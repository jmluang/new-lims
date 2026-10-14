#![allow(dead_code)]
use chrono::Utc;
use lims_execution::*;
use uuid::Uuid;
pub const PDF: &[u8] = b"%PDF-1.7 local execution lifecycle fixture; not a cryptographic test";
pub const APPEARANCE: &[u8] = b"local appearance placeholder; signer is explicitly fake";
pub fn claim() -> OperationClaim {
    OperationClaim {
        operation_uuid: Uuid::new_v4(),
        java_gate_version: 0,
        lease_epoch: 7,
        operation_input_manifest_hash: "0".repeat(64),
        input_fingerprint: "1".repeat(64),
        expected_source_sha256: sha256(PDF),
        policy_version_id: 1,
        policy_version_uuid: Uuid::parse_str("12345678-1234-4234-8234-123456789abc").unwrap(),
        policy_hash: "b".repeat(64),
        config_bundle_hash: "c".repeat(64),
        appearance_manifest_hash: "d".repeat(64),
        appearance_sha256: sha256(APPEARANCE),
        pdf_signature_role: "certification_p2".into(),
        target_field_name: "lims_inspector_g1".into(),
        expected_certificate_fingerprint: "f".repeat(64),
        field_lock_policy_hash: "8".repeat(64),
    }
}
pub fn policy() -> PolicySnapshot {
    let c = claim();
    PolicySnapshot {
        id: 1,
        version_uuid: c.policy_version_uuid,
        policy_hash: c.policy_hash,
        config_bundle_hash: c.config_bundle_hash,
        execution_timeout_seconds: 90,
        maximum_attempts: 3,
        retry_backoff_seconds: vec![1, 2],
        retryable_error_codes: ["PDF_BUSY".into()].into_iter().collect(),
        maximum_generated_revision_bytes: 32 * 1024 * 1024,
        maximum_signature_increment_bytes: 2 * 1024 * 1024,
    }
}
pub fn command() -> serde_json::Value {
    serde_json::json!({"fieldName":"lims_inspector_g1","signatureRole":"certification_p2","signerName":"Local test"})
}
pub fn record(c: &OperationClaim) -> ExecutionRecord {
    ExecutionRecord {
        operation_uuid: c.operation_uuid,
        operation_input_manifest_hash: c.operation_input_manifest_hash.clone(),
        input_fingerprint: c.input_fingerprint.clone(),
        policy_hash: c.policy_hash.clone(),
        attempt_number: 0,
        attempt_count: 0,
        max_attempts: 3,
        state: "claimed".into(),
        retryability: None,
        authorized_lease_epoch: c.lease_epoch,
        lock_version: 0,
        claimed_at: Some(Utc::now()),
        execution_started_at: None,
        private_key_started_at: None,
        execution_deadline_at: None,
        next_retry_at: None,
        retry_exhausted_at: None,
        completed_at: None,
        terminal_at: None,
        error_code: None,
        result_path: None,
        result_sha256: None,
        result_size: 0,
        result_file_key: None,
        validation_report_hash: None,
        result_integrity_state: "not_applicable".into(),
        result_last_verified_at: None,
        result_integrity_error_code: None,
        retention_until: None,
        retirement_phase: "none".into(),
        retirement_epoch: 0,
        retirement_staged_path: None,
        retirement_started_at: None,
        retirement_purge_not_before: None,
        evidence_hold_mask: 0,
        evidence_hold_state: "none".into(),
        legal_hold_until: None,
        bytes_deleted_at: None,
    }
}
pub fn stored_record(c: &OperationClaim, s: &StoredResult) -> ExecutionRecord {
    let mut r = record(c);
    r.state = "completed".into();
    r.result_path = Some(s.path.clone());
    r.result_sha256 = Some(s.sha256.clone());
    r.result_size = s.size;
    r.result_file_key = Some(s.file_key.clone());
    r.result_integrity_state = "available".into();
    r
}
