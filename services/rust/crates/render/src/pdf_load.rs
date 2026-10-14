//! Compatibility recovery for small, single-revision classic text PDFs.
//! This does not participate in modern/incremental signature processing.
use anyhow::{Context, Result, ensure};
use lopdf::{Document, LoadOptions, Object, content::Content};
use regex::bytes::Regex;
use std::collections::BTreeMap;

pub(crate) fn load_legacy(pdf: &[u8], options: LoadOptions) -> Result<Document> {
    match Document::load_mem_with_options(pdf, options.clone()) {
        Ok(doc) if content_references_resolve(&doc) => Ok(doc),
        _ => {
            let recovered = repair_classic_text_pdf(pdf)
                .context("PDF could not be loaded or safely repaired")?;
            let doc = Document::load_mem_with_options(&recovered, options)?;
            ensure!(
                content_references_resolve(&doc),
                "Repaired PDF still has invalid content references"
            );
            Ok(doc)
        }
    }
}
fn content_references_resolve(doc: &Document) -> bool {
    doc.catalog().is_ok()
        && doc.get_pages().values().all(|id| {
            doc.get_page_contents(*id).iter().all(|id| {
                doc.get_object(*id)
                    .is_ok_and(|o| matches!(o, Object::Stream(_)))
            })
        })
}
/// Only offsets and unfiltered content Lengths are rebuilt. Every numbered object
/// and the original trailer dictionary is retained. Ambiguous, binary, encrypted,
/// object-stream and incremental inputs are rejected rather than guessed.
fn repair_classic_text_pdf(pdf: &[u8]) -> Result<Vec<u8>> {
    ensure!(
        pdf.len() <= 1024 * 1024 && pdf.starts_with(b"%PDF-") && pdf.iter().all(|b| b.is_ascii()),
        "Not a bounded classic text PDF"
    );
    let source = std::str::from_utf8(pdf)?;
    ensure!(
        !source.contains("/Prev")
            && !source.contains("/XRefStm")
            && !source.contains("/ObjStm")
            && !source.contains("/Encrypt")
            && !source.contains('#'),
        "Complex PDF repair is unsupported"
    );
    let starts = Regex::new(r"(?m)^(\d+) +(\d+) +obj[\t \r]*\n")?;
    let matches: Vec<_> = starts.captures_iter(pdf).collect();
    ensure!(
        !matches.is_empty() && matches.len() < 10000,
        "No bounded object sequence"
    );
    let trailer_at = source.rfind("trailer").context("Classic trailer missing")?;
    ensure!(
        source[..trailer_at].matches("trailer").count() == 0,
        "Multiple trailers are not repairable"
    );
    let trailer_end = source[trailer_at..]
        .find("startxref")
        .context("startxref missing")?
        + trailer_at;
    let trailer = source[trailer_at + 7..trailer_end].trim();
    ensure!(
        trailer.starts_with("<<") && trailer.ends_with(">>"),
        "Invalid classic trailer"
    );
    let declared_size = Regex::new(r"/Size[\t\r\n ]+(\d+)")?
        .captures(trailer.as_bytes())
        .context("Trailer size missing")?[1]
        .to_vec();
    let declared_size = std::str::from_utf8(&declared_size)?.parse::<usize>()?;
    ensure!(
        declared_size == matches.len() + 1,
        "Ambiguous object inventory"
    );
    let mut objects = BTreeMap::new();
    let stream = Regex::new(r"(?s)^(.*?\bstream\r?\n)(.*)(\r?\nendstream[\t\r\n ]*)$")?;
    let length = Regex::new(r"/Length[\t\r\n ]+\d+\b")?;
    for (i, captures) in matches.iter().enumerate() {
        let id = std::str::from_utf8(&captures[1])?.parse::<usize>()?;
        let generation = std::str::from_utf8(&captures[2])?.parse::<u16>()?;
        ensure!(
            id > 0 && id < declared_size && generation == 0 && !objects.contains_key(&id),
            "Noncontiguous or duplicate object"
        );
        let start = captures.get(0).unwrap().end();
        let next = matches
            .get(i + 1)
            .map(|c| c.get(0).unwrap().start())
            .unwrap_or(trailer_at);
        let segment = &pdf[start..next];
        let end = segment
            .windows(6)
            .rposition(|w| w == b"endobj")
            .context("Object terminator missing")?;
        let mut body = segment[..end].to_vec();
        if let Some(parts) = stream.captures(&body) {
            ensure!(
                !parts[1].windows(7).any(|w| w == b"/Filter"),
                "Compressed stream repair unsupported"
            );
            ensure!(
                length.find_iter(&parts[1]).count() == 1,
                "Ambiguous stream Length"
            );
            Content::decode_strict(&parts[2])
                .context("Unfiltered repaired stream is not valid PDF content")?;
            let header = length
                .replace(&parts[1], format!("/Length {}", parts[2].len()).as_bytes())
                .into_owned();
            let mut fixed = header;
            fixed.extend_from_slice(&parts[2]);
            fixed.extend_from_slice(&parts[3]);
            body = fixed;
        } else {
            ensure!(
                !body.windows(6).any(|w| w == b"stream"),
                "Ambiguous stream terminator"
            );
        }
        objects.insert(id, body);
    }
    ensure!(
        (1..declared_size).all(|id| objects.contains_key(&id)),
        "Incomplete classic object inventory"
    );
    let header_end = source.find('\n').context("PDF header newline missing")?;
    let mut out = pdf[..header_end + 1].to_vec();
    let mut offsets = vec![0];
    for (id, body) in objects {
        offsets.push(out.len());
        out.extend_from_slice(format!("{id} 0 obj\n").as_bytes());
        out.extend(body);
        out.extend_from_slice(b"\nendobj\n");
    }
    let xref = out.len();
    out.extend_from_slice(format!("xref\n0 {declared_size}\n0000000000 65535 f \n").as_bytes());
    for offset in offsets.into_iter().skip(1) {
        out.extend_from_slice(format!("{offset:010} 00000 n \n").as_bytes());
    }
    out.extend_from_slice(format!("trailer\n{trailer}\nstartxref\n{xref}\n%%EOF\n").as_bytes());
    Ok(out)
}
