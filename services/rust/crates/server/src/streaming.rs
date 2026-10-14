//! Bounded JSON/base64 response encoder for disk-spooled legacy PDFs.
use axum::{
    body::Body,
    http::{header, Response},
};
use base64::{engine::general_purpose::STANDARD, Engine};
use bytes::Bytes;
use lims_core::{ServiceError, ServiceResult};
use serde_json::Value;
use tokio::io::AsyncReadExt;

struct StreamState<G> {
    file: tokio::fs::File,
    carry: Vec<u8>,
    suffix: Vec<u8>,
    stage: u8,
    _guard: G,
}
/// `guard` owns the temporary PDF directory, keeping it alive through response
/// completion and deleting it on disconnect. At most 48 KiB plus base64 output
/// and a two-byte remainder are allocated by the encoder, independent of PDF size.
pub fn legacy_json<G: Send + 'static>(
    file: std::fs::File,
    cover: Value,
    guard: G,
) -> ServiceResult<Response<Body>> {
    let mut suffix = b"\"".to_vec();
    if !cover.is_null() {
        suffix.extend_from_slice(b",\"cover_fields\":");
        suffix.extend(
            serde_json::to_vec(&cover)
                .map_err(|_| ServiceError::internal("PDF_RESPONSE_SERIALIZATION_FAILED"))?,
        );
    }
    suffix.push(b'}');
    let state = StreamState {
        file: tokio::fs::File::from_std(file),
        carry: Vec::with_capacity(2),
        suffix,
        stage: 0,
        _guard: guard,
    };
    let stream = futures_util::stream::try_unfold(state, |mut state| async move {
        let output = match state.stage {
            0 => {
                state.stage = 1;
                Bytes::from_static(b"{\"success\":true,\"pdf_base64\":\"")
            }
            1 => {
                let mut chunk = vec![0u8; 48 * 1024];
                let count = state.file.read(&mut chunk).await?;
                chunk.truncate(count);
                let mut input = std::mem::take(&mut state.carry);
                input.extend_from_slice(&chunk);
                if count == 0 {
                    state.stage = 2;
                    Bytes::from(STANDARD.encode(&input))
                } else {
                    let complete = input.len() - input.len() % 3;
                    state.carry.extend_from_slice(&input[complete..]);
                    Bytes::from(STANDARD.encode(&input[..complete]))
                }
            }
            2 => {
                state.stage = 3;
                Bytes::from(std::mem::take(&mut state.suffix))
            }
            _ => return Ok::<_, std::io::Error>(None),
        };
        Ok(Some((output, state)))
    });
    Response::builder()
        .status(200)
        .header(header::CONTENT_TYPE, "application/json")
        .body(Body::from_stream(stream))
        .map_err(|_| ServiceError::internal("PDF_RESPONSE_FAILED"))
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::to_bytes;
    use std::{
        io::{Seek, Write},
        sync::{
            atomic::{AtomicBool, Ordering},
            Arc,
        },
    };
    struct Guard(Arc<AtomicBool>);
    impl Drop for Guard {
        fn drop(&mut self) {
            self.0.store(true, Ordering::SeqCst)
        }
    }
    #[tokio::test]
    async fn exact_base64_all_remainders_and_tempfile_lifetime() {
        for size in [0, 1, 2, 3, 49_151, 49_152, 49_153, 100_000] {
            let input = (0..size).map(|n| (n % 251) as u8).collect::<Vec<_>>();
            let mut file = tempfile::tempfile().unwrap();
            file.write_all(&input).unwrap();
            file.rewind().unwrap();
            let dropped = Arc::new(AtomicBool::new(false));
            let response = legacy_json(
                file,
                serde_json::json!({"report_number":"SYNTHETIC-001"}),
                Guard(dropped.clone()),
            )
            .unwrap();
            assert!(!dropped.load(Ordering::SeqCst));
            let bytes = to_bytes(response.into_body(), 200_000).await.unwrap();
            assert!(dropped.load(Ordering::SeqCst));
            let value: Value = serde_json::from_slice(&bytes).unwrap();
            assert_eq!(
                STANDARD
                    .decode(value["pdf_base64"].as_str().unwrap())
                    .unwrap(),
                input
            );
            assert_eq!(value["success"], true);
            assert_eq!(value["cover_fields"]["report_number"], "SYNTHETIC-001");
        }
    }
    #[tokio::test]
    async fn disconnect_releases_spool_guard() {
        let dropped = Arc::new(AtomicBool::new(false));
        let response = legacy_json(
            tempfile::tempfile().unwrap(),
            Value::Null,
            Guard(dropped.clone()),
        )
        .unwrap();
        drop(response);
        assert!(dropped.load(Ordering::SeqCst));
    }
}
