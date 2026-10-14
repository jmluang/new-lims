//! Legacy `/api/pdf/process` implementation. Normal detached SHA-256/RSA CMS only.
//! The newer PAdES execution protocol is intentionally implemented in a different crate.
mod config;
pub mod geometry;
mod pdf;
mod spool;
use base64::Engine;
pub use config::Config;
use geometry::{front_rect, media_box, mm, slice_range};
use image::RgbaImage;
use lims_core::{MultipartData, ServiceError};
use lopdf::Document;
pub use pdf::Identity;
use pdf::{Widget, sign_revision, stamp_content};
use serde_json::{Value, json};
pub use spool::FileProcessResult;
use std::path::PathBuf;

#[derive(Debug)]
pub struct Error(pub ServiceError);
pub type Result<T> = std::result::Result<T, Error>;
impl Error {
    fn input(code: &str) -> Self {
        Self(ServiceError::internal(code))
    }
    fn policy(code: &str) -> Self {
        Self(ServiceError::unprocessable(code))
    }
    fn unavailable(code: &str) -> Self {
        Self(ServiceError::internal(code))
    }
    fn internal(code: &str) -> Self {
        Self(ServiceError::internal(code))
    }
    fn pdf(_: lopdf::Error) -> Self {
        Self::input("PDF_INVALID_DOCUMENT")
    }
    fn crypto(_: openssl::error::ErrorStack) -> Self {
        Self::internal("PDF_CMS_SIGNING_FAILED")
    }
}
impl std::fmt::Display for Error {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        self.0.fmt(f)
    }
}
impl std::error::Error for Error {}
impl From<Error> for ServiceError {
    fn from(error: Error) -> Self {
        error.0
    }
}

pub fn ready() -> bool {
    Config::from_env().and_then(|c| Identity::load(&c)).is_ok()
}
pub fn process(data: &MultipartData) -> std::result::Result<Value, ServiceError> {
    process_with_config(
        data,
        &Config::from_env()?,
        &lims_render::MediaConfig::from_env(),
    )
}
/// Production streaming entry point. The returned owner must live until the response ends.
pub fn process_to_file(
    data: &MultipartData,
) -> std::result::Result<FileProcessResult, ServiceError> {
    process_to_file_with_config(
        data,
        &Config::from_env()?,
        &lims_render::MediaConfig::from_env(),
    )
}
pub fn process_with_config(
    data: &MultipartData,
    config: &Config,
    media: &lims_render::MediaConfig,
) -> std::result::Result<Value, ServiceError> {
    let result = process_to_file_with_config(data, config, media)?;
    let bytes = std::fs::read(&result.pdf_file)
        .map_err(|_| ServiceError::internal("PDF_TEMPORARY_STORAGE_FAILED"))?;
    let mut response = json!({"success":true,"pdf_base64":base64::engine::general_purpose::STANDARD.encode(bytes)});
    if !result.cover_fields.is_null() {
        response["cover_fields"] = result.cover_fields.clone();
    }
    Ok(response)
}
pub fn process_to_file_with_config(
    data: &MultipartData,
    config: &Config,
    media: &lims_render::MediaConfig,
) -> std::result::Result<FileProcessResult, ServiceError> {
    for key in ["signing_key_id", "hash_algo", "tsa_enabled", "tsa_url"] {
        if data.fields.contains_key(key) {
            return Err(ServiceError::unprocessable(
                "PDF_SIGNING_POLICY_OVERRIDE_FORBIDDEN",
            ));
        }
    }
    let mode = data.field("mode")?;
    reject_retired_mode(mode)?;
    let pdf = data.file("pdf")?;
    let count = data
        .fields
        .get("function_stamp_count")
        .map(|v| {
            v.trim()
                .parse::<i32>()
                .map_err(|_| ServiceError::bad_request("PDF_INVALID_FUNCTION_STAMP_COUNT"))
        })
        .transpose()?
        .unwrap_or(0);
    // Iterate uploaded entries, not the untrusted count: equivalent result without a billion-step loop.
    let mut functions = data
        .files
        .iter()
        .filter_map(|(name, file)| {
            let suffix = name.strip_prefix("function_stamp_")?;
            let index = suffix.parse::<i32>().ok()?;
            (index >= 0
                && index < count
                && name == &format!("function_stamp_{index}")
                && !file.bytes.is_empty())
            .then_some((index, file.bytes.as_slice()))
        })
        .collect::<Vec<_>>();
    functions.sort_by_key(|(index, _)| *index);
    let functions = functions
        .into_iter()
        .map(|(_, bytes)| bytes)
        .collect::<Vec<_>>();
    let nonempty = |key: &str| {
        data.files
            .get(key)
            .map(|f| f.bytes.as_slice())
            .filter(|b| !b.is_empty())
    };
    let directory = spool::directory(config)?;
    let (file, input_path) = spool::create(directory.path())?;
    drop(file);
    let prepared = lims_render::prepare_unsigned_pdf_to_file(
        pdf,
        data.fields.get("report_number").map(String::as_str),
        data.fields.get("report_appid").map(String::as_str),
        media,
        &input_path,
    )?;
    let output = process_unsigned_path(
        input_path,
        mode,
        nonempty("perforation_image"),
        nonempty("signature_appearance_image"),
        &functions,
        config,
    )?;
    let mut cover = prepared.cover_fields;
    if let Some(map) = cover.as_object_mut() {
        map.remove("extraction_status");
    }
    Ok(FileProcessResult {
        pdf_file: output,
        cover_fields: cover,
        directory,
    })
}
fn reject_retired_mode(mode: &str) -> Result<()> {
    if mode.eq_ignore_ascii_case("stamp_and_sign") {
        return Err(Error::policy("PDF_LEGACY_MODE_RETIRED"));
    }
    Ok(())
}

