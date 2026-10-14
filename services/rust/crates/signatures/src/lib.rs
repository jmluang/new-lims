//! Modern, pre-created PDF field signing. All mutations are in-memory; execution
//! leases and the durable private-key boundary belong to the execution crate.
mod appearance;
mod cms;
mod pdf;
mod permissions;
mod service;
mod types;
pub use cms::{
    DocumentIdentity, HttpTimestampTransport, Rfc3161Client, TimestampClient, TimestampTransport,
    TrustConfig,
};
pub use pdf::{finalize_unsigned, inspect, prepare_fields, validate_sign_target};
pub use service::{verify, PreparedSigning, SignatureService};
pub use types::*;

pub fn sha256_hex(bytes: &[u8]) -> String {
    use sha2::Digest;
    hex::encode(sha2::Sha256::digest(bytes))
}

#[cfg(test)]
mod tests;
