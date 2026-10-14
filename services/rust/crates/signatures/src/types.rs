use serde::{Deserialize, Serialize};

#[derive(Debug, thiserror::Error)]
pub enum SignatureError {
    #[error("{0}")]
    InvalidInput(String),
    #[error("{0}")]
    Configuration(String),
    #[error("{0}")]
    Crypto(String),
    #[error("{0}")]
    Timestamp(String),
    #[error("{0}")]
    OutcomeUnknown(String),
    #[error("{0}")]
    Integrity(String),
    #[error("{0}")]
    Unsupported(String),
}
impl SignatureError {
    pub fn outcome_uncertain(&self) -> bool {
        matches!(self, Self::OutcomeUnknown(_))
    }
}
pub type Result<T> = std::result::Result<T, SignatureError>;
impl From<lopdf::Error> for SignatureError {
    fn from(e: lopdf::Error) -> Self {
        Self::InvalidInput(e.to_string())
    }
}
impl From<openssl::error::ErrorStack> for SignatureError {
    fn from(e: openssl::error::ErrorStack) -> Self {
        Self::Crypto(e.to_string())
    }
}
impl From<std::io::Error> for SignatureError {
    fn from(e: std::io::Error) -> Self {
        Self::Integrity(e.to_string())
    }
}
impl From<SignatureError> for lims_core::ServiceError {
    fn from(e: SignatureError) -> Self {
        let (status, code) = match &e {
            SignatureError::InvalidInput(_) => (422, "PDF_SIGNATURE_INPUT_INVALID"),
            SignatureError::Configuration(_) => (503, "PDF_SIGNING_CONFIGURATION_INVALID"),
            SignatureError::Unsupported(_) => (501, "PDF_SIGNATURE_FEATURE_MISSING"),
            SignatureError::Timestamp(_) => (502, "PDF_TIMESTAMP_FAILED"),
            SignatureError::OutcomeUnknown(_) => (502, "PDF_SIGNING_OUTCOME_UNKNOWN"),
            SignatureError::Crypto(_) => (500, "PDF_SIGNATURE_CRYPTO_FAILED"),
            SignatureError::Integrity(_) => (500, "PDF_SIGNATURE_INTEGRITY_FAILED"),
        };
        Self {
            status,
            code: code.into(),
            message: Some(e.to_string()),
        }
    }
}
#[derive(Clone, Debug, Serialize, Deserialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct NormalizedRectangle {
    pub x: String,
    pub y: String,
    pub width: String,
    pub height: String,
}
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct FieldPlan {
    pub field_name: String,
    pub page_index: i32,
    pub rectangle: NormalizedRectangle,
    #[serde(default)]
    pub deferred: bool,
}
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SignCommand {
    pub field_name: String,
    pub signature_role: String,
    pub signer_name: String,
    pub reason: Option<String>,
    pub location: Option<String>,
    pub contact: Option<String>,
}
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct PageGeometry {
    pub page_index: usize,
    pub crop_lower_left_x: String,
    pub crop_lower_left_y: String,
    pub crop_width: String,
    pub crop_height: String,
    pub rotation: i32,
    pub user_unit: String,
}
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct WidgetInspection {
    pub widget_index: usize,
    pub page_index: usize,
    pub normalized_rectangle: NormalizedRectangle,
    pub object_ref: Option<String>,
    pub appearance_object_refs: Vec<String>,
}
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct FieldInspection {
    pub field_name: String,
    pub signed: bool,
    pub self_only_lock: bool,
    pub widget_count: usize,
    pub object_ref: Option<String>,
    pub widgets: Vec<WidgetInspection>,
}
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Inspection {
    pub sha256: String,
    pub page_count: usize,
    pub encrypted: bool,
    pub signature_count: usize,
    pub doc_mdp_permission: Option<i64>,
    pub pages: Vec<PageGeometry>,
    pub fields: Vec<FieldInspection>,
}
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SignatureVerification {
    pub index: usize,
    pub signer_name: Option<String>,
    pub sub_filter: Option<String>,
    pub cms_integrity: String,
    pub certificate_trust: String,
    pub signed_revision_integrity: String,
    pub later_revision_permission: String,
    pub timestamp_trust: String,
    pub document_current_state: String,
    pub error: Option<String>,
}
#[derive(Clone, Debug, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct VerificationReport {
    pub document_current_state: String,
    pub doc_mdp_permission: Option<i64>,
    pub signatures: Vec<SignatureVerification>,
    pub error: Option<String>,
}
