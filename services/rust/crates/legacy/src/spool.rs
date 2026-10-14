//! Private request-scoped files. A failed request or disconnected response drops all files.
use crate::{Config, Error, Result};
use lopdf::{Dictionary, Document, Object};
use std::{
    fs::{File, OpenOptions},
    io::{Read, Seek, SeekFrom, Write},
    path::{Path, PathBuf},
};

pub struct FileProcessResult {
    pub pdf_file: PathBuf,
    pub cover_fields: serde_json::Value,
    pub(crate) directory: tempfile::TempDir,
}
impl FileProcessResult {
    pub fn temporary_directory(&self) -> &Path {
        self.directory.path()
    }
}
pub(crate) fn directory(config: &Config) -> Result<tempfile::TempDir> {
    let mut builder = tempfile::Builder::new();
    builder.prefix("lims-pdf-operation-");
    match &config.temporary_directory {
        Some(path) => builder.tempdir_in(path),
        None => builder.tempdir(),
    }
    .map_err(|_| Error::internal("PDF_TEMPORARY_STORAGE_FAILED"))
}
pub(crate) fn create(directory: &Path) -> Result<(File, PathBuf)> {
    tempfile::Builder::new()
        .prefix("revision-")
        .suffix(".pdf")
        .tempfile_in(directory)
        .and_then(|file| file.keep().map_err(|error| error.error))
        .map_err(|_| Error::internal("PDF_TEMPORARY_STORAGE_FAILED"))
}
pub(crate) fn write_bytes(directory: &Path, bytes: &[u8]) -> Result<PathBuf> {
    let (mut file, path) = create(directory)?;
    file.write_all(bytes).map_err(io)?;
    Ok(path)
}
pub(crate) fn save_document(directory: &Path, doc: &mut Document) -> Result<PathBuf> {
    let (mut file, path) = create(directory)?;
    doc.save_to(&mut file).map_err(io)?;
    Ok(path)
}
pub(crate) fn replace(previous: PathBuf, next: PathBuf) -> Result<PathBuf> {
    if previous != next {
        std::fs::remove_file(previous).map_err(io)?;
    }
    Ok(next)
}
pub(crate) fn io(_: std::io::Error) -> Error {
    Error::internal("PDF_TEMPORARY_STORAGE_FAILED")
}