fn decode_optional(bytes: &[u8]) -> Result<Option<RgbaImage>> {
    match lims_images::decode(bytes) {
        Ok(image) => Ok(Some(image.to_rgba8())),
        Err(lims_images::DecodeError::Unsupported) => Ok(None),
        Err(_) => Err(Error::input("PDF_INVALID_STAMP_IMAGE")),
    }
}
fn decode(bytes: &[u8]) -> Result<RgbaImage> {
    decode_optional(bytes)?.ok_or_else(|| Error::input("PDF_UNSUPPORTED_STAMP_IMAGE"))
}
fn signature_widget(page: usize, image: Option<RgbaImage>, rect: [f32; 4]) -> Widget {
    Widget {
        page,
        image,
        rect,
        multistate: true,
        flags: 132,
        appearance_state: true,
    }
}

/// Apply the legacy mode after cover extraction, metadata and QR preprocessing.
/// This function still rejects signed/DocMDP input, so it cannot be used to rewrite a signed PDF.
pub fn process_unsigned_pdf(
    input: &[u8],
    mode: &str,
    perforation: Option<&[u8]>,
    front: Option<&[u8]>,
    functions: &[&[u8]],
    config: &Config,
) -> Result<Vec<u8>> {
    reject_retired_mode(mode)?;
    let directory = spool::directory(config)?;
    let path = spool::write_bytes(directory.path(), input)?;
    let output = process_unsigned_path(path, mode, perforation, front, functions, config)?;
    std::fs::read(output).map_err(spool::io)
}
fn process_unsigned_path(
    input: PathBuf,
    mode: &str,
    perforation: Option<&[u8]>,
    front: Option<&[u8]>,
    functions: &[&[u8]],
    config: &Config,
) -> Result<PathBuf> {
    reject_retired_mode(mode)?;
    config.validate()?;
    let directory = input
        .parent()
        .ok_or_else(|| Error::internal("PDF_TEMPORARY_STORAGE_FAILED"))?
        .to_path_buf();
    let mut doc = if mode.eq_ignore_ascii_case("stamp") && perforation.is_some() {
        Document::load(&input).map_err(Error::pdf)?
    } else {
        spool::load_for_signing(&input)?
    };
    reject_signed_input(&doc)?;
    if doc.is_encrypted() {
        return Err(Error::input("PDF_ENCRYPTED_INPUT_UNSUPPORTED"));
    }
    let page_ids = doc.get_pages().values().copied().collect::<Vec<_>>();
    let boxes = page_ids
        .iter()
        .map(|id| media_box(&doc, *id))
        .collect::<Result<Vec<_>>>()?;
    let dimensions = boxes
        .iter()
        .map(|b| (b[2] - b[0], b[3] - b[1]))
        .collect::<Vec<_>>();
    let front = front.filter(|b| !b.is_empty());
    let perforation = perforation.filter(|b| !b.is_empty());
    let has_functions = !functions.is_empty();
    let mode = mode.to_ascii_lowercase();
    let mut bytes = input;
    match mode.as_str() {
        "stamp" => {
            if let Some(stamp) = perforation {
                let image = decode(stamp)?;
                if page_ids.len() > 1 {
                    let height = mm(config.perforation_height_mm);
                    let full_width = height * (image.width() as f32 / image.height() as f32);
                    for (index, &page_id) in page_ids.iter().enumerate() {
                        let group_start = index / 10 * 10;
                        let group_count = (page_ids.len() - group_start).min(10);
                        let (start, width) =
                            slice_range(image.width(), index - group_start, group_count, false)?;
                        if width == 0 {
                            continue;
                        }
                        let slice =
                            image::imageops::crop_imm(&image, start, 0, width, image.height())
                                .to_image();
                        let display_width = full_width / group_count as f32;
                        let (pw, ph) = dimensions[index];
                        stamp_content(
                            &mut doc,
                            page_id,
                            &slice,
                            [
                                pw - display_width,
                                (ph - height) / 2.,
                                display_width,
                                height,
                            ],
                        )?;
                    }
                    bytes = spool::replace(bytes, spool::save_document(&directory, &mut doc)?)?;
                }
            }
            if !has_functions {
                return Ok(bytes);
            }
            drop(doc);
            let key = Identity::load(config)?;
            append_functions(bytes, functions, &dimensions, config, &key)
        }
        "sign" => {
            drop(doc);
            let key = Identity::load(config)?;
            if has_functions || front.is_some() {
                bytes = append_front(bytes, front, &dimensions, config, &key)?;
                append_functions(bytes, functions, &dimensions, config, &key)
            } else {
                sign_revision(
                    bytes,
                    &[signature_widget(0, None, [0.; 4])],
                    config.signature_reserved_size,
                    &key,
                )
            }
        }
        "custom" => {
            if !has_functions && front.is_none() && perforation.is_none() {
                return Ok(bytes);
            }
            drop(doc);
            let key = Identity::load(config)?;
            bytes = append_front(bytes, front, &dimensions, config, &key)?;
            bytes = append_functions(bytes, functions, &dimensions, config, &key)?;
            if let Some(stamp) = perforation {
                let image = decode(stamp)?;
                for index in 0..dimensions.len() {
                    let widget = perforation_widget(&image, index, &dimensions, config)?;
                    bytes = sign_revision(bytes, &[widget], 9472, &key)?;
                }
            }
            Ok(bytes)
        }
        _ => Ok(bytes), // Java has no fallback mode: unknown or blank values only run preprocessing.
    }
}
fn append_front(
    mut bytes: PathBuf,
    image: Option<&[u8]>,
    dimensions: &[(f32, f32)],
    config: &Config,
    key: &Identity,
) -> Result<PathBuf> {
    let Some(image) = image else { return Ok(bytes) };
    // ImageIO returning null in the Java visual-template path produces a blank appearance.
    let image = decode_optional(image)?;
    for page in [0, 2] {
        if let Some(&(width, height)) = dimensions.get(page) {
            let widget =
                signature_widget(page, image.clone(), front_rect(width, height, page, config));
            bytes = sign_revision(bytes, &[widget], config.signature_reserved_size, key)?;
        }
    }
    Ok(bytes)
}
fn append_functions(
    mut bytes: PathBuf,
    functions: &[&[u8]],
    dimensions: &[(f32, f32)],
    config: &Config,
    key: &Identity,
) -> Result<PathBuf> {
    let height = mm(20.);
    let margin = mm(config.function_left_mm);
    let mut x = margin;
    for stamp in functions {
        if stamp.is_empty() {
            continue;
        }
        let Some(image) = decode_optional(stamp)? else {
            continue;
        };
        let &(page_width, page_height) = dimensions
            .first()
            .ok_or_else(|| Error::input("PDF_SIGNATURE_REQUIRES_PAGE"))?;
        let width = height * (image.width() as f32 / image.height() as f32);
        if x + width > page_width - margin {
            break;
        }
        let y = page_height - height - mm(config.function_top_mm);
        bytes = sign_revision(
            bytes,
            &[signature_widget(0, Some(image), [x, y, width, height])],
            9472,
            key,
        )?;
        x += width + 10.;
    }
    Ok(bytes)
}
fn perforation_widget(
    image: &RgbaImage,
    index: usize,
    dimensions: &[(f32, f32)],
    config: &Config,
) -> Result<Widget> {
    let (start, width) = slice_range(image.width(), index, dimensions.len(), true)?;
    let slice = image::imageops::crop_imm(image, start, 0, width, image.height()).to_image();
    let height = mm(config.perforation_height_mm);
    let display_width =
        height * (image.width() as f32 / image.height() as f32) / dimensions.len() as f32;
    let (pw, ph) = dimensions[index];
    Ok(Widget {
        page: index,
        image: Some(slice),
        rect: [
            pw - display_width,
            (ph - height) / 2.,
            display_width,
            height,
        ],
        multistate: true,
        flags: 132,
        appearance_state: true,
    })
}
pub fn reject_signed_input(doc: &Document) -> Result<()> {
    lims_render::media::reject_signed_input(doc).map_err(Error)
}
