//! Spring ByteArrayResource compatibility for prepare/finalize only.
//! The streaming execution result and servlet-written reports do not use this.
use axum::{
    body::Body,
    http::{header, HeaderValue, Response},
};
use bytes::Bytes;
use lims_core::{ServiceError, ServiceResult};
use std::{convert::Infallible, ops::Range};

pub(crate) fn response(pdf: Vec<u8>, range: Option<&HeaderValue>) -> ServiceResult<Response<Body>> {
    let size = pdf.len();
    let digest = lims_core::sha256(&pdf);
    let original = Bytes::from(pdf);
    let builder = Response::builder()
        .header(
            header::CONTENT_DISPOSITION,
            "attachment; filename=revision.pdf",
        )
        .header("x-pdf-sha256", digest)
        .header(header::ACCEPT_RANGES, "bytes");
    let response_failed = |_| ServiceError::internal("PDF_RESPONSE_FAILED");
    let Some(range) = range else {
        return builder
            .status(200)
            .header(header::CONTENT_TYPE, "application/pdf")
            .header(header::CONTENT_LENGTH, size)
            .body(Body::from(original))
            .map_err(response_failed);
    };
    let parsed = range
        .to_str()
        .map_err(|_| ())
        .and_then(|raw| parse(raw, size));
    let ranges = match parsed {
        Ok(ranges) => ranges,
        Err(()) => {
            // Observed Java 416 retains the complete PDF, including its hash.
            return builder
                .status(416)
                .header(header::CONTENT_TYPE, "application/pdf")
                .header(header::CONTENT_RANGE, format!("bytes */{size}"))
                .header(header::CONTENT_LENGTH, size)
                .body(Body::from(original))
                .map_err(response_failed);
        }
    };
    if ranges.len() == 1 {
        let region = ranges[0].clone();
        return builder
            .status(206)
            .header(header::CONTENT_TYPE, "application/pdf")
            .header(
                header::CONTENT_RANGE,
                format!("bytes {}-{}/{size}", region.start, region.end - 1),
            )
            .header(header::CONTENT_LENGTH, region.len())
            .body(Body::from(original.slice(region)))
            .map_err(response_failed);
    }
    if ranges.is_empty() {
        return builder
            .status(206)
            .header(header::CONTENT_TYPE, "application/pdf")
            .header(header::CONTENT_LENGTH, 0)
            .body(Body::empty())
            .map_err(response_failed);
    }
    let boundary = format!("lims-{}", uuid::Uuid::new_v4().simple());
    let mut pieces = Vec::with_capacity(ranges.len() * 2 + 1);
    for region in ranges {
        pieces.push(Bytes::from(format!("\r\n--{boundary}\r\nContent-Type: application/pdf\r\nContent-Range: bytes {}-{}/{size}\r\n\r\n", region.start, region.end - 1)));
        // Shared immutable slices avoid duplicating the entire generated PDF.
        pieces.push(original.slice(region));
    }
    pieces.push(Bytes::from(format!("\r\n--{boundary}--")));
    let length = pieces
        .iter()
        .try_fold(0usize, |total, part| total.checked_add(part.len()))
        .ok_or_else(|| ServiceError::internal("PDF_RESPONSE_FAILED"))?;
    // Approved correctness exception: Java preserves the full PDF's length for
    // multipart regions, producing a truncated HTTP entity. Report actual bytes.
    builder
        .status(206)
        .header(
            header::CONTENT_TYPE,
            format!("multipart/byteranges; boundary={boundary}"),
        )
        .header(header::CONTENT_LENGTH, length)
        .body(Body::from_stream(futures_util::stream::iter(
            pieces.into_iter().map(Ok::<_, Infallible>),
        )))
        .map_err(response_failed)
}

