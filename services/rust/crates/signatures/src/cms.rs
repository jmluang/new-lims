//! SHA-256/RSA CMS with ESSCertIDv2 and RFC 3161 signature timestamps.
//! OpenSSL performs every public/private-key operation. The small ASN.1 helpers
//! assemble policy attributes and inspect their bindings; they are not crypto.
use crate::{sha256_hex, Result, SignatureError};
use openssl::{
    asn1::Asn1Time,
    cms::{CMSOptions, CmsContentInfo},
    hash::MessageDigest,
    pkey::{PKey, Private},
    sign::Signer,
    x509::X509,
};
use sha2::{Digest, Sha256};
use std::collections::HashSet;
use std::sync::Arc;
const DATA: &str = "1.2.840.113549.1.7.1";
const SIGNED_DATA: &str = "1.2.840.113549.1.7.2";
const SHA256: &str = "2.16.840.1.101.3.4.2.1";
const RSA: &str = "1.2.840.113549.1.1.1";
const CONTENT_TYPE: &str = "1.2.840.113549.1.9.3";
const MESSAGE_DIGEST: &str = "1.2.840.113549.1.9.4";
const SIGNING_TIME: &str = "1.2.840.113549.1.9.5";
const ESS: &str = "1.2.840.113549.1.9.16.2.47";
const ESS_V1: &str = "1.2.840.113549.1.9.16.2.12";
const TIMESTAMP: &str = "1.2.840.113549.1.9.16.2.14";
const TSTINFO: &str = "1.2.840.113549.1.9.16.1.4";
fn crypto(s: &str) -> SignatureError {
    SignatureError::Crypto(s.into())
}
fn config(s: &str) -> SignatureError {
    SignatureError::Configuration(s.into())
}
#[derive(Clone, Debug, Default)]
pub struct TrustConfig {
    pub document_roots: HashSet<String>,
    pub tsa_certificates: HashSet<String>,
}
impl TrustConfig {
    pub fn from_env() -> Result<Self> {
        Self::from_csv(
            &std::env::var("PDF_PADES_SIGNER_TRUSTED_ROOT_SHA256").unwrap_or_default(),
            &std::env::var("PDF_PADES_TSA_TRUSTED_CERT_SHA256").unwrap_or_default(),
        )
    }

