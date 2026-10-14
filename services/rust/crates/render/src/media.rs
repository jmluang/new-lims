//! Java legacy process pre-sign media operations. Never call after signing.
use crate::{blank, inherit, num, service_error, utf16};
use anyhow::{Context, Result, bail, ensure};
use lims_core::ServiceError;
use lopdf::{Dictionary, Document, Object, Stream, dictionary};
use rxing::{BarcodeFormat, EncodeHints, Writer};
use serde_json::Value;

#[derive(Clone, Debug)]
pub struct MediaConfig {
    pub certificate_query_base_url: String,
    pub qr_width_px: i32,
    pub qr_height_px: i32,
    pub qr_size_mm: f32,
    pub qr_margin_mm: f32,
    pub qr_offset_left_mm: f32,
    /// Java's misnamed TOP setting is a bottom offset when explicitly present.
    pub qr_offset_top_mm: Option<f32>,
}
impl Default for MediaConfig {
    fn default() -> Self {
        Self {
            certificate_query_base_url: "https://www.yanzhenjia.cn/".into(),
            qr_width_px: 200,
            qr_height_px: 200,
            qr_size_mm: 15.6,
            qr_margin_mm: 10.,
            qr_offset_left_mm: 0.,
            qr_offset_top_mm: None,
        }
    }
}
impl MediaConfig {
    pub fn from_env() -> Self {
        Self::from_lookup(|k| std::env::var(k).ok())
    }
    pub fn from_lookup(get: impl Fn(&str) -> Option<String>) -> Self {
        fn float(get: &impl Fn(&str) -> Option<String>, key: &str, default: f32) -> f32 {
            get(key)
                .filter(|s| !blank(s))
                .and_then(|s| s.trim().parse().ok())
                .unwrap_or(default)
        }
        let mut c = Self::default();
        if let Some(s) = get("CERTIFICATE_QUERY_BASE_URL").filter(|s| !blank(s)) {
            c.certificate_query_base_url = s;
        }
        // Java's shared catch restores BOTH dimensions when either parse fails.
        let dimensions = (|| -> Option<(i32, i32)> {
            let w = get("QR_CODE_WIDTH_PX")
                .filter(|s| !blank(s))
                .map(|s| s.trim().parse::<i32>().ok())
                .unwrap_or(Some(200))?;
            let h = get("QR_CODE_HEIGHT_PX")
                .filter(|s| !blank(s))
                .map(|s| s.trim().parse::<i32>().ok())
                .unwrap_or(Some(200))?;
            Some((w.max(10), h.max(10)))
        })()
        .unwrap_or((200, 200));
        c.qr_width_px = dimensions.0;
        c.qr_height_px = dimensions.1;
        c.qr_size_mm = float(&get, "QR_CODE_SIZE_MM", 15.6);
        c.qr_margin_mm = float(&get, "QR_CODE_MARGIN_MM", 10.);
        c.qr_offset_left_mm = float(&get, "QR_CODE_OFFSET_LEFT_MM", 0.);
        c.qr_offset_top_mm = get("QR_CODE_OFFSET_TOP_MM")
            .filter(|s| !blank(s))
            .and_then(|s| s.trim().parse().ok());
        c
    }
}
#[derive(Clone, Debug)]
pub struct PreparedPdf {
    pub pdf: Vec<u8>,
    /// Seven-field extract-cover data; process drops extraction_status.
    pub cover_fields: Value,
    pub effective_report_number: Option<String>,
    /// None if skipped because report/AppID is absent, or QR encoding failed.
    pub qr_url: Option<String>,
}

