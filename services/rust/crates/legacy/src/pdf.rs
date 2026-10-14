use crate::spool;
use crate::{Config, Error, Result, geometry::array};
use foreign_types::ForeignType;
use image::RgbaImage;
use lopdf::{Dictionary, Document, Object, ObjectId, Stream, StringFormat, dictionary};
use openssl::{
    cms::{CMSOptions, CmsContentInfo},
    pkey::{Id, PKey, Private},
    stack::Stack,
    x509::X509,
};
use std::collections::BTreeMap;
use std::{
    io::{Read, Seek, SeekFrom, Write},
    path::{Path, PathBuf},
};

pub struct Identity {
    revision_cache: std::cell::RefCell<Option<(PathBuf, Document)>>,
    key: PKey<Private>,
    certificate: X509,
    chain: Stack<X509>,
}
impl Identity {
    pub fn load(config: &Config) -> Result<Self> {
        let password = config
            .pfx_password
            .as_ref()
            .filter(|s| !s.trim().is_empty())
            .ok_or_else(|| Error::unavailable("PDF_SIGNING_PASSWORD_REQUIRED"))?;
        let bytes = std::fs::read(&config.pfx_path)
            .map_err(|_| Error::unavailable("PDF_SIGNING_KEY_UNAVAILABLE"))?;
        Self::from_pkcs12(&bytes, password)
    }
    pub fn from_pkcs12(bytes: &[u8], password: &str) -> Result<Self> {
        let parsed = lims_crypto::parse_pkcs12(bytes, password).map_err(|error| {
            Error::unavailable(match error {
                lims_crypto::Pkcs12Error::InvalidEncoding(_) => "PDF_INVALID_PKCS12",
                lims_crypto::Pkcs12Error::InvalidPasswordOrData(_) => {
                    "PDF_PKCS12_PASSWORD_OR_DATA_INVALID"
                }
                lims_crypto::Pkcs12Error::LegacyProviderUnavailable(_) => {
                    "PDF_PKCS12_LEGACY_PROVIDER_UNAVAILABLE"
                }
            })
        })?;
        let key = parsed
            .pkey
            .ok_or_else(|| Error::unavailable("PDF_PKCS12_PRIVATE_KEY_MISSING"))?;
        let certificate = parsed
            .cert
            .ok_or_else(|| Error::unavailable("PDF_PKCS12_CERTIFICATE_MISSING"))?;
        if key.id() != Id::RSA {
            return Err(Error::unavailable("PDF_SIGNING_KEY_RSA_REQUIRED"));
        }
        let public = certificate.public_key().map_err(Error::crypto)?;
        if !key.public_eq(&public) {
            return Err(Error::unavailable("PDF_SIGNING_KEY_CERTIFICATE_MISMATCH"));
        }
        Ok(Self {
            revision_cache: std::cell::RefCell::new(None),
            key,
            certificate,
            chain: match parsed.ca {
                Some(ca) => ca,
                None => Stack::new().map_err(Error::crypto)?,
            },
        })
    }
    fn sign_file(&self, path: &Path) -> Result<Vec<u8>> {
        use std::os::unix::ffi::OsStrExt;
        let name = std::ffi::CString::new(path.as_os_str().as_bytes())
            .map_err(|_| Error::internal("PDF_TEMPORARY_STORAGE_FAILED"))?;
        struct Bio(*mut openssl_sys::BIO);
        impl Drop for Bio {
            fn drop(&mut self) {
                unsafe { openssl_sys::BIO_free_all(self.0) }
            }
        }
        // SAFETY: name/mode are NUL-terminated; all pointers remain alive through CMS_sign.
        // BIO owns and closes FILE; CMS ownership transfers to the openssl crate immediately.
        let cms = unsafe {
            let fp = libc::fopen(name.as_ptr(), c"rb".as_ptr());
            if fp.is_null() {
                return Err(Error::internal("PDF_TEMPORARY_STORAGE_FAILED"));
            }
            let pointer = openssl_sys::BIO_new_fp(fp, 1);
            if pointer.is_null() {
                libc::fclose(fp);
                return Err(Error::crypto(openssl::error::ErrorStack::get()));
            }
            let bio = Bio(pointer);
            let pointer = openssl_sys::CMS_sign(
                self.certificate.as_ptr(),
                self.key.as_ptr(),
                self.chain.as_ptr(),
                bio.0,
                (CMSOptions::DETACHED | CMSOptions::BINARY | CMSOptions::NOSMIMECAP).bits(),
            );
            if pointer.is_null() {
                return Err(Error::crypto(openssl::error::ErrorStack::get()));
            }
            CmsContentInfo::from_ptr(pointer)
        };
        cms.to_der().map_err(Error::crypto)
    }
    pub fn rsa_bits(&self) -> u32 {
        self.key.bits()
    }
    pub fn certificate_count(&self) -> usize {
        1 + self.chain.len()
    }
}
#[derive(Clone)]
pub(crate) struct Widget {
    pub page: usize,
    pub image: Option<RgbaImage>,
    pub rect: [f32; 4],
    pub multistate: bool,
    pub flags: i64,
    pub appearance_state: bool,
}
pub(crate) fn arr(values: &[f32]) -> Object {
    Object::Array(values.iter().map(|v| Object::Real(*v)).collect())
}
fn form(doc: &mut Document, w: f32, h: f32, resources: Dictionary, content: Vec<u8>) -> ObjectId {
    let mut stream = Stream::new(
        dictionary! {"Type"=>"XObject","Subtype"=>"Form","FormType"=>1,"BBox"=>arr(&[0.,0.,w,h]),"Resources"=>resources},
        content,
    );
    stream.allows_compression = false;
    doc.add_object(stream)
}
pub(crate) fn image_object(doc: &mut Document, image: &RgbaImage) -> Result<ObjectId> {
    let (w, h) = image.dimensions();
    let mut rgb = Vec::with_capacity((w as usize) * (h as usize) * 3);
    let mut alpha = Vec::with_capacity((w as usize) * (h as usize));
    let mut transparent = false;
    for pixel in image.pixels() {
        rgb.extend_from_slice(&pixel.0[..3]);
        alpha.push(pixel.0[3]);
        transparent |= pixel.0[3] != 255;
    }
    let mut dict = dictionary! {"Type"=>"XObject","Subtype"=>"Image","Width"=>w as i64,"Height"=>h as i64,"ColorSpace"=>"DeviceRGB","BitsPerComponent"=>8,"Resources"=>Dictionary::new()};
    if transparent {
        let mut mask = Stream::new(
            dictionary! {"Type"=>"XObject","Subtype"=>"Image","Width"=>w as i64,"Height"=>h as i64,"ColorSpace"=>"DeviceGray","BitsPerComponent"=>8},
            alpha,
        );
        mask.compress().map_err(Error::pdf)?;
        dict.set("SMask", doc.add_object(mask));
    }
    let mut stream = Stream::new(dict, rgb);
    stream.compress().map_err(Error::pdf)?;
    Ok(doc.add_object(stream))
}
fn appearance(
    doc: &mut Document,
    image: Option<&RgbaImage>,
    w: f32,
    h: f32,
    multistate: bool,
) -> Result<ObjectId> {
    let (resources, draw) = if let Some(image) = image {
        let id = image_object(doc, image)?;
        (
            dictionary! {"XObject"=>dictionary!{"IMG"=>id}},
            format!("q\n{w:.4} 0 0 {h:.4} 0 0 cm\n/IMG Do\nQ\n"),
        )
    } else {
        (Dictionary::new(), String::new())
    };
    if !multistate {
        return Ok(form(doc, w, h, resources, draw.into_bytes()));
    }
    let n0 = form(doc, w, h, Dictionary::new(), vec![]);
    let n3 = form(doc, w, h, Dictionary::new(), vec![]);
    let n2 = form(doc, w, h, resources.clone(), draw.as_bytes().to_vec());
    let base = w.min(h);
    let thickness = (base * 0.18).max(1.2);
    let inset = thickness * 0.55;
    let right = (w - inset).max(inset);
    let top = (h - inset).max(inset);
    let border = (base * 0.05).max(0.8);
    let bi = border * 0.5;
    let invalid = format!(
        "{draw}{thickness:.4} w\n1 J\n1 j\n1 0 0 RG\n{inset:.4} {top:.4} m\n{right:.4} {inset:.4} l\n{right:.4} {top:.4} m\n{inset:.4} {inset:.4} l\nS\n{border:.4} w\n0 0 0 RG\n{bi:.4} {bi:.4} {:.4} {:.4} re\nS\n",
        w - 2. * bi,
        h - 2. * bi
    );
    let n1 = form(doc, w, h, resources, invalid.into_bytes());
    Ok(form(doc,w,h,dictionary!{"XObject"=>dictionary!{"n0"=>n0,"n1"=>n1,"n2"=>n2,"n3"=>n3}},b"q /n0 Do Q\nq 0.0001 0 0 0.0001 0 0 cm /n1 Do Q\nq /n2 Do Q\nq 1.01 0 0 1.01 6.1 5.6 cm /n3 Do Q\n".to_vec()))
}
fn field_partial_names(
    doc: &Document,
    fields: &[Object],
    depth: usize,
    names: &mut std::collections::BTreeSet<String>,
) -> Result<()> {
    if depth >= 128 {
        return Err(Error::input("PDF_INVALID_FORM_FIELDS"));
    }
    for field in fields {
        let dictionary = doc
            .dereference(field)
            .map_err(Error::pdf)?
            .1
            .as_dict()
            .map_err(Error::pdf)?;
        if let Ok(value) = dictionary.get(b"T")
            && let Ok(name) =
                lopdf::decode_text_string(doc.dereference(value).map_err(Error::pdf)?.1)
        {
            names.insert(name);
        }
        if let Ok(children) = dictionary.get(b"Kids") {
            field_partial_names(doc, &array(doc, children)?, depth + 1, names)?;
        }
    }
    Ok(())
}
fn next_signature_field_name(names: &mut std::collections::BTreeSet<String>) -> String {
    let mut number = 1;
    loop {
        let name = format!("Signature{number}");
        if names.insert(name.clone()) {
            return name;
        }
        number += 1;
    }
}