    pub fn from_csv(roots: &str, tsa: &str) -> Result<Self> {
        fn parse(raw: &str) -> Result<HashSet<String>> {
            raw.split(',')
                .map(|v| v.replace(':', "").trim().to_ascii_lowercase())
                .filter(|v| !v.is_empty())
                .map(|v| {
                    if v.len() == 64 && v.bytes().all(|b| b.is_ascii_hexdigit()) {
                        Ok(v)
                    } else {
                        Err(config("Trust certificate fingerprints must be SHA-256 hex"))
                    }
                })
                .collect()
        }
        Ok(Self {
            document_roots: parse(roots)?,
            tsa_certificates: parse(tsa)?,
        })
    }
}
#[derive(Clone)]
pub struct DocumentIdentity {
    pub(crate) key: PKey<Private>,
    pub(crate) chain: Vec<X509>,
}
impl DocumentIdentity {
    pub fn from_pem(key: &[u8], certificates: &[u8]) -> Result<Self> {
        Ok(Self {
            key: PKey::private_key_from_pem(key)?,
            chain: X509::stack_from_pem(certificates)?,
        })
    }
    pub fn from_pkcs12(der: &[u8], password: &str) -> Result<Self> {
        let parsed = lims_crypto::parse_pkcs12(der, password).map_err(|error| match error {
            lims_crypto::Pkcs12Error::InvalidEncoding(error)
            | lims_crypto::Pkcs12Error::InvalidPasswordOrData(error) => SignatureError::from(error),
            lims_crypto::Pkcs12Error::LegacyProviderUnavailable(_) => {
                config("OpenSSL legacy provider unavailable for configured PKCS12 envelope")
            }
        })?;
        let key = parsed
            .pkey
            .ok_or_else(|| config("PKCS12 private key is missing"))?;
        let mut chain = vec![parsed
            .cert
            .ok_or_else(|| config("PKCS12 leaf certificate is missing"))?];
        if let Some(ca) = parsed.ca {
            chain.extend(ca);
        }
        Ok(Self { key, chain })
    }
    pub fn certificate_fingerprint(&self) -> Result<String> {
        Ok(sha256_hex(
            &self
                .chain
                .first()
                .ok_or_else(|| config("Document-signing certificate is missing"))?
                .to_der()?,
        ))
    }
    pub fn validate(&self, trust: &TrustConfig) -> Result<String> {
        if trust.document_roots.is_empty() {
            return Err(config(
                "A versioned document-signing trust root allowlist is required",
            ));
        }
        if self.chain.len() < 2 {
            return Err(config(
                "The document-signing identity requires a leaf and trust chain",
            ));
        }
        let leaf = &self.chain[0];
        validate_leaf(leaf, true)?;
        if !self.key.public_eq(leaf.public_key()?.as_ref()) {
            return Err(config(
                "The document-signing private key does not match its certificate",
            ));
        }
        let now = Asn1Time::days_from_now(0)?;
        for (i, c) in self.chain.iter().enumerate() {
            if c.not_before() > now || c.not_after() < now {
                return Err(config(
                    "The document-signing chain certificate is outside its validity period",
                ));
            }
            if let Some(parent) = self.chain.get(i + 1) {
                if !c.verify(parent.public_key()?.as_ref())? {
                    return Err(config("The document-signing chain signature is invalid"));
                }
            }
        }
        let root = self.chain.last().unwrap();
        if !is_ca(root)? || !root.verify(root.public_key()?.as_ref())? {
            return Err(config(
                "The document-signing trust root must be a self-signed CA certificate",
            ));
        }
        if !trust.document_roots.contains(&sha256_hex(&root.to_der()?)) {
            return Err(config(
                "The document-signing chain does not terminate at an allowed trust root",
            ));
        }
        self.certificate_fingerprint()
    }
}
fn is_ca(c: &X509) -> Result<bool> {
    let der = c.to_der()?;
    let (_, cert) =
        x509_parser::parse_x509_certificate(&der).map_err(|e| crypto(&e.to_string()))?;
    Ok(cert
        .basic_constraints()
        .map_err(|e| crypto(&e.to_string()))?
        .map(|v| v.value.ca)
        .unwrap_or(false))
}
fn validate_leaf(c: &X509, require_rsa: bool) -> Result<()> {
    let der = c.to_der()?;
    let (_, cert) =
        x509_parser::parse_x509_certificate(&der).map_err(|e| crypto(&e.to_string()))?;
    if cert
        .basic_constraints()
        .map_err(|e| crypto(&e.to_string()))?
        .map(|v| v.value.ca)
        .unwrap_or(false)
    {
        return Err(config(
            "The document-signing leaf certificate must have CA=false",
        ));
    }
    let ku = cert
        .key_usage()
        .map_err(|e| crypto(&e.to_string()))?
        .ok_or_else(|| {
            config("The document-signing leaf requires digitalSignature or nonRepudiation KeyUsage")
        })?;
    if !ku.value.digital_signature() && !ku.value.non_repudiation() {
        return Err(config(
            "The document-signing leaf requires digitalSignature or nonRepudiation KeyUsage",
        ));
    }
    let eku = cert
        .extended_key_usage()
        .map_err(|e| crypto(&e.to_string()))?
        .ok_or_else(|| config("The document-signing leaf requires the documentSigning EKU"))?;
    if !eku
        .value
        .other
        .iter()
        .any(|oid| oid.to_id_string() == "1.3.6.1.5.5.7.3.36")
    {
        return Err(config(
            "The document-signing leaf requires the documentSigning EKU",
        ));
    }
    if require_rsa {
        let rsa = c.public_key()?.rsa().map_err(|_| {
            config("The document-signing leaf must use RSA with at least 2048 bits")
        })?;
        if rsa.n().num_bits() < 2048 {
            return Err(config(
                "The document-signing leaf must use RSA with at least 2048 bits",
            ));
        }
    }
    Ok(())
}
pub trait TimestampClient: Send + Sync {
    fn require_ready_configuration(&self) -> Result<()>;
    /// Return a complete RFC 3161 ContentInfo token, after validating the response
    /// against the request (including nonce and any requested policy).
    fn timestamp(&self, signature_value: &[u8]) -> Result<Vec<u8>>;
}
pub trait TimestampTransport: Send + Sync {
    fn post_timestamp_query(&self, request: &[u8]) -> Result<Vec<u8>>;
}
pub struct Rfc3161Client {
    transport: Arc<dyn TimestampTransport>,
    trust: TrustConfig,
    policy: Option<String>,
}
impl Rfc3161Client {
    pub fn new(
        transport: Arc<dyn TimestampTransport>,
        trust: TrustConfig,
        policy: Option<String>,
    ) -> Result<Self> {
        if let Some(p) = &policy {
            oid(p)?;
        }
        Ok(Self {
            transport,
            trust,
            policy,
        })
    }
}
impl TimestampClient for Rfc3161Client {
    fn require_ready_configuration(&self) -> Result<()> {
        if self.trust.tsa_certificates.is_empty() {
            return Err(config(
                "PAdES-B-T requires a TSA certificate fingerprint allowlist",
            ));
        }
        Ok(())
    }
    fn timestamp(&self, signature_value: &[u8]) -> Result<Vec<u8>> {
        self.require_ready_configuration()?;
        let mut nonce = [0u8; 20];
        openssl::rand::rand_bytes(&mut nonce)?;
        if nonce.iter().all(|v| *v == 0) {
            nonce[19] = 1;
        }
        let mut request = vec![
            integer(&[1]),
            sequence(&[algorithm(SHA256)?, tlv(4, &Sha256::digest(signature_value))]),
        ];
        if let Some(policy) = &self.policy {
            request.push(oid(policy)?);
        }
        request.push(integer(&nonce));
        request.push(tlv(1, &[255]));
        let response = self.transport.post_timestamp_query(&sequence(&request))?;
        let node = Node::one(&response)?;
        let children = node.children()?;
        if children.len() != 2 {
            return Err(SignatureError::Timestamp(
                "RFC 3161 response did not contain a timestamp token".into(),
            ));
        }
        let status = children[0].children()?;
        if !matches!(status.first().map(|n| n.value), Some([0]) | Some([1])) {
            return Err(SignatureError::Timestamp(
                "RFC 3161 service did not grant the timestamp request".into(),
            ));
        }
        let token = children[1].raw.to_vec();
        let info = verify_timestamp(&token, signature_value, &self.trust)?;
        if info.nonce.as_deref() != Some(integer_bytes(&nonce)) {
            return Err(SignatureError::Timestamp(
                "RFC 3161 response nonce does not match request".into(),
            ));
        }
        if self.policy.as_deref().is_some_and(|p| p != info.policy) {
            return Err(SignatureError::Timestamp(
                "RFC 3161 response policy does not match request".into(),
            ));
        }
        if info.trust != "valid" {
            return Err(SignatureError::Timestamp(
                "RFC 3161 token signer is not in the configured trust allowlist".into(),
            ));
        }
        Ok(token)
    }
}
pub(crate) fn signed_attributes(
    content: &[u8],
    certificate: &X509,
    content_type: &str,
) -> Result<Vec<u8>> {
    let mut attributes = [
        attribute(CONTENT_TYPE, oid(content_type)?)?,
        attribute(MESSAGE_DIGEST, tlv(4, &Sha256::digest(content)))?,
        attribute(
            ESS,
            sequence(&[sequence(&[sequence(&[tlv(
                4,
                &Sha256::digest(certificate.to_der()?),
            )])])]),
        )?,
    ];
    attributes.sort();
    Ok(tlv(0x31, &attributes.concat()))
}
pub(crate) fn sign_attributes(attributes: &[u8], identity: &DocumentIdentity) -> Result<Vec<u8>> {
    let mut signer = Signer::new(MessageDigest::sha256(), &identity.key)?;
    signer.update(attributes)?;
    Ok(signer.sign_to_vec()?)
}
pub(crate) fn assemble(
    identity: &DocumentIdentity,
    attrs: &[u8],
    signature: &[u8],
    timestamp: Option<&[u8]>,
    content_type: &str,
    content: Option<&[u8]>,
) -> Result<Vec<u8>> {
    let leaf = &identity.chain[0];
    let mut serial = leaf.serial_number().to_bn()?.to_vec();
    if serial.first().is_some_and(|b| b & 128 != 0) {
        serial.insert(0, 0);
    }
    let sid = sequence(&[leaf.issuer_name().to_der()?, tlv(2, &serial)]);
    let mut sign_fields = vec![
        integer(&[1]),
        sid,
        algorithm(SHA256)?,
        tlv(0xa0, Node::one(attrs)?.value),
        algorithm(RSA)?,
        tlv(4, signature),
    ];
    if let Some(ts) = timestamp {
        sign_fields.push(tlv(0xa1, &attribute(TIMESTAMP, ts.to_vec())?));
    }
    let mut encap = vec![oid(content_type)?];
    if let Some(content) = content {
        encap.push(tlv(0xa0, &tlv(4, content)));
    }
    let mut certs = Vec::new();
    for c in &identity.chain {
        certs.push(c.to_der()?);
    }
    certs.sort();
    let signed = sequence(&[
        integer(&[if content_type == DATA { 1 } else { 3 }]),
        tlv(0x31, &algorithm(SHA256)?),
        sequence(&encap),
        tlv(0xa0, &certs.concat()),
        tlv(0x31, &sequence(&sign_fields)),
    ]);
    Ok(sequence(&[oid(SIGNED_DATA)?, tlv(0xa0, &signed)]))
}
pub(crate) fn sign_document(
    content: &[u8],
    identity: &DocumentIdentity,
    client: &dyn TimestampClient,
    trust: &TrustConfig,
) -> Result<Vec<u8>> {
    client.require_ready_configuration()?;
    let attrs = signed_attributes(content, &identity.chain[0], DATA)?;
    let signature = sign_attributes(&attrs, identity)?;
    let ts = client.timestamp(&signature)?;
    let info = verify_timestamp(&ts, &signature, trust)?;
    if info.trust != "valid" {
        return Err(SignatureError::Timestamp(
            "RFC 3161 token signer is not in the configured trust allowlist".into(),
        ));
    }
    assemble(identity, &attrs, &signature, Some(&ts), DATA, None)
}
struct ParsedCms {
    certificates: Vec<X509>,
    signer: X509,
    attrs: Vec<u8>,
    unsigned_attrs: Vec<u8>,
    signature: Vec<u8>,
    content_type: String,
    digest_algorithm: String,
    content: Option<Vec<u8>>,
}
fn parse_cms(bytes: &[u8]) -> Result<ParsedCms> {
    let openssl = CmsContentInfo::from_der(bytes)?;
    let normalized = openssl.to_der()?;
    let root = Node::one(&normalized)?.children()?;
    if root.len() != 2 || root[0].oid()? != SIGNED_DATA {
        return Err(crypto("CMS ContentInfo must contain SignedData"));
    }
    let explicit = root[1].children()?;
    let sd = explicit
        .first()
        .ok_or_else(|| crypto("CMS SignedData is missing"))?
        .children()?;
    if sd.len() < 5 {
        return Err(crypto("CMS SignedData is incomplete"));
    }
    let encap = sd[2].children()?;
    let content_type = encap
        .first()
        .ok_or_else(|| crypto("CMS content type is missing"))?
        .oid()?;
    let content = encap
        .get(1)
        .map(|n| {
            n.children().and_then(|v| {
                v.first()
                    .map(|n| n.value.to_vec())
                    .ok_or_else(|| crypto("CMS encapsulated content is missing"))
            })
        })
        .transpose()?;
    let cert_node = sd
        .iter()
        .find(|n| n.tag == 0xa0)
        .ok_or_else(|| crypto("CMS signer certificates are missing"))?;
    let mut certificates = Vec::new();
    for n in cert_node.children()? {
        certificates.push(X509::from_der(n.raw)?);
    }
    let signers = sd.last().unwrap().children()?;
    if signers.len() != 1 {
        return Err(crypto("CMS must contain exactly one SignerInfo"));
    }
    let si = signers[0].children()?;
    if si.len() < 6 {
        return Err(crypto("CMS signed attributes are missing"));
    }
    let sid = si[1].children()?;
    if sid.len() != 2 {
        return Err(crypto(
            "CMS issuer and serial signer identifier is required",
        ));
    }
    let serial = integer_bytes(sid[1].value);
    let matches: Vec<_> = certificates
        .iter()
        .filter(|c| {
            c.issuer_name().to_der().ok().as_deref() == Some(sid[0].raw)
                && c.serial_number()
                    .to_bn()
                    .ok()
                    .map(|bn| integer_bytes(&bn.to_vec()).to_vec())
                    .as_deref()
                    == Some(serial)
        })
        .collect();
    if matches.len() != 1 {
        return Err(crypto(
            "CMS must contain exactly one matching signer certificate",
        ));
    }
    if si[3].tag != 0xa0 {
        return Err(crypto("CMS signed attributes are missing"));
    }
    let signer = matches[0].clone();
    let digest_algorithm = si[2]
        .children()?
        .first()
        .ok_or_else(|| crypto("CMS digest algorithm is missing"))?
        .oid()?;
    let attrs = tlv(0x31, si[3].value);
    let signature = si[5].value.to_vec();
    let unsigned_attrs = si
        .get(6)
        .filter(|n| n.tag == 0xa1)
        .map(|n| n.value.to_vec())
        .unwrap_or_default();
    // OpenSSL verifies the CMS signatures and message digest in the caller,
    // with certificate path policy evaluated separately, never bypassed.
    Ok(ParsedCms {
        certificates,
        signer,
        attrs,
        unsigned_attrs,
        signature,
        content_type,
        digest_algorithm,
        content,
    })
}
fn attr_values<'a>(attrs: &'a [u8], wanted: &str) -> Result<Vec<Node<'a>>> {
    let mut found = Vec::new();
    for attr in nodes(attrs)? {
        let a = attr.children()?;
        if a.len() != 2 {
            return Err(crypto("CMS attribute is incomplete"));
        }
        if a[0].oid()? == wanted {
            found.extend(a[1].children()?);
        }
    }
    Ok(found)
}
fn validate_attributes(cms: &ParsedCms, content: &[u8], require_no_time: bool) -> Result<()> {
    let attrs = Node::one(&cms.attrs)?;
    let ct = attr_values(attrs.value, CONTENT_TYPE)?;
    let md = attr_values(attrs.value, MESSAGE_DIGEST)?;
    let ess = attr_values(attrs.value, ESS)?;
    let ess_v1 = if require_no_time {
        Vec::new()
    } else {
        attr_values(attrs.value, ESS_V1)?
    };
    if ct.len() != 1
        || md.len() != 1
        || ess.len() + ess_v1.len() != 1
        || ct[0].oid()? != cms.content_type
        || md[0].value
            != openssl::hash::hash(digest_algorithm(&cms.digest_algorithm)?, content)?.as_ref()
        || (require_no_time && !attr_values(attrs.value, SIGNING_TIME)?.is_empty())
    {
        return Err(crypto(
            "CMS signed attributes do not match the PAdES-B-T policy",
        ));
    }
    let legacy = ess.is_empty();
    let certs = if legacy { ess_v1[0] } else { ess[0] }.children()?;
    let cert_ids = certs
        .first()
        .ok_or_else(|| crypto("ESSCertIDv2 is missing"))?
        .children()?;
    let first = cert_ids
        .first()
        .ok_or_else(|| crypto("ESSCertIDv2 is missing"))?
        .children()?;
    let mut algorithm = if legacy {
        MessageDigest::sha1()
    } else {
        MessageDigest::sha256()
    };
    let hash = if !legacy && first.first().is_some_and(|n| n.tag == 0x30) {
        let alg = first[0].children()?;
        algorithm = digest_algorithm(
            &alg.first()
                .ok_or_else(|| crypto("ESS certificate digest algorithm is missing"))?
                .oid()?,
        )?;
        first.get(1)
    } else {
        first.first()
    }
    .ok_or_else(|| crypto("ESSCertIDv2 certificate hash is missing"))?;
    if hash.value != openssl::hash::hash(algorithm, &cms.signer.to_der()?)?.as_ref() {
        return Err(crypto("ESSCertIDv2 does not bind the signer certificate"));
    }
    Ok(())
}
fn digest_algorithm(oid: &str) -> Result<MessageDigest> {
    match oid {
        "1.3.14.3.2.26" => Ok(MessageDigest::sha1()),
        "2.16.840.1.101.3.4.2.1" => Ok(MessageDigest::sha256()),
        "2.16.840.1.101.3.4.2.2" => Ok(MessageDigest::sha384()),
        "2.16.840.1.101.3.4.2.3" => Ok(MessageDigest::sha512()),
        _ => Err(SignatureError::Unsupported(format!(
            "Unsupported CMS digest algorithm {oid}"
        ))),
    }
}
fn openssl_verify(bytes: &[u8], detached: Option<&[u8]>) -> Result<Vec<u8>> {
    let mut cms = CmsContentInfo::from_der(bytes)?;
    let mut output = Vec::new();
    cms.verify(
        None,
        None,
        detached,
        Some(&mut output),
        CMSOptions::BINARY | CMSOptions::NO_SIGNER_CERT_VERIFY,
    )?;
    Ok(output)
}
struct TimestampInfo {
    trust: String,
    policy: String,
    nonce: Option<Vec<u8>>,
}
fn verify_timestamp(bytes: &[u8], signature: &[u8], trust: &TrustConfig) -> Result<TimestampInfo> {
    let cms = parse_cms(bytes)?;
    if cms.content_type != TSTINFO {
        return Err(crypto("RFC 3161 token must contain TSTInfo"));
    }
    let content = cms
        .content
        .as_deref()
        .ok_or_else(|| crypto("RFC 3161 token content is missing"))?;
    openssl_verify(bytes, None)?;
    validate_attributes(&cms, content, false)?;
    let fields = Node::one(content)?.children()?;
    if fields.len() < 5 {
        return Err(crypto("RFC 3161 TSTInfo is incomplete"));
    }
    let imprint = fields[2].children()?;
    if imprint.len() != 2 {
        return Err(crypto("RFC 3161 message imprint is incomplete"));
    }
    let algorithm = imprint[0].children()?;
    if algorithm
        .first()
        .ok_or_else(|| crypto("RFC 3161 message imprint algorithm is missing"))?
        .oid()?
        != SHA256
        || imprint[1].value != Sha256::digest(signature).as_slice()
    {
        return Err(crypto(
            "RFC 3161 timestamp imprint does not bind the CMS signature value",
        ));
    }
    let cert_der = cms.signer.to_der()?;
    let (_, cert) =
        x509_parser::parse_x509_certificate(&cert_der).map_err(|e| crypto(&e.to_string()))?;
    let eku = cert
        .extended_key_usage()
        .map_err(|e| crypto(&e.to_string()))?
        .ok_or_else(|| crypto("RFC 3161 signer requires a critical timeStamping EKU"))?;
    if !eku.critical
        || !eku.value.time_stamping
        || eku.value.any
        || eku.value.server_auth
        || eku.value.client_auth
        || eku.value.code_signing
        || eku.value.email_protection
        || eku.value.ocsp_signing
        || !eku.value.other.is_empty()
    {
        return Err(crypto(
            "RFC 3161 signer requires exclusively critical timeStamping EKU",
        ));
    }
    let time = std::str::from_utf8(fields[4].value)
        .map_err(|_| crypto("RFC 3161 generation time is invalid"))?;
    let generation = chrono::NaiveDateTime::parse_from_str(time, "%Y%m%d%H%M%SZ")
        .or_else(|_| chrono::NaiveDateTime::parse_from_str(time, "%Y%m%d%H%M%S%.fZ"))
        .map_err(|_| crypto("RFC 3161 generation time is invalid"))?
        .and_utc()
        .timestamp();
    let generation = Asn1Time::from_unix(generation)?;
    if cms.signer.not_before() > generation || cms.signer.not_after() < generation {
        return Err(crypto(
            "RFC 3161 signer certificate is not valid at generation time",
        ));
    }
    let nonce = fields
        .iter()
        .skip(5)
        .find(|n| n.tag == 2)
        .map(|n| integer_bytes(n.value).to_vec());
    Ok(TimestampInfo {
        trust: if trust.tsa_certificates.contains(&sha256_hex(&cert_der)) {
            "valid"
        } else {
            "indeterminate"
        }
        .into(),
        policy: fields[1].oid()?,
        nonce,
    })
}
pub(crate) fn verify_document(
    bytes: &[u8],
    content: &[u8],
    trust: &TrustConfig,
) -> Result<(String, String)> {
    let cms = parse_cms(bytes)?;
    if cms.content_type != DATA || cms.content.is_some() {
        return Err(crypto("PDF CMS must contain detached data"));
    }
    openssl_verify(bytes, Some(content))?;
    validate_attributes(&cms, content, true)?;
    let stamps = attr_values(&cms.unsigned_attrs, TIMESTAMP)?;
    if stamps.len() != 1 {
        return Err(crypto(
            "Exactly one RFC 3161 signature timestamp is required",
        ));
    }
    let ts = verify_timestamp(stamps[0].raw, &cms.signature, trust)?;
    let certificate_trust = if validate_leaf(&cms.signer, false).is_err() {
        "invalid"
    } else {
        let mut state = "indeterminate";
        for root in &cms.certificates {
            if is_ca(root)? && trust.document_roots.contains(&sha256_hex(&root.to_der()?)) {
                let now = Asn1Time::days_from_now(0)?;
                if root.not_before() > now
                    || root.not_after() < now
                    || !root.verify(root.public_key()?.as_ref())?
                    || !cms.signer.verify(root.public_key()?.as_ref())?
                {
                    return Err(crypto("Document-signing trusted root validation failed"));
                }
                state = "valid";
                break;
            }
        }
        state
    };
    Ok((certificate_trust.into(), ts.trust))
}
// DER encoding and bounded ASN.1 framing. Input crypto is always checked by OpenSSL.
fn tlv(tag: u8, value: &[u8]) -> Vec<u8> {
    let mut out = vec![tag];
    if value.len() < 128 {
        out.push(value.len() as u8)
    } else {
        let n = value.len().to_be_bytes();
        let first = n.iter().position(|b| *b != 0).unwrap_or(n.len() - 1);
        out.push(0x80 | ((n.len() - first) as u8));
        out.extend_from_slice(&n[first..]);
    }
    out.extend_from_slice(value);
    out
}
fn sequence(parts: &[Vec<u8>]) -> Vec<u8> {
    tlv(0x30, &parts.concat())
}
fn integer(value: &[u8]) -> Vec<u8> {
    let mut v = integer_bytes(value).to_vec();
    if v.is_empty() {
        v.push(0);
    }
    if v[0] & 128 != 0 {
        v.insert(0, 0);
    }
    tlv(2, &v)
}
fn integer_bytes(mut value: &[u8]) -> &[u8] {
    while value.len() > 1 && value[0] == 0 {
        value = &value[1..];
    }
    value
}
fn algorithm(id: &str) -> Result<Vec<u8>> {
    Ok(sequence(&[oid(id)?, tlv(5, &[])]))
}
fn attribute(id: &str, value: Vec<u8>) -> Result<Vec<u8>> {
    Ok(sequence(&[oid(id)?, tlv(0x31, &value)]))
}
fn oid(id: &str) -> Result<Vec<u8>> {
    let parts = id
        .split('.')
        .map(|p| p.parse::<u64>())
        .collect::<std::result::Result<Vec<_>, _>>()
        .map_err(|_| config("Invalid ASN.1 object identifier"))?;
    if parts.len() < 2 || parts[0] > 2 || (parts[0] < 2 && parts[1] > 39) {
        return Err(config("Invalid ASN.1 object identifier"));
    }
    let mut value = Vec::new();
    for n in std::iter::once(parts[0] * 40 + parts[1]).chain(parts.into_iter().skip(2)) {
        let mut buf = vec![(n & 127) as u8];
        let mut n = n >> 7;
        while n != 0 {
            buf.push(128 | ((n & 127) as u8));
            n >>= 7;
        }
        buf.reverse();
        value.extend(buf);
    }
    Ok(tlv(6, &value))
}
#[derive(Clone, Copy)]
struct Node<'a> {
    tag: u8,
    value: &'a [u8],
    raw: &'a [u8],
}
impl<'a> Node<'a> {
    fn one(bytes: &'a [u8]) -> Result<Self> {
        let (n, end) = node(bytes, 0)?;
        if end != bytes.len() {
            return Err(crypto("ASN.1 object has trailing data"));
        }
        Ok(n)
    }
    fn children(&self) -> Result<Vec<Node<'a>>> {
        nodes(self.value)
    }
    fn oid(&self) -> Result<String> {
        if self.tag != 6 || self.value.is_empty() {
            return Err(crypto("ASN.1 object identifier is missing"));
        }
        let mut values = Vec::new();
        let mut v = 0u64;
        for b in self.value {
            v = v
                .checked_mul(128)
                .and_then(|v| v.checked_add((b & 127) as u64))
                .ok_or_else(|| crypto("ASN.1 object identifier is too large"))?;
            if b & 128 == 0 {
                values.push(v);
                v = 0;
            }
        }
        if self.value.last().unwrap() & 128 != 0 || values.is_empty() {
            return Err(crypto("ASN.1 object identifier is incomplete"));
        }
        let first = values.remove(0);
        let a = if first < 40 {
            0
        } else if first < 80 {
            1
        } else {
            2
        };
        let mut result = format!("{a}.{}", first - a * 40);
        for v in values {
            result.push_str(&format!(".{v}"));
        }
        Ok(result)
    }
}
fn nodes(bytes: &[u8]) -> Result<Vec<Node<'_>>> {
    let mut out = Vec::new();
    let mut offset = 0;
    while offset < bytes.len() {
        let (n, end) = node(&bytes[offset..], 0)?;
        out.push(n);
        offset += end;
    }
    Ok(out)
}
fn node(bytes: &[u8], depth: usize) -> Result<(Node<'_>, usize)> {
    if depth > 64 || bytes.len() < 2 {
        return Err(crypto("ASN.1 object is incomplete or too deeply nested"));
    }
    let tag = bytes[0];
    let length = bytes[1];
    let mut start = 2;
    let end;
    if length == 128 {
        if tag & 32 == 0 {
            return Err(crypto("ASN.1 indefinite primitive is unsupported"));
        }
        let mut pos = 2;
        loop {
            if bytes.get(pos..pos + 2) == Some(&[0, 0]) {
                end = pos;
                break;
            }
            let (_, used) = node(
                bytes
                    .get(pos..)
                    .ok_or_else(|| crypto("ASN.1 object is incomplete"))?,
                depth + 1,
            )?;
            pos += used;
        }
        let total = end + 2;
        return Ok((
            Node {
                tag,
                value: &bytes[start..end],
                raw: &bytes[..total],
            },
            total,
        ));
    } else if length & 128 != 0 {
        let count = (length & 127) as usize;
        if count == 0 || count > 8 || bytes.len() < 2 + count {
            return Err(crypto("ASN.1 length is invalid"));
        }
        let mut n = 0usize;
        for b in &bytes[2..2 + count] {
            n = n
                .checked_mul(256)
                .and_then(|v| v.checked_add(*b as usize))
                .ok_or_else(|| crypto("ASN.1 length overflow"))?;
        }
        start += count;
        end = start
            .checked_add(n)
            .ok_or_else(|| crypto("ASN.1 length overflow"))?;
    } else {
        end = start + length as usize;
    }
    if end > bytes.len() {
        return Err(crypto("ASN.1 object is incomplete"));
    }
    Ok((
        Node {
            tag,
            value: &bytes[start..end],
            raw: &bytes[..end],
        },
        end,
    ))
}
pub(crate) fn cms_unpadded(bytes: &[u8]) -> Result<&[u8]> {
    let (_, used) = node(bytes, 0)?;
    if bytes[used..].iter().any(|b| *b != 0) {
        return Err(crypto("Signature Contents padding must be all zero"));
    }
    Ok(&bytes[..used])
}

