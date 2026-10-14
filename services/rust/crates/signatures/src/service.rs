use crate::{cms, pdf, permissions, *};
use lopdf::{dictionary, Dictionary, Document, IncrementalDocument, Object, Stream, StringFormat};
use std::sync::Arc;

/// Contains the frozen revision and prepared visible appearance. Construction
/// requires all locally knowable validation. It cannot be cloned or fabricated.
pub struct PreparedSigning {
    bytes: Vec<u8>,
    gap_start: usize,
    gap_end: usize,
    source_sha256: String,
    appearance_sha256: String,
    certificate_fingerprint: String,
    previous_signature_count: usize,
}
impl PreparedSigning {
    pub fn source_sha256(&self) -> &str {
        &self.source_sha256
    }
    pub fn appearance_sha256(&self) -> &str {
        &self.appearance_sha256
    }
    pub fn certificate_fingerprint(&self) -> &str {
        &self.certificate_fingerprint
    }
}
#[derive(Clone)]
pub struct SignatureService {
    identity: Arc<DocumentIdentity>,
    timestamp_client: Arc<dyn TimestampClient>,
    trust: TrustConfig,
    reserved_size: usize,
    environment_backed: bool,
}
impl SignatureService {
    pub fn from_env() -> Result<Self> {
        let identity = load_environment_identity()?;
        let trust = TrustConfig::from_env()?;
        let url = std::env::var("PDF_PADES_TSA_URL").unwrap_or_default();
        let timeout = std::env::var("PDF_PADES_TSA_TIMEOUT_SECONDS")
            .unwrap_or_else(|_| "10".into())
            .parse::<u64>()
            .map_err(|_| {
                SignatureError::Configuration(
                    "RFC 3161 timeout must be between 1 and 60 seconds".into(),
                )
            })?;
        let transport = Arc::new(HttpTimestampTransport::new(&url, timeout)?);
        let policy = std::env::var("PDF_PADES_TSA_POLICY_OID")
            .ok()
            .filter(|v| !v.trim().is_empty());
        let tsa = Arc::new(Rfc3161Client::new(transport, trust.clone(), policy)?);
        let size = std::env::var("SIGNATURE_RESERVED_SIZE")
            .unwrap_or_else(|_| "32768".into())
            .parse::<usize>()
            .map_err(|_| {
                SignatureError::Configuration(
                    "SIGNATURE_RESERVED_SIZE must be between 16 KiB and 1 MiB".into(),
                )
            })?;
        let mut service = Self::new(identity, tsa, trust).with_reserved_size(size)?;
        service.environment_backed = true;
        Ok(service)
    }