#[derive(Clone, Debug)]
pub struct PreparedMetadata {
    pub cover_fields: Value,
    pub effective_report_number: Option<String>,
    pub qr_url: Option<String>,
}
/// Java legacy preprocessing for unsigned input only. This API is never suitable
/// for incremental modern signatures; it performs a fresh serialization.
pub fn prepare_unsigned_pdf(
    pdf: &[u8],
    confirmed_report_number: Option<&str>,
    report_appid: Option<&str>,
    config: &MediaConfig,
) -> std::result::Result<PreparedPdf, ServiceError> {
    let (mut doc, metadata) = prepare_document(pdf, confirmed_report_number, report_appid, config)?;
    let mut output = Vec::new();
    doc.save_to(&mut output)
        .map_err(|e| service_error(500, "PDF_SERIALIZATION_FAILED", e))?;
    Ok(PreparedPdf {
        pdf: output,
        cover_fields: metadata.cover_fields,
        effective_report_number: metadata.effective_report_number,
        qr_url: metadata.qr_url,
    })
}
/// Disk-backed serialization avoids retaining an additional complete PDF Vec.
pub fn prepare_unsigned_pdf_to_file(
    pdf: &[u8],
    confirmed_report_number: Option<&str>,
    report_appid: Option<&str>,
    config: &MediaConfig,
    output: &std::path::Path,
) -> std::result::Result<PreparedMetadata, ServiceError> {
    let (mut doc, metadata) = prepare_document(pdf, confirmed_report_number, report_appid, config)?;
    doc.save(output)
        .map_err(|e| service_error(500, "PDF_SERIALIZATION_FAILED", e))?;
    Ok(metadata)
}
fn prepare_document(
    pdf: &[u8],
    confirmed_report_number: Option<&str>,
    report_appid: Option<&str>,
    config: &MediaConfig,
) -> std::result::Result<(Document, PreparedMetadata), ServiceError> {
    let mut doc = load_pdf(pdf).map_err(|e| service_error(500, "PDF_INVALID_DOCUMENT", e))?;
    reject_signed_input(&doc)?;
    let cover_fields = crate::cover::extract_from_document(&doc).unwrap_or(Value::Null);
    let effective_report_number = confirmed_report_number
        .filter(|s| !blank(s))
        .map(|s| s.trim_matches(|c: char| c <= '\u{20}').to_owned())
        .or_else(|| cover_fields["report_number"].as_str().map(str::to_owned));
    clean_pdf_metadata(&mut doc, effective_report_number.as_deref())
        .map_err(|e| service_error(500, "PDF_METADATA_FAILED", e))?;
    let mut qr_url =
        certificate_query_url(effective_report_number.as_deref(), report_appid, config)
            .map_err(|e| service_error(500, "PDF_QR_URL_INVALID", e))?;
    if let Some(url) = &qr_url
        && add_query_qr(&mut doc, url, config).is_err()
    {
        qr_url = None;
    }
    Ok((
        doc,
        PreparedMetadata {
            cover_fields,
            effective_report_number,
            qr_url,
        },
    ))
}
fn load_pdf(pdf: &[u8]) -> Result<Document> {
    let mut d = crate::pdf_load::load_legacy(pdf, lopdf::LoadOptions::default())?;
    if d.is_encrypted() {
        d.decrypt("")?;
    }
    Ok(d)
}
fn resolved<'a>(doc: &'a Document, o: &'a Object) -> Result<&'a Object> {
    Ok(doc.dereference(o)?.1)
}
fn dict<'a>(doc: &'a Document, o: &'a Object) -> Result<&'a Dictionary> {
    Ok(resolved(doc, o)?.as_dict()?)
}
fn has_signed_field(
    doc: &Document,
    fields: &[Object],
    inherited_sig: bool,
    depth: usize,
) -> Result<bool> {
    ensure!(depth < 128, "AcroForm field tree exceeds depth limit");
    for field in fields {
        let f = dict(doc, field)?;
        let sig = f
            .get(b"FT")
            .ok()
            .map(|v| resolved(doc, v))
            .transpose()?
            .map(|v| v.as_name())
            .transpose()?
            .map(|v| v == b"Sig")
            .unwrap_or(inherited_sig);
        if sig && f.get(b"V").ok().is_some_and(|v| dict(doc, v).is_ok()) {
            return Ok(true);
        }
        if let Ok(kids) = f.get(b"Kids")
            && has_signed_field(doc, resolved(doc, kids)?.as_array()?, sig, depth + 1)?
        {
            return Ok(true);
        }
    }
    Ok(false)
}
/// Rejects populated signature fields and a catalog DocMDP permission, not empty signature widgets.
pub fn reject_signed_input(doc: &Document) -> std::result::Result<(), ServiceError> {
    let check = (|| -> Result<bool> {
        let catalog = doc.catalog()?;
        if let Ok(perms) = catalog.get(b"Perms")
            && dict(doc, perms)?.has(b"DocMDP")
        {
            return Ok(true);
        }
        if let Ok(acro) = catalog.get(b"AcroForm")
            && let Ok(fields) = dict(doc, acro)?.get(b"Fields")
        {
            return has_signed_field(doc, resolved(doc, fields)?.as_array()?, false, 0);
        }
        Ok(false)
    })()
    .map_err(|e| service_error(500, "PDF_INVALID_DOCUMENT", e))?;
    if check {
        Err(ServiceError::unprocessable(
            "PDF_LEGACY_PROCESS_SIGNED_INPUT_FORBIDDEN",
        ))
    } else {
        Ok(())
    }
}
/// Replaces only the six Java Info entries, preserving CreationDate/ModDate and custom entries.
pub fn clean_pdf_metadata(doc: &mut Document, report_number: Option<&str>) -> Result<()> {
    let mut info = doc
        .trailer
        .get(b"Info")
        .ok()
        .and_then(|o| dict(doc, o).ok())
        .cloned()
        .unwrap_or_default();
    for (key, value) in [
        (
            "Title",
            report_number.filter(|s| !blank(s)).unwrap_or("检测报告"),
        ),
        ("Author", "中山市鑫普达检测有限公司"),
        ("Creator", "鑫普达LIMS系统"),
        ("Producer", "鑫普达PDF处理服务"),
        ("Subject", "检测报告文件"),
        ("Keywords", "检测,报告,认证,质量"),
    ] {
        info.set(key, utf16(value));
    }
    let id = doc.add_object(info);
    doc.trailer.set("Info", id);
    Ok(())
}
/// URLEncoder UTF-8 form encoding, including Java's '*' and '+' behavior.
pub fn java_urlencode(s: &str) -> String {
    let mut out = String::new();
    use std::fmt::Write;
    for b in s.as_bytes() {
        match *b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'.' | b'-' | b'*' | b'_' => {
                out.push(*b as char)
            }
            b' ' => out.push('+'),
            v => {
                write!(&mut out, "%{v:02X}").unwrap();
            }
        }
    }
    out
}
pub fn certificate_query_url(
    report_number: Option<&str>,
    appid: Option<&str>,
    config: &MediaConfig,
) -> Result<Option<String>> {
    let Some(report) = report_number.filter(|s| !blank(s)) else {
        return Ok(None);
    };
    let base = &config.certificate_query_base_url;
    ensure!(
        !base.chars().any(|c| c.is_ascii_whitespace()
            || matches!(c, '<' | '>' | '"' | '{' | '}' | '|' | '\\' | '^' | '`')),
        "Invalid query base URI"
    );
    for (i, b) in base.bytes().enumerate() {
        if b == b'%' {
            ensure!(
                base.as_bytes()
                    .get(i + 1..i + 3)
                    .is_some_and(|s| s.iter().all(u8::is_ascii_hexdigit)),
                "Invalid query URI escape"
            )
        }
    }
    let host = url::Url::parse(base)
        .ok()
        .and_then(|u| u.host_str().map(str::to_owned));
    let yanzhenjia = host.as_deref().is_some_and(|h| {
        h.eq_ignore_ascii_case("yanzhenjia.cn") || h.eq_ignore_ascii_case("www.yanzhenjia.cn")
    });
    let valid_appid = appid.filter(|s| s.len() == 32 && s.bytes().all(|b| b.is_ascii_hexdigit()));
    if yanzhenjia && valid_appid.is_none() {
        return Ok(None);
    }
    let separator = if base.contains('?') { '&' } else { '?' };
    let mut result = format!("{base}{separator}query={}", java_urlencode(report));
    if let Some(id) = valid_appid {
        result.push_str("&appid=");
        result.push_str(&id.to_ascii_lowercase());
    }
    Ok(Some(result))
}
fn matrix(
    data: &str,
    width: i32,
    height: i32,
    hints: Option<&EncodeHints>,
) -> Result<rxing::common::BitMatrix> {
    let writer = rxing::qrcode::QRCodeWriter;
    match hints {
        Some(h) => writer.encode_with_hints(data, &BarcodeFormat::QR_CODE, width, height, h),
        None => writer.encode(data, &BarcodeFormat::QR_CODE, width, height),
    }
    .map_err(|e| anyhow::anyhow!("QR encoding failed: {e}"))
}
pub fn generate_qr_code_bytes(url: &str, size_mm: i32) -> Result<Vec<u8>> {
    ensure!(
        !url.trim_matches(|c: char| c <= '\u{20}').is_empty(),
        "URL cannot be null or empty"
    );
    let size = ((size_mm as f64 * 11.811) + 0.5).floor() as i32;
    let hints = EncodeHints {
        ErrorCorrection: Some("M".into()),
        CharacterSet: Some("UTF-8".into()),
        Margin: Some("1".into()),
        ..Default::default()
    };
    matrix_png(&matrix(url, size, size, Some(&hints))?)
}
pub fn generate_query_qr_png(url: &str, width: i32, height: i32) -> Result<Vec<u8>> {
    matrix_png(&matrix(url, width, height, None)?)
}
fn matrix_png(m: &rxing::common::BitMatrix) -> Result<Vec<u8>> {
    let mut im = image::GrayImage::new(m.getWidth(), m.getHeight());
    for (x, y, p) in im.enumerate_pixels_mut() {
        *p = image::Luma([if m.get(x, y) { 0 } else { 255 }]);
    }
    let mut out = std::io::Cursor::new(Vec::new());
    im.write_to(&mut out, image::ImageFormat::Png)?;
    Ok(out.into_inner())
}
fn add_query_qr(doc: &mut Document, url: &str, c: &MediaConfig) -> Result<()> {
    let Some(page) = doc.get_pages().into_values().next() else {
        return Ok(());
    };
    let m = matrix(url, c.qr_width_px, c.qr_height_px, None)?;
    let mb = inherit(doc, page, b"MediaBox")?.context("Page MediaBox absent")?;
    let values = resolved(doc, &mb)?.as_array()?;
    ensure!(values.len() == 4, "Invalid page MediaBox");
    let coords: Vec<f32> = values
        .iter()
        .map(|v| resolved(doc, v)?.as_float().map_err(Into::into))
        .collect::<Result<_>>()?;
    let size = (c.qr_size_mm as f64 * 2.83465) as f32;
    let margin = (c.qr_margin_mm as f64 * 2.83465) as f32;
    let left = (c.qr_offset_left_mm as f64 * 2.83465) as f32;
    let x = coords[2] - coords[0] - size - margin - left;
    let max_y = coords[3] - coords[1] - size - margin;
    let y = c
        .qr_offset_top_mm
        .map(|v| (margin + (v as f64 * 2.83465) as f32).min(max_y))
        .unwrap_or(max_y);
    ensure!(
        [x, y, size].iter().all(|v| v.is_finite()),
        "Non-finite QR placement"
    );
    let stride = m.getWidth().div_ceil(8) as usize;
    let mut packed = vec![255u8; stride * m.getHeight() as usize];
    for yy in 0..m.getHeight() {
        for xx in 0..m.getWidth() {
            if m.get(xx, yy) {
                packed[yy as usize * stride + xx as usize / 8] &= !(0x80 >> (xx % 8));
            }
        }
    }
    let image=doc.add_object(Stream::new(dictionary!{"Type"=>"XObject","Subtype"=>"Image","Width"=>m.getWidth() as i64,"Height"=>m.getHeight() as i64,"ColorSpace"=>"DeviceGray","BitsPerComponent"=>1,"Resources"=>Dictionary::new()},packed));
    let inherited = inherit(doc, page, b"Resources")?;
    let mut resources = inherited
        .as_ref()
        .map(|o| dict(doc, o).cloned())
        .transpose()?
        .unwrap_or_default();
    let mut xobjects = resources
        .get(b"XObject")
        .ok()
        .map(|o| dict(doc, o).cloned())
        .transpose()?
        .unwrap_or_default();
    let mut i = 1;
    while xobjects.has(format!("Im{i}").as_bytes()) {
        i += 1;
    }
    let name = format!("Im{i}");
    xobjects.set(name.as_str(), image);
    resources.set("XObject", xobjects);
    let current = doc.get_dictionary(page)?.get(b"Contents").ok().cloned();
    let mut contents = Vec::new();
    if let Some(o) = current {
        match resolved(doc, &o)? {
            Object::Array(a) => contents.extend(a.clone()),
            Object::Stream(_) => contents.push(o),
            Object::Null => (),
            _ => bail!("Invalid page Contents"),
        }
    }
    if !contents.is_empty() {
        let prefix = doc.add_object(Stream::new(Dictionary::new(), b"q\n".to_vec()));
        contents.insert(0, Object::Reference(prefix));
    }
    let mut ops = if contents.is_empty() {
        String::new()
    } else {
        "Q\n".into()
    };
    ops.push_str(&format!(
        "q\n{} 0 0 {} {} {} cm\n/{name} Do\nQ\n",
        num(size),
        num(size),
        num(x),
        num(y)
    ));
    let stream = doc.add_object(Stream::new(Dictionary::new(), ops.into_bytes()));
    contents.push(Object::Reference(stream));
    let page = doc.get_dictionary_mut(page)?;
    page.set("Resources", resources);
    page.set("Contents", Object::Array(contents));
    Ok(())
}