fn signing_object_filter(
    id: lopdf::ObjectId,
    obj: &mut Object,
) -> Option<(lopdf::ObjectId, Object)> {
    if let Object::Stream(stream) = obj
        && !stream.dict.has_type(b"ObjStm")
    {
        return None;
    }
    if let Ok(dict) = obj.as_dict_mut()
        && dict.get(b"Type").and_then(Object::as_name).ok() == Some(b"Sig")
    {
        dict.remove(b"Contents");
    }
    Some((id, obj.clone()))
}
/// The mapping supplies the immutable previous bytes; old page/image streams and CMS Contents
/// are not retained because the new revision refers to them, rather than rewriting them.
pub(crate) fn load_for_signing(path: &Path) -> Result<Document> {
    let file = File::open(path).map_err(io)?;
    // SAFETY: this is our private request-spool file. It is immutable until this mapping drops.
    let map = unsafe { memmap2::MmapOptions::new().map(&file) }.map_err(io)?;
    let mut document = Document::load_mem_with_options(
        &map,
        lopdf::LoadOptions {
            filter: Some(signing_object_filter),
            max_decompressed_size: Some(64 * 1024 * 1024),
            ..Default::default()
        },
    )
    .map_err(Error::pdf)?;
    document
        .objects
        .retain(|_, object| !matches!(object, Object::Stream(_)));
    Ok(document)
}
/// Standard PDF object writer for the new revision only. Existing object bytes are copied
/// unchanged. Classic xref offsets are absolute and Prev targets the parsed prior revision.
fn object<W: Write>(out: &mut W, obj: &Object) -> std::io::Result<()> {
    match obj {
        Object::Null => out.write_all(b"null"),
        Object::Boolean(v) => out.write_all(if *v { b"true" } else { b"false" }),
        Object::Integer(v) => write!(out, "{v}"),
        Object::Real(v) => write!(out, "{v}"),
        Object::Name(name) => {
            out.write_all(b"/")?;
            for byte in name {
                if !(33..=126).contains(byte) || b"()<>[]{}/%#".contains(byte) {
                    write!(out, "#{byte:02X}")?;
                } else {
                    out.write_all(&[*byte])?;
                }
            }
            Ok(())
        }
        Object::String(bytes, _) => {
            out.write_all(b"<")?;
            let mut buf = [0u8; 8192];
            const HEX: &[u8] = b"0123456789ABCDEF";
            for chunk in bytes.chunks(buf.len() / 2) {
                for (i, byte) in chunk.iter().enumerate() {
                    buf[2 * i] = HEX[(byte >> 4) as usize];
                    buf[2 * i + 1] = HEX[(byte & 15) as usize];
                }
                out.write_all(&buf[..chunk.len() * 2])?;
            }
            out.write_all(b">")
        }
        Object::Reference((id, generation)) => write!(out, "{id} {generation} R"),
        Object::Array(values) => {
            out.write_all(b"[")?;
            for (i, value) in values.iter().enumerate() {
                if i > 0 {
                    out.write_all(b" ")?;
                }
                object(out, value)?;
            }
            out.write_all(b"]")
        }
        Object::Dictionary(dict) => dictionary(out, dict),
        Object::Stream(stream) => {
            let mut dict = stream.dict.clone();
            dict.set("Length", stream.content.len() as i64);
            dictionary(out, &dict)?;
            out.write_all(b"\nstream\n")?;
            out.write_all(&stream.content)?;
            out.write_all(b"\nendstream")
        }
    }
}
fn dictionary<W: Write>(out: &mut W, dict: &Dictionary) -> std::io::Result<()> {
    out.write_all(b"<<")?;
    for (key, value) in dict {
        object(out, &Object::Name(key.clone()))?;
        out.write_all(b" ")?;
        object(out, value)?;
    }
    out.write_all(b">>")
}
pub(crate) fn append_revision(
    previous: &Path,
    directory: &Path,
    doc: &Document,
) -> Result<(PathBuf, u64)> {
    let (file, path) = create(directory)?;
    let mut out = std::io::BufWriter::with_capacity(64 * 1024, file);
    std::io::copy(&mut File::open(previous).map_err(io)?, &mut out).map_err(io)?;
    out.write_all(b"\n").map_err(io)?;
    let mut offsets = Vec::new();
    for (&id, value) in &doc.objects {
        offsets.push((id, out.stream_position().map_err(io)?));
        writeln!(&mut out, "{} {} obj", id.0, id.1).map_err(io)?;
        object(&mut out, value).map_err(io)?;
        out.write_all(b"\nendobj\n").map_err(io)?;
    }
    let xref = out.stream_position().map_err(io)?;
    out.write_all(b"xref\n").map_err(io)?;
    for ((id, generation), offset) in offsets {
        if offset > 9_999_999_999 {
            return Err(Error::internal("PDF_XREF_OFFSET_OVERFLOW"));
        }
        write!(&mut out, "{id} 1\n{offset:010} {generation:05} n \n").map_err(io)?;
    }
    let mut trailer = doc.trailer.clone();
    for key in [
        b"Type".as_slice(),
        b"W",
        b"Index",
        b"Length",
        b"Filter",
        b"DecodeParms",
        b"XRefStm",
    ] {
        trailer.remove(key);
    }
    trailer.set("Size", doc.max_id as i64 + 1);
    out.write_all(b"trailer\n").map_err(io)?;
    dictionary(&mut out, &trailer).map_err(io)?;
    write!(&mut out, "\nstartxref\n{xref}\n%%EOF\n").map_err(io)?;
    out.flush().map_err(io)?;
    Ok((path, xref))
}
pub(crate) fn find(file: &mut File, start: u64, needle: &[u8]) -> Result<u64> {
    file.seek(SeekFrom::Start(start)).map_err(io)?;
    let mut absolute = start;
    let mut retained = Vec::new();
    let mut chunk = [0u8; 65536];
    loop {
        let len = file.read(&mut chunk).map_err(io)?;
        if len == 0 {
            return Err(Error::internal("PDF_SIGNATURE_PLACEHOLDER_MISSING"));
        }
        retained.extend_from_slice(&chunk[..len]);
        if let Some(offset) = retained
            .windows(needle.len())
            .position(|bytes| bytes == needle)
        {
            return Ok(absolute + offset as u64);
        }
        let keep = (needle.len() - 1).min(retained.len());
        let remove = retained.len() - keep;
        retained.drain(..remove);
        absolute += remove as u64;
    }
}
pub(crate) fn open_update(path: &Path) -> Result<File> {
    OpenOptions::new()
        .read(true)
        .write(true)
        .open(path)
        .map_err(io)
}