#[cfg(test)]
pub(crate) fn test_timestamp_token(
    identity: &DocumentIdentity,
    signature: &[u8],
    nonce: Option<&[u8]>,
) -> Result<Vec<u8>> {
    test_timestamp_imprint(
        identity,
        &Sha256::digest(signature),
        nonce,
        "1.3.6.1.4.1.55555.1",
    )
}
#[cfg(test)]
fn test_timestamp_imprint(
    identity: &DocumentIdentity,
    imprint: &[u8],
    nonce: Option<&[u8]>,
    policy: &str,
) -> Result<Vec<u8>> {
    let mut fields = vec![
        integer(&[1]),
        oid(policy)?,
        sequence(&[algorithm(SHA256)?, tlv(4, imprint)]),
        integer(&[1]),
        tlv(
            0x18,
            chrono::Utc::now()
                .format("%Y%m%d%H%M%SZ")
                .to_string()
                .as_bytes(),
        ),
    ];
    if let Some(n) = nonce {
        fields.push(integer(n));
    }
    let info = sequence(&fields);
    let attrs = signed_attributes(&info, &identity.chain[0], TSTINFO)?;
    let signature = sign_attributes(&attrs, identity)?;
    assemble(identity, &attrs, &signature, None, TSTINFO, Some(&info))
}
#[cfg(test)]
pub(crate) fn test_timestamp_response(
    identity: &DocumentIdentity,
    request: &[u8],
) -> Result<Vec<u8>> {
    let fields = Node::one(request)?.children()?;
    let imprint = fields[1].children()?[1].value;
    let nonce = fields.iter().skip(2).find(|n| n.tag == 2).map(|n| n.value);
    let policy = fields
        .iter()
        .skip(2)
        .find(|n| n.tag == 6)
        .map(|n| n.oid())
        .transpose()?
        .unwrap_or("1.3.6.1.4.1.55555.1".into());
    let token = test_timestamp_imprint(identity, imprint, nonce, &policy)?;
    Ok(sequence(&[sequence(&[integer(&[0])]), token]))
}