/// Appends exactly one detached CMS revision; every supplied widget refers to the same signature.
/// All previous bytes are preserved, including earlier Contents and ByteRange values.
pub(crate) fn sign_revision(
    previous: PathBuf,
    widgets: &[Widget],
    reserve: usize,
    identity: &Identity,
) -> Result<PathBuf> {
    let directory = previous
        .parent()
        .ok_or_else(|| Error::internal("PDF_TEMPORARY_STORAGE_FAILED"))?
        .to_path_buf();
    let previous_len = std::fs::metadata(&previous).map_err(spool::io)?.len();
    let mut parsed = match identity.revision_cache.borrow_mut().take() {
        Some((path, document)) if path == previous => document,
        _ => spool::load_for_signing(&previous)?,
    };
    if parsed.is_encrypted() {
        return Err(Error::input("PDF_ENCRYPTED_INPUT_UNSUPPORTED"));
    }
    let pages = parsed.get_pages();
    if pages.is_empty() {
        return Err(Error::input("PDF_SIGNATURE_REQUIRES_PAGE"));
    }
    let root_id = parsed
        .trailer
        .get(b"Root")
        .and_then(Object::as_reference)
        .map_err(Error::pdf)?;
    let mut root = parsed.get_dictionary(root_id).map_err(Error::pdf)?.clone();
    let mut acro = match root.get(b"AcroForm") {
        Ok(o) => parsed
            .dereference(o)
            .map_err(Error::pdf)?
            .1
            .as_dict()
            .map_err(Error::pdf)?
            .clone(),
        Err(_) => Dictionary::new(),
    };
    let mut fields = match acro.get(b"Fields") {
        Ok(o) => array(&parsed, o)?,
        Err(_) => vec![],
    };
    let mut page_edits = BTreeMap::new();
    for widget in widgets {
        let page_id = *pages
            .get(&((widget.page + 1) as u32))
            .ok_or_else(|| Error::input("PDF_SIGNATURE_PAGE_UNAVAILABLE"))?;
        let page = parsed.get_dictionary(page_id).map_err(Error::pdf)?.clone();
        let annots = match page.get(b"Annots") {
            Ok(o) => array(&parsed, o)?,
            Err(_) => vec![],
        };
        page_edits.entry(page_id).or_insert((page, annots));
    }
    let mut names = std::collections::BTreeSet::new();
    field_partial_names(&parsed, &fields, 0, &mut names)?;
    let mut next_doc = Document::new_from_prev(&parsed);
    let doc = &mut next_doc;
    let signature_id=doc.add_object(dictionary!{
        "Type"=>"Sig","Filter"=>"Adobe.PPKLite","SubFilter"=>"adbe.pkcs7.detached",
        "M"=>Object::string_literal(chrono::Utc::now().format("D:%Y%m%d%H%M%SZ").to_string()),
        "ByteRange"=>Object::Array(vec![0.into(),1111111111i64.into(),2222222222i64.into(),3333333333i64.into()]),
        "Contents"=>Object::String(vec![0;reserve],StringFormat::Hexadecimal)
    });
    for widget in widgets {
        let page_id = pages[&((widget.page + 1) as u32)];
        let [x, y, w, h] = widget.rect;
        let mut field = dictionary! {"Type"=>"Annot","Subtype"=>"Widget","FT"=>"Sig","T"=>Object::string_literal(next_signature_field_name(&mut names)),"F"=>widget.flags,"P"=>page_id,"Rect"=>arr(&[x,y,x+w,y+h]),"V"=>signature_id};
        if w != 0. || h != 0. {
            let ap = appearance(doc, widget.image.as_ref(), w, h, widget.multistate)?;
            field.set("AP", dictionary! {"N"=>ap});
            if widget.appearance_state && widget.multistate {
                field.set("AS", Object::Name(b"n2".to_vec()));
            }
        }
        let id = doc.add_object(field);
        fields.push(id.into());
        page_edits.get_mut(&page_id).unwrap().1.push(id.into());
    }
    for (id, (mut page, annots)) in page_edits {
        page.set("Annots", annots);
        doc.set_object(id, page);
    }
    acro.set("Fields", fields);
    acro.set("SigFlags", 3);
    acro.set("NeedAppearances", false);
    if !acro.has(b"DR") {
        acro.set("DR", Dictionary::new());
    }
    root.set("AcroForm", acro);
    doc.set_object(root_id, root);
    let (output, xref_start) = spool::append_revision(&previous, &directory, &next_doc)?;
    // Keep only the tiny structural index needed by the next revision; old page/image
    // streams and CMS buffers are released immediately, rather than reparsing the big prefix.
    parsed.max_id = next_doc.max_id;
    parsed.trailer = next_doc.trailer.clone();
    parsed.xref_start = xref_start as usize;
    for (id, mut object) in std::mem::take(&mut next_doc.objects) {
        if matches!(object, Object::Stream(_)) {
            continue;
        }
        if let Ok(dict) = object.as_dict_mut()
            && dict.get(b"Type").and_then(Object::as_name).ok() == Some(b"Sig")
        {
            dict.remove(b"Contents");
        }
        parsed.objects.insert(id, object);
    }
    drop(next_doc);
    let mut file = spool::open_update(&output)?;
    let object_marker = format!("{} {} obj", signature_id.0, signature_id.1);
    let object_start = spool::find(&mut file, previous_len, object_marker.as_bytes())?;
    let label = spool::find(&mut file, object_start, b"/Contents")? + 9;
    file.seek(SeekFrom::Start(label)).map_err(spool::io)?;
    let mut byte = [0u8; 1];
    let mut contents = label;
    loop {
        file.read_exact(&mut byte).map_err(spool::io)?;
        if !byte[0].is_ascii_whitespace() {
            break;
        }
        contents += 1;
    }
    let after = contents + 2 * reserve as u64 + 2;
    if byte[0] != b'<' {
        return Err(Error::internal("PDF_SIGNATURE_CONTENTS_BOUNDS"));
    }
    file.seek(SeekFrom::Start(after - 1)).map_err(spool::io)?;
    file.read_exact(&mut byte).map_err(spool::io)?;
    if byte[0] != b'>' {
        return Err(Error::internal("PDF_SIGNATURE_CONTENTS_BOUNDS"));
    }
    let length = file.metadata().map_err(spool::io)?.len();
    let marker = b"[0 1111111111 2222222222 3333333333]";
    let range_start = spool::find(&mut file, object_start, marker)?;
    let actual = format!("[0 {contents} {after} {}]", length - after);
    if actual.len() > marker.len() {
        return Err(Error::internal("PDF_SIGNATURE_RANGE_OVERFLOW"));
    }
    file.seek(SeekFrom::Start(range_start)).map_err(spool::io)?;
    file.write_all(actual.as_bytes()).map_err(spool::io)?;
    file.write_all(&vec![b' '; marker.len() - actual.len()])
        .map_err(spool::io)?;
    file.flush().map_err(spool::io)?;
    // The detached content is streamed to a short-lived file and OpenSSL hashes it through
    // a native FILE BIO. Neither detached content nor the full output is duplicated in RAM.
    let mut detached = tempfile::NamedTempFile::new_in(&directory).map_err(spool::io)?;
    file.seek(SeekFrom::Start(0)).map_err(spool::io)?;
    std::io::copy(&mut (&mut file).take(contents), &mut detached).map_err(spool::io)?;
    file.seek(SeekFrom::Start(after)).map_err(spool::io)?;
    std::io::copy(&mut file, &mut detached).map_err(spool::io)?;
    detached.flush().map_err(spool::io)?;
    let der = identity.sign_file(detached.path())?;
    if der.len() > reserve {
        return Err(Error::input("PDF_SIGNATURE_RESERVE_TOO_SMALL"));
    }
    const HEX: &[u8] = b"0123456789ABCDEF";
    let mut encoded = Vec::with_capacity(der.len() * 2);
    for byte in der {
        encoded.push(HEX[(byte >> 4) as usize]);
        encoded.push(HEX[(byte & 15) as usize]);
    }
    file.seek(SeekFrom::Start(contents + 1))
        .map_err(spool::io)?;
    file.write_all(&encoded).map_err(spool::io)?;
    file.flush().map_err(spool::io)?;
    drop(file);
    drop(detached);
    let output = spool::replace(previous, output)?;
    identity
        .revision_cache
        .replace(Some((output.clone(), parsed)));
    Ok(output)
}