fn parse(raw: &str, size: usize) -> Result<Vec<Range<usize>>, ()> {
    if raw.is_empty() {
        return Ok(Vec::new());
    }
    let specification = raw.strip_prefix("bytes=").ok_or(())?;
    // Spring StringUtils tokenization trims <= U+0020 and discards empty tokens.
    let tokens: Vec<_> = specification
        .split(',')
        .map(|s| s.trim_matches(|c| c <= '\u{20}'))
        .filter(|s| !s.is_empty())
        .collect();
    if tokens.len() > 100 {
        return Err(());
    }
    let integer = |s: &str| {
        s.parse::<i64>()
            .ok()
            .and_then(|v| usize::try_from(v).ok())
            .ok_or(())
    };
    let mut regions = Vec::with_capacity(tokens.len());
    for token in tokens {
        if size == 0 {
            return Err(());
        }
        let (first, last) = token.split_once('-').ok_or(())?;
        let region = if first.is_empty() {
            let suffix = integer(last)?;
            if suffix == 0 {
                return Err(());
            }
            size.saturating_sub(suffix)..size
        } else {
            let start = integer(first)?;
            let end = if last.is_empty() {
                size - 1
            } else {
                let requested = integer(last)?;
                if requested < start {
                    return Err(());
                }
                requested.min(size - 1)
            };
            if start >= size {
                return Err(());
            }
            start..end + 1
        };
        regions.push(region);
    }
    if regions.len() > 1 {
        let total = regions
            .iter()
            .try_fold(0usize, |n, r| n.checked_add(r.len()))
            .ok_or(())?;
        // Actual Spring HttpRange guard, including overlapping range lengths.
        if total >= size {
            return Err(());
        }
    }
    Ok(regions)
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn ordinary_java_regions_and_limit() {
        for (raw, start, end) in [
            ("bytes=0-15", 0, 16),
            ("bytes=16-", 16, 798),
            ("bytes=-16", 782, 798),
        ] {
            let regions = parse(raw, 798).unwrap();
            assert_eq!(regions.len(), 1);
            assert_eq!(regions[0], start..end);
        }
        assert_eq!(parse("bytes=0-15,32-47", 798), Ok(vec![0..16, 32..48]));
        for bad in [
            "bytes=999999-1000015",
            "bytes=16-0",
            "bytes=-0",
            "items=0-1",
            "bytes=0-999,0-999",
        ] {
            assert!(parse(bad, 798).is_err(), "{bad}");
        }
        assert!(parse(&format!("bytes={}", vec!["0-0"; 101].join(",")), 798).is_err());
    }
    #[tokio::test]
    async fn normal_and_single_keep_full_document_digest() {
        let pdf = b"0123456789".to_vec();
        for (range, status, expected) in [
            (None, 200, b"0123456789".as_slice()),
            (Some("bytes=2-4"), 206, b"234".as_slice()),
            (Some("bytes=9-0"), 416, b"0123456789".as_slice()),
        ] {
            let header = range.map(HeaderValue::from_static);
            let response = response(pdf.clone(), header.as_ref()).unwrap();
            assert_eq!(response.status(), status);
            assert_eq!(response.headers()["x-pdf-sha256"], lims_core::sha256(&pdf));
            assert_eq!(response.headers()[header::ACCEPT_RANGES], "bytes");
            assert_eq!(
                response.headers()[header::CONTENT_LENGTH],
                expected.len().to_string()
            );
            assert_eq!(
                axum::body::to_bytes(response.into_body(), 1000)
                    .await
                    .unwrap(),
                expected
            );
        }
    }
    #[tokio::test]
    async fn multipart_has_exact_length_and_shared_payload_slices() {
        let pdf = b"0123456789".to_vec();
        let response = response(pdf, Some(&HeaderValue::from_static("bytes=0-1,8-9"))).unwrap();
        let length = response.headers()[header::CONTENT_LENGTH]
            .to_str()
            .unwrap()
            .parse::<usize>()
            .unwrap();
        let content_type = response.headers()[header::CONTENT_TYPE]
            .to_str()
            .unwrap()
            .to_owned();
        assert!(content_type.starts_with("multipart/byteranges; boundary="));
        assert!(!response.headers().contains_key(header::CONTENT_RANGE));
        let body = axum::body::to_bytes(response.into_body(), 2000)
            .await
            .unwrap();
        assert_eq!(length, body.len());
        let text = std::str::from_utf8(&body).unwrap();
        assert!(text.contains("Content-Range: bytes 0-1/10\r\n\r\n01\r\n"));
        assert!(text.contains("Content-Range: bytes 8-9/10\r\n\r\n89\r\n"));
    }
}