/// HTTP transport for production wiring; tests use an in-process offline TSA.
/// No network request occurs while constructing or checking configuration.
pub struct HttpTimestampTransport {
    url: reqwest::Url,
    client: reqwest::Client,
    runtime: tokio::runtime::Runtime,
    header_timeout: std::time::Duration,
}
impl HttpTimestampTransport {
    pub fn new(url: &str, timeout_seconds: u64) -> Result<Self> {
        if !(1..=60).contains(&timeout_seconds) {
            return Err(config("RFC 3161 timeout must be between 1 and 60 seconds"));
        }
        let url =
            reqwest::Url::parse(url).map_err(|_| config("PAdES-B-T requires a valid TSA URL"))?;
        if !matches!(url.scheme(), "http" | "https") {
            return Err(config("RFC 3161 TSA URL must use HTTP or HTTPS"));
        }
        let header_timeout = std::time::Duration::from_secs(timeout_seconds);
        // Java HttpRequest.timeout stops guarding once headers arrive. Keep
        // that default compatibility boundary; do not turn a later EOF into
        // a total-body timeout and change the durable outcome classification.
        let client = reqwest::Client::builder()
            .connect_timeout(header_timeout)
            .redirect(reqwest::redirect::Policy::none())
            .build()
            .map_err(|e| config(&e.to_string()))?;
        let runtime = tokio::runtime::Builder::new_multi_thread()
            .worker_threads(1)
            .enable_all()
            .build()
            .map_err(|e| config(&e.to_string()))?;
        Ok(Self {
            url,
            client,
            runtime,
            header_timeout,
        })
    }
}
impl TimestampTransport for HttpTimestampTransport {
    fn post_timestamp_query(&self, request: &[u8]) -> Result<Vec<u8>> {
        self.runtime.block_on(async {
            let sending = self
                .client
                .post(self.url.clone())
                .header("Content-Type", "application/timestamp-query")
                .header("Accept", "application/timestamp-reply")
                .body(request.to_vec())
                .send();
            let mut response = tokio::time::timeout(self.header_timeout, sending)
                .await
                .map_err(|_| {
                    SignatureError::OutcomeUnknown("RFC 3161 response headers timed out".into())
                })?
                .map_err(|e| {
                    if transport_uncertain(&e) {
                        SignatureError::OutcomeUnknown(format!("RFC 3161 transport failed: {e}"))
                    } else {
                        SignatureError::Timestamp(format!("RFC 3161 request failed: {e}"))
                    }
                })?;
            if !response.status().is_success() {
                return Err(SignatureError::Timestamp(format!(
                    "RFC 3161 service returned HTTP {}",
                    response.status().as_u16()
                )));
            }
            let mut bytes = Vec::new();
            while let Some(chunk) = response.chunk().await.map_err(|e| {
                if transport_uncertain(&e) {
                    SignatureError::OutcomeUnknown(format!(
                        "RFC 3161 response transport failed: {e}"
                    ))
                } else {
                    SignatureError::Timestamp(format!("RFC 3161 response failed: {e}"))
                }
            })? {
                if bytes.len().saturating_add(chunk.len()) > 8 * 1024 * 1024 {
                    return Err(SignatureError::Timestamp(
                        "RFC 3161 response exceeds 8 MiB".into(),
                    ));
                }
                bytes.extend_from_slice(&chunk);
            }
            Ok(bytes)
        })
    }
}

