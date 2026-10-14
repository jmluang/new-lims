//! Durable signing ledger using the existing Laravel / Java MySQL schema.
//! No signing material is used until the private-key marker transaction commits.
pub mod configuration;
pub mod http;
pub mod model;
pub mod repository;
pub mod service;
pub mod storage;
pub use configuration::database_connect_options;
pub use http::*;
pub use model::*;
pub use repository::ExecutionRepository;
pub use service::*;
pub use storage::*;

pub fn sha256(bytes: &[u8]) -> String {
    use sha2::Digest;
    hex::encode(sha2::Sha256::digest(bytes))
}

#[derive(Debug, thiserror::Error)]
pub enum ExecutionError {
    #[error("Execution gate rejected: {0}")]
    Gate(String),
    #[error("Execution ledger is not fully configured")]
    NotReady,
    #[error("Execution database configuration rejected: {0}")]
    DatabaseConfiguration(&'static str),
    #[error("Execution does not exist")]
    NotFound,
    #[error("Execution result is not available")]
    Unavailable,
    #[error("Execution result bytes are missing")]
    Missing,
    #[error("Execution result integrity breach: {0}")]
    Breached(String),
    #[error("Invalid execution input: {0}")]
    Invalid(String),
    #[error("Execution service is shutting down")]
    ShuttingDown,
    #[error("PDF work limiter is busy")]
    Busy,
    #[error("Signing outcome is uncertain: {0}")]
    OutcomeUncertain(String),
    #[error("Signing failed: {0}")]
    Signing(String),
    #[error("Generated revision failed layered PAdES-B-T verification")]
    GeneratedVerification,
    #[error(transparent)]
    Database(#[from] sqlx::Error),
    #[error(transparent)]
    Io(#[from] std::io::Error),
    #[error(transparent)]
    Json(#[from] serde_json::Error),
}
pub type Result<T> = std::result::Result<T, ExecutionError>;
pub(crate) fn gate(msg: impl Into<String>) -> ExecutionError {
    ExecutionError::Gate(msg.into())
}