/// Unsigned content changes are saved once before any signature is made.
pub(crate) fn stamp_content(
    doc: &mut Document,
    page_id: ObjectId,
    image: &RgbaImage,
    rect: [f32; 4],
) -> Result<()> {
    let mut page = doc.get_dictionary(page_id).map_err(Error::pdf)?.clone();
    // Resolve inherited resources before introducing the new XObject.
    let mut resource_source = page_id;
    let mut seen = std::collections::BTreeSet::new();
    let mut resources = Dictionary::new();
    while seen.insert(resource_source) {
        let d = doc.get_dictionary(resource_source).map_err(Error::pdf)?;
        if let Ok(obj) = d.get(b"Resources") {
            resources = doc
                .dereference(obj)
                .map_err(Error::pdf)?
                .1
                .as_dict()
                .map_err(Error::pdf)?
                .clone();
            break;
        }
        if let Ok(parent) = d.get(b"Parent").and_then(Object::as_reference) {
            resource_source = parent;
        } else {
            break;
        }
    }
    let mut xobjects = match resources.get(b"XObject") {
        Ok(obj) => doc
            .dereference(obj)
            .map_err(Error::pdf)?
            .1
            .as_dict()
            .map_err(Error::pdf)?
            .clone(),
        Err(_) => Dictionary::new(),
    };
    let image_id = image_object(doc, image)?;
    let name = format!("LimsSeal{}", image_id.0);
    xobjects.set(name.as_bytes(), image_id);
    resources.set("XObject", xobjects);
    page.set("Resources", resources);
    let [x, y, w, h] = rect;
    let reset = doc.add_object(Stream::new(Dictionary::new(), b"q\n".to_vec()));
    let draw = doc.add_object(Stream::new(
        Dictionary::new(),
        format!("Q\nq\n{w:.5} 0 0 {h:.5} {x:.5} {y:.5} cm\n/{name} Do\nQ\n").into_bytes(),
    ));
    let mut contents = vec![reset.into()];
    if let Ok(content) = page.get(b"Contents") {
        let resolved = doc.dereference(content).map_err(Error::pdf)?.1;
        match resolved {
            Object::Array(list) => contents.extend(list.clone()),
            _ => contents.push(content.clone()),
        }
    }
    contents.push(draw.into());
    page.set("Contents", contents);
    doc.set_object(page_id, page);
    Ok(())
}
