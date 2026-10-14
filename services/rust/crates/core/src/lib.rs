//! Transport-neutral contracts shared by the Rust service modules.
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::collections::BTreeMap;

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub struct ServiceError {
    pub status: u16,
    pub code: String,
    pub message: Option<String>,
}
impl ServiceError {
    pub fn new(status: u16, code: impl Into<String>) -> Self {
        Self {
            status,
            code: code.into(),
            message: None,
        }
    }
    pub fn bad_request(code: impl Into<String>) -> Self {
        Self::new(400, code)
    }
    pub fn unprocessable(code: impl Into<String>) -> Self {
        Self::new(422, code)
    }
    pub fn internal(code: impl Into<String>) -> Self {
        Self::new(500, code)
    }
    pub fn unavailable(code: impl Into<String>) -> Self {
        Self::new(503, code)
    }
    /// Deliberate, visible gap. Never returns fabricated success.
    pub fn missing(feature: impl Into<String>) -> Self {
        Self {
            status: 501,
            code: "PDF_FEATURE_NOT_IMPLEMENTED".into(),
            message: Some(feature.into()),
        }
    }
}
impl std::fmt::Display for ServiceError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "{}", self.code)
    }
}
impl std::error::Error for ServiceError {}
pub type ServiceResult<T> = Result<T, ServiceError>;

#[derive(Debug, Clone, Default)]
pub struct MultipartData {
    pub fields: BTreeMap<String, String>,
    pub files: BTreeMap<String, FilePart>,
}
#[derive(Debug, Clone)]
pub struct FilePart {
    pub name: String,
    pub filename: Option<String>,
    pub content_type: String,
    pub bytes: Vec<u8>,
}
impl MultipartData {
    pub fn file(&self, name: &str) -> ServiceResult<&[u8]> {
        self.files
            .get(name)
            .map(|p| p.bytes.as_slice())
            .ok_or_else(|| ServiceError::bad_request("PDF_MULTIPART_REQUIRED"))
    }
    pub fn field(&self, name: &str) -> ServiceResult<&str> {
        self.fields
            .get(name)
            .map(String::as_str)
            .ok_or_else(|| ServiceError::bad_request("PDF_MULTIPART_REQUIRED"))
    }
}
pub fn sha256(bytes: &[u8]) -> String {
    hex::encode(Sha256::digest(bytes))
}