#[cfg(test)]
pub(crate) fn test_timestamp_parts(pdf_cms: &[u8]) -> Result<(Vec<u8>, Vec<u8>)> {
    let cms = parse_cms(pdf_cms)?;
    let timestamps = attr_values(&cms.unsigned_attrs, TIMESTAMP)?;
    Ok((cms.signature, timestamps[0].raw.to_vec()))
}

/// Match the Java execution boundary: timeout/connect/socket failures are
/// uncertain; TLS validation failures and ordinary EOF/protocol failures are
/// known failures. Walk typed causes rather than matching exception messages.
fn transport_uncertain(error: &(dyn std::error::Error + 'static)) -> bool {
    let mut pending = vec![error];
    let mut visited = HashSet::new();
    let mut uncertain = false;
    while let Some(error) = pending.pop() {
        if !visited.insert(error as *const dyn std::error::Error as *const () as usize) {
            continue;
        }
        if error.is::<rustls::Error>() {
            return false;
        }
        if let Some(error) = error.downcast_ref::<reqwest::Error>() {
            uncertain |= error.is_timeout() || error.is_connect();
        }
        if let Some(error) = error.downcast_ref::<std::io::Error>() {
            uncertain |= matches!(
                error.kind(),
                std::io::ErrorKind::TimedOut
                    | std::io::ErrorKind::ConnectionAborted
                    | std::io::ErrorKind::ConnectionRefused
                    | std::io::ErrorKind::ConnectionReset
                    | std::io::ErrorKind::NotConnected
                    | std::io::ErrorKind::BrokenPipe
                    | std::io::ErrorKind::NetworkDown
                    | std::io::ErrorKind::NetworkUnreachable
                    | std::io::ErrorKind::HostUnreachable
                    | std::io::ErrorKind::AddrInUse
                    | std::io::ErrorKind::AddrNotAvailable
            );
            // io::Error::source may skip the wrapped error itself. Inspect the
            // typed inner value as well, so rustls handshake failures survive
            // the connector's double io::Error wrapping.
            if let Some(inner) = error.get_ref() {
                pending.push(inner);
            }
        }
        if let Some(source) = error.source() {
            pending.push(source);
        }
    }
    uncertain
}