    pub fn new(
        identity: DocumentIdentity,
        timestamp_client: Arc<dyn TimestampClient>,
        trust: TrustConfig,
    ) -> Self {
        Self {
            identity: Arc::new(identity),
            timestamp_client,
            trust,
            reserved_size: 32768,
            environment_backed: false,
        }
    }
    pub fn with_reserved_size(mut self, size: usize) -> Result<Self> {
        if !(16384..=1024 * 1024).contains(&size) {
            return Err(SignatureError::Configuration(
                "SIGNATURE_RESERVED_SIZE must be between 16 KiB and 1 MiB".into(),
            ));
        }
        self.reserved_size = size;
        Ok(self)
    }
    pub fn require_ready_signing_configuration(&self, expected: Option<&str>) -> Result<String> {
        let identity = self.active_identity()?;
        self.validate_identity(&identity, expected)
    }
    fn active_identity(&self) -> Result<DocumentIdentity> {
        if self.environment_backed {
            load_environment_identity()
        } else {
            Ok(self.identity.as_ref().clone())
        }
    }
    fn validate_identity(
        &self,
        identity: &DocumentIdentity,
        expected: Option<&str>,
    ) -> Result<String> {
        self.timestamp_client.require_ready_configuration()?;
        let actual = identity.validate(&self.trust)?;
        if expected.is_some_and(|e| {
            actual.len() != e.len()
                || !openssl::memcmp::eq(actual.as_bytes(), e.to_ascii_lowercase().as_bytes())
        }) {
            return Err(pdf::invalid(
                "The active document-signing certificate does not match the frozen operation",
            ));
        }
        Ok(actual)
    }
    pub fn preflight(
        &self,
        pdf_bytes: &[u8],
        appearance: &[u8],
        command: &SignCommand,
        expected: Option<&str>,
    ) -> Result<PreparedSigning> {
        if appearance.is_empty() {
            return Err(pdf::invalid("A signature appearance is required"));
        }
        if command.signer_name.trim().is_empty() {
            return Err(pdf::invalid("A signer display name is required"));
        }
        let mut doc = Document::load_mem(pdf_bytes)?;
        let field = pdf::target(&doc, command)?;
        if field.widgets.len() != 1 {
            return Err(pdf::invalid(
                "A signature field supports exactly one widget",
            ));
        }
        // Decode before entering the durable private-key stage. Java currently
        // discovers this error later; moving the check earlier changes no output.
        let image = crate::appearance::decode(appearance)?;
        let actual = self.require_ready_signing_configuration(expected)?;
        let previous_signature_count = pdf::signatures(&doc)?.len();
        let fid = field.object.as_reference().ok();
        let widget = field.widgets[0].clone();
        let wid = widget
            .as_reference()
            .map_err(|_| pdf::invalid("Signature widget is not attached to a document page"))?;
        let widget_dict = pdf::dict(&doc, &widget)?.clone();
        let rect = widget_dict.get(b"Rect")?.as_array()?;
        if rect.len() != 4 {
            return Err(pdf::invalid("Signature widget rectangle is required"));
        }
        let width = f64::from(pdf::number(&rect[2])? as f32 - pdf::number(&rect[0])? as f32);
        let height = f64::from(pdf::number(&rect[3])? as f32 - pdf::number(&rect[1])? as f32);
        let root = pdf::root_id(&doc)?;
        let acro = pdf::form(&doc)?
            .ok_or_else(|| pdf::invalid("The target signature field does not exist"))?
            .clone();
        let catalog = doc.catalog()?.clone();
        doc.reference_table.cross_reference_type = lopdf::xref::XrefType::CrossReferenceTable;
        let mut inc = IncrementalDocument::create_from(pdf_bytes.to_vec(), doc); // A table adds no standalone cross-reference object to the document graph.
                                                                                 // The Java permission oracle rejects a new unreferenced XRef stream
                                                                                 // object, even when the content signature is otherwise valid.
        inc.new_document.reference_table.cross_reference_type =
            lopdf::xref::XrefType::CrossReferenceTable;
        for key in [
            b"Type".as_slice(),
            b"W",
            b"Index",
            b"Length",
            b"Filter",
            b"DecodeParms",
        ] {
            inc.new_document.trailer.remove(key);
        }
        let new = &mut inc.new_document;
        let mut rgb = Vec::with_capacity(image.width() as usize * image.height() as usize * 3);
        let mut alpha = Vec::with_capacity(image.width() as usize * image.height() as usize);
        for pixel in image.pixels() {
            rgb.extend_from_slice(&pixel.0[..3]);
            alpha.push(pixel.0[3]);
        }
        let mut image_dict = dictionary! {"Type"=>"XObject","Subtype"=>"Image","Width"=>image.width()as i64,"Height"=>image.height()as i64,"ColorSpace"=>"DeviceRGB","BitsPerComponent"=>8};
        if alpha.iter().any(|a| *a != 255) {
            let mut mask = Stream::new(
                dictionary! {"Type"=>"XObject","Subtype"=>"Image","Width"=>image.width()as i64,"Height"=>image.height()as i64,"ColorSpace"=>"DeviceGray","BitsPerComponent"=>8},
                alpha,
            );
            mask.compress()?;
            image_dict.set("SMask", new.add_object(mask));
        }
        let mut image_stream = Stream::new(image_dict, rgb);
        image_stream.compress()?;
        let image_id = new.add_object(image_stream);
        // PDFBox's visible designer converts formatter width/height with f2i.
        // Preserve its integer AP coordinate space, including zero when a
        // physical dimension is below one point. The frozen widget Rect stays
        // untouched and maps this appearance into the original field geometry.
        let appearance_width = width as i32;
        let appearance_height = height as i32;
        let appearance_id=new.add_object(Stream::new(dictionary!{"Type"=>"XObject","Subtype"=>"Form","FormType"=>1,"BBox"=>vec![0.into(),0.into(),Object::Integer(appearance_width as i64),Object::Integer(appearance_height as i64)],"Resources"=>dictionary!{"XObject"=>dictionary!{"Im0"=>image_id}}},format!("q {appearance_width} 0 0 {appearance_height} 0 0 cm /Im0 Do Q\n").into_bytes()));
        let mut widget_dict = widget_dict;
        widget_dict.set("AP", dictionary! {"N"=>appearance_id});
        new.set_object(wid, widget_dict);
        let sig_id = new.new_object_id();
        let mut refs = Vec::new();
        if command
            .signature_role
            .eq_ignore_ascii_case("certification_p2")
        {
            refs.push(Object::Dictionary(dictionary!{"Type"=>"SigRef","TransformMethod"=>"DocMDP","TransformParams"=>dictionary!{"Type"=>"TransformParams","V"=>"1.2","P"=>2},"Data"=>root}));
            let mut catalog = catalog;
            let mut perms = inc
                .get_prev_documents()
                .catalog()?
                .get(b"Perms")
                .ok()
                .map(|o| pdf::dict(inc.get_prev_documents(), o).cloned())
                .transpose()?
                .unwrap_or_default();
            perms.set("DocMDP", sig_id);
            catalog.set("Perms", perms);
            inc.new_document.set_object(root, catalog);
        }
        refs.push(Object::Dictionary(dictionary!{"Type"=>"SigRef","TransformMethod"=>"FieldMDP","TransformParams"=>dictionary!{"Type"=>"TransformParams","V"=>"1.2","Action"=>"Include","Fields"=>vec![pdf::text_string(&command.field_name)]},"Data"=>acro}));
        let mut signature = dictionary! {"Type"=>"Sig","Filter"=>"Adobe.PPKLite","SubFilter"=>"ETSI.CAdES.detached","Name"=>pdf::text_string(&command.signer_name),"M"=>Object::string_literal(chrono::Utc::now().format("D:%Y%m%d%H%M%SZ").to_string()),"Reference"=>refs,"ByteRange"=>vec![Object::Integer(0),Object::Integer(999999999999999999),Object::Integer(999999999999999999),Object::Integer(999999999999999999)],"Contents"=>Object::String(vec![0;self.reserved_size],StringFormat::Hexadecimal)};
        for (key, value) in [
            ("Reason", &command.reason),
            ("Location", &command.location),
            ("ContactInfo", &command.contact),
        ] {
            if let Some(v) = value {
                signature.set(key, pdf::text_string(v));
            }
        }
        inc.new_document.set_object(sig_id, signature);
        let mut field_dict = if fid == Some(wid) {
            inc.new_document.get_dictionary(wid)?.clone()
        } else {
            field.dictionary.clone()
        };
        field_dict.set("V", sig_id);
        pdf::replace_field(&mut inc, &field, field_dict)?;
        let mut bytes = Vec::new();
        inc.save_to(&mut bytes)?;
        if !bytes.starts_with(pdf_bytes) {
            return Err(SignatureError::Integrity(
                "Incremental signing changed the immutable input prefix".into(),
            ));
        }
        let suffix = &bytes[pdf_bytes.len()..];
        let placeholder = b"999999999999999999";
        let positions: Vec<_> = suffix
            .windows(placeholder.len())
            .enumerate()
            .filter(|(_, v)| *v == placeholder)
            .map(|(p, _)| p + pdf_bytes.len())
            .collect();
        if positions.len() != 3 {
            return Err(SignatureError::Integrity(
                "Signature ByteRange placeholder is ambiguous".into(),
            ));
        }
        let zeros = vec![b'0'; self.reserved_size * 2];
        let start = bytes[pdf_bytes.len()..]
            .windows(zeros.len())
            .position(|w| w == zeros)
            .map(|p| p + pdf_bytes.len())
            .ok_or_else(|| {
                SignatureError::Integrity("Signature Contents reservation is missing".into())
            })?;
        let gap_start = start.checked_sub(1).ok_or_else(|| {
            SignatureError::Integrity("Signature Contents reservation is invalid".into())
        })?;
        let gap_end = start + zeros.len() + 1;
        if bytes[gap_start] != b'<' || bytes[gap_end - 1] != b'>' {
            return Err(SignatureError::Integrity(
                "Signature Contents reservation is invalid".into(),
            ));
        }
        let ranges = [gap_start, gap_end, bytes.len() - gap_end];
        for (pos, n) in positions.into_iter().zip(ranges) {
            let value = format!("{n:<18}");
            if value.len() != 18 {
                return Err(SignatureError::Integrity(
                    "PDF ByteRange exceeds supported size".into(),
                ));
            }
            bytes[pos..pos + 18].copy_from_slice(value.as_bytes());
        }
        Ok(PreparedSigning {
            bytes,
            gap_start,
            gap_end,
            source_sha256: sha256_hex(pdf_bytes),
            appearance_sha256: sha256_hex(appearance),
            certificate_fingerprint: actual,
            previous_signature_count,
        })
    }
    /// Invoke exactly once, only after a durable execution claim marks the key
    /// stage started. This method never retries the key or timestamp operation.
    pub fn sign_preflighted(&self, mut prepared: PreparedSigning) -> Result<Vec<u8>> {
        // Match Java's authoritative reload immediately before the key stage:
        // a keystore rotation cannot silently replace the frozen certificate.
        let active_identity = self.active_identity()?;
        self.validate_identity(&active_identity, Some(&prepared.certificate_fingerprint))?;
        let mut content = prepared.bytes[..prepared.gap_start].to_vec();
        content.extend_from_slice(&prepared.bytes[prepared.gap_end..]);
        let signature = cms::sign_document(
            &content,
            &active_identity,
            self.timestamp_client.as_ref(),
            &self.trust,
        )?;
        let hex = hex::encode(signature);
        if hex.len() > prepared.gap_end - prepared.gap_start - 2 {
            return Err(SignatureError::Integrity(
                "CMS signature exceeds the reserved Contents size".into(),
            ));
        }
        prepared.bytes[prepared.gap_start + 1..prepared.gap_start + 1 + hex.len()]
            .copy_from_slice(hex.as_bytes());
        let inspection = inspect(&prepared.bytes)?;
        if inspection.signature_count != prepared.previous_signature_count + 1
            || inspection.doc_mdp_permission != Some(2)
        {
            return Err(SignatureError::Integrity(
                "Signed PDF failed structural round-trip verification".into(),
            ));
        }
        Ok(prepared.bytes)
    }
    pub fn verify(&self, pdf: &[u8]) -> VerificationReport {
        verify(pdf, &self.trust)
    }
}
pub fn verify(bytes: &[u8], trust: &TrustConfig) -> VerificationReport {
    let parsed = (|| -> Result<_> {
        let doc = Document::load_mem(bytes)?;
        let permission = pdf::doc_mdp(&doc)?;
        let signatures = pdf::signatures(&doc)?;
        Ok((doc, permission, signatures))
    })();
    let Ok((_, permission, signatures)) = parsed else {
        return VerificationReport {
            document_current_state: "invalid".into(),
            doc_mdp_permission: None,
            signatures: Vec::new(),
            error: Some(format!(
                "PDF_PARSE_OR_STRUCTURE_INVALID: {}",
                parsed.err().unwrap()
            )),
        };
    };
    let mut reports = Vec::new();
    for (index, (_, s)) in signatures.iter().enumerate() {
        let result = (|| -> Result<(String, String)> {
            let range = byte_range(s, bytes.len())?;
            let gap = &bytes[range[1]..range[2]];
            if gap.len() < 4 || gap[0] != b'<' || gap[gap.len() - 1] != b'>' {
                return Err(pdf::invalid(
                    "ByteRange gap is not the signature Contents hex string",
                ));
            }
            let padded = hex::decode(&gap[1..gap.len() - 1]).map_err(|_| {
                pdf::invalid("Signature Contents is not canonical hexadecimal data")
            })?;
            let cms = cms::cms_unpadded(&padded)?;
            let mut content = bytes[..range[1]].to_vec();
            content.extend_from_slice(&bytes[range[2]..range[2] + range[3]]);
            cms::verify_document(cms, &content, trust)
        })();
        let signer_name = s.get(b"Name").ok().and_then(|o| pdf::string(o).ok());
        let sub_filter = s.get(b"SubFilter").ok().and_then(pdf::name);
        let report = match result {
            Ok((certificate_trust, timestamp_trust)) => SignatureVerification {
                index,
                signer_name,
                sub_filter,
                cms_integrity: "valid".into(),
                certificate_trust,
                signed_revision_integrity: "valid".into(),
                later_revision_permission: "valid".into(),
                timestamp_trust,
                document_current_state: "valid".into(),
                error: None,
            },
            Err(e) => SignatureVerification {
                index,
                signer_name,
                sub_filter,
                cms_integrity: "invalid".into(),
                certificate_trust: "indeterminate".into(),
                signed_revision_integrity: "invalid".into(),
                later_revision_permission: "invalid".into(),
                timestamp_trust: "invalid".into(),
                document_current_state: "invalid".into(),
                error: Some(e.to_string()),
            },
        };
        reports.push(report);
    }
    let permission_error = permissions::validate(bytes).err().map(|e| e.to_string());
    if permission_error.is_some() {
        for item in &mut reports {
            item.later_revision_permission = "invalid".into();
            item.document_current_state = "invalid".into();
        }
    }
    let invalid = reports.iter().any(|r| {
        r.cms_integrity != "valid"
            || r.signed_revision_integrity != "valid"
            || r.certificate_trust == "invalid"
            || r.timestamp_trust == "invalid"
    }) || (!reports.is_empty()
        && (permission != Some(2) || permission_error.is_some()));
    let indeterminate = reports
        .iter()
        .any(|r| r.certificate_trust == "indeterminate" || r.timestamp_trust == "indeterminate");
    VerificationReport {
        document_current_state: if invalid {
            "invalid"
        } else if indeterminate {
            "indeterminate"
        } else {
            "valid"
        }
        .into(),
        doc_mdp_permission: permission,
        signatures: reports,
        error: permission_error,
    }
}
pub(crate) fn byte_range(s: &Dictionary, len: usize) -> Result<[usize; 4]> {
    let a = s.get(b"ByteRange")?.as_array()?;
    if a.len() != 4 {
        return Err(pdf::invalid("Invalid PDF signature ByteRange"));
    }
    let mut range = [0; 4];
    for (i, o) in a.iter().enumerate() {
        range[i] = usize::try_from(o.as_i64()?)
            .map_err(|_| pdf::invalid("Invalid PDF signature ByteRange"))?;
    }
    if range[0] != 0
        || range[2] < range[1]
        || range[2].checked_add(range[3]).is_none_or(|n| n > len)
    {
        return Err(pdf::invalid("Invalid PDF signature ByteRange"));
    }
    Ok(range)
}

fn load_environment_identity() -> Result<DocumentIdentity> {
    let password = std::env::var("DEFAULT_PFX_PASS")
        .ok()
        .filter(|v| !v.trim().is_empty())
        .ok_or_else(|| {
            SignatureError::Configuration(
                "DEFAULT_PFX_PASS is required; default keystore passwords are forbidden".into(),
            )
        })?;
    let path = std::env::var("DEFAULT_PFX_PATH")
        .ok()
        .filter(|v| !v.trim().is_empty())
        .unwrap_or_else(|| {
            if std::path::Path::new("/keys/signer.pfx").exists() {
                "/keys/signer.pfx".into()
            } else if std::path::Path::new("keys/signer.pfx").exists() {
                "keys/signer.pfx".into()
            } else {
                "/keys/signer.pfx".into()
            }
        });
    let der = std::fs::read(path).map_err(|e| {
        SignatureError::Configuration(format!("Cannot load document-signing keystore: {e}"))
    })?;
    DocumentIdentity::from_pkcs12(&der, &password)
}
