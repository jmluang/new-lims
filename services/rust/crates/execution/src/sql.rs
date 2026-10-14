// SQL ported verbatim from Java SigningExecutionRepository at 7841e29.
pub const CLAIM_0: &str = r#"UPDATE pdf_signing_operations
   SET java_gate_version = java_gate_version + 1
 WHERE operation_uuid = ?
   AND java_gate_version = ?
   AND lease_epoch = ?
   AND state = 'processing'
   AND stage IN ('java_call', 'java_polling')
   AND action = 'fill_signature_field'
   AND document_evidence_hold_mask = 0
   AND lease_expires_at > CURRENT_TIMESTAMP
   AND operation_input_manifest_hash = ?
   AND input_fingerprint = ?
   AND expected_source_sha256 = ?
   AND signing_policy_version_id = ?
   AND policy_hash = ?
   AND config_bundle_hash = ?
   AND appearance_manifest_hash = ?
   AND appearance_sha256 = ?
   AND pdf_signature_role = ?
   AND target_field_name = ?
   AND expected_certificate_fingerprint = ?
   AND field_lock_policy_hash = ?"#;

pub const CLAIM_1: &str = r#"INSERT INTO pdf_java_signing_executions (
    operation_uuid, operation_input_manifest_hash, input_fingerprint, policy_hash,
    attempt_number, attempt_count, max_attempts,
    state, authorized_lease_epoch, lock_version, claimed_at,
    result_integrity_state, retirement_phase, retirement_epoch,
    evidence_hold_mask, evidence_hold_state, created_at, updated_at
) VALUES (?, ?, ?, ?, 0, 0, ?, 'claimed', ?, 0, ?,
          'not_applicable', 'none', 0, 0, 'none', ?, ?)"#;

pub const MARK_PRIVATE_KEY_STARTED_0: &str = r#"UPDATE pdf_java_signing_executions
   SET private_key_started_at=?, lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND state='executing' AND private_key_started_at IS NULL
   AND execution_deadline_at > CURRENT_TIMESTAMP
   AND evidence_hold_mask=0 AND evidence_hold_state='none'
   AND (legal_hold_until IS NULL OR legal_hold_until<=CURRENT_TIMESTAMP)
   AND input_fingerprint=? AND policy_hash=? AND authorized_lease_epoch=?"#;

pub const MARK_COMPLETED_0: &str = r#"UPDATE pdf_java_signing_executions
   SET state='completed', completed_at=?, terminal_at=?, result_path=?, result_sha256=?,
       result_size=?, result_file_key=?, result_integrity_state='available',
       validation_report_hash=?, retention_until=?,
       lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND state='executing' AND private_key_started_at IS NOT NULL
   AND input_fingerprint=? AND policy_hash=? AND lock_version=?"#;

pub const MARK_FAILURE_0: &str = r#"UPDATE pdf_java_signing_executions
   SET state=?, retryability=?, error_code=?, terminal_at=?,
       next_retry_at=NULL,
       retry_exhausted_at=CASE WHEN ?='failed_before_private_key'
           AND attempt_count >= max_attempts THEN ? ELSE retry_exhausted_at END,
       lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND state IN ('claimed', 'executing')
   AND input_fingerprint=? AND policy_hash=? AND lock_version=?"#;

pub const MARK_PRE_PRIVATE_KEY_FAILURE_0: &str = r#"UPDATE pdf_java_signing_executions
   SET state='failed_before_private_key', retryability=?, error_code=?, terminal_at=?,
       next_retry_at=?, retry_exhausted_at=?, lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND state='executing' AND private_key_started_at IS NULL
   AND input_fingerprint=? AND policy_hash=? AND lock_version=?"#;

pub const AUTHORIZE_RETRY_0: &str = r#"UPDATE pdf_java_signing_executions
   SET state='claimed', authorized_lease_epoch=?, next_retry_at=NULL,
       retry_exhausted_at=NULL, error_code=NULL, terminal_at=NULL,
       lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND input_fingerprint=? AND policy_hash=?
   AND state='failed_before_private_key' AND retryability='same_operation'
   AND lock_version=? AND private_key_started_at IS NULL
   AND attempt_count < max_attempts AND next_retry_at<=CURRENT_TIMESTAMP"#;

pub const RECOVERY_MAXIMUM_BYTES_0: &str = r#"SELECT policy.generated_revision_max_bytes
  FROM pdf_java_signing_executions execution
  JOIN pdf_signing_operations operation
    ON operation.operation_uuid=execution.operation_uuid
  JOIN pdf_signing_policy_versions policy
    ON policy.id=operation.signing_policy_version_id
   AND policy.policy_hash=execution.policy_hash
 WHERE execution.operation_uuid=? AND policy.immutable_at IS NOT NULL"#;

pub const RETIREMENT_CANDIDATES_0: &str = r#"SELECT * FROM pdf_java_signing_executions
 WHERE state='completed'
   AND result_integrity_state IN ('available','retiring')
   AND retirement_phase IN ('none','stage_intent','staged','purge_intent')
 ORDER BY id
 LIMIT ?"#;

pub const BEGIN_RETIREMENT_0: &str = r#"UPDATE pdf_java_signing_executions
   SET result_integrity_state='retiring', retirement_phase='stage_intent',
       retirement_epoch=?, retirement_staged_path=?, retirement_started_at=?,
       retirement_purge_not_before=?, lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND state='completed'
   AND result_integrity_state='available' AND retirement_phase='none'
   AND lock_version=? AND evidence_hold_mask=0 AND evidence_hold_state='none'"#;

pub const APPLY_RETIREMENT_STAGE_0: &str = r#"UPDATE pdf_java_signing_executions
   SET retirement_phase='staged', lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND retirement_phase='stage_intent'
   AND retirement_epoch=? AND lock_version=?
   AND evidence_hold_mask=0 AND evidence_hold_state='none'"#;

pub const APPLY_RETIREMENT_PURGE_0: &str = r#"UPDATE pdf_java_signing_executions
   SET result_integrity_state='retired', retirement_phase='retired', bytes_deleted_at=?,
       lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND retirement_phase='purge_intent'
   AND retirement_epoch=? AND lock_version=?
   AND evidence_hold_mask=0 AND evidence_hold_state='none'"#;

pub const APPLY_RETIREMENT_RESTORE_0: &str = r#"UPDATE pdf_java_signing_executions
   SET result_integrity_state='available', retirement_phase='none',
       retirement_epoch=retirement_epoch+1, retirement_staged_path=NULL,
       retirement_started_at=NULL, retirement_purge_not_before=NULL,
       lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND retirement_epoch=?
   AND retirement_phase IN ('stage_intent','staged','purge_intent')
   AND lock_version=? AND evidence_hold_mask<>0 AND evidence_hold_state='active'"#;

pub const RETIREMENT_TRANSITION_0: &str = r#"UPDATE pdf_java_signing_executions
   SET retirement_phase=?, lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND retirement_phase=? AND retirement_epoch=?
   AND lock_version=? AND evidence_hold_mask=0 AND evidence_hold_state='none'"#;

pub const RETIREMENT_AUTHORIZATION_0: &str = r#"SELECT java_gate_version, state, result_retirement_not_before,
       result_retirement_authorization_expires_at,
       result_retirement_authorization_manifest,
       result_retirement_authorization_hash
  FROM pdf_signing_operations WHERE operation_uuid=? FOR UPDATE"#;

pub const RETIREMENT_GATE_0: &str = r#"UPDATE pdf_signing_operations SET java_gate_version=java_gate_version+1
 WHERE operation_uuid=? AND java_gate_version=? AND state='completed'
   AND result_retirement_authorization_hash=?
   AND result_retirement_not_before<=CURRENT_TIMESTAMP
   AND result_retirement_authorization_expires_at>CURRENT_TIMESTAMP"#;

pub const APPEND_RETIREMENT_EVENT_0: &str = r#"INSERT INTO pdf_java_signing_execution_events (
    operation_uuid, attempt_number, event_type, old_state, new_state,
    old_retirement_phase, new_retirement_phase, old_lock_version, new_lock_version,
    authorized_lease_epoch, retirement_epoch, error_code, event_at, event_hash
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"#;

pub const OPEN_VERIFIED_RESULT_0: &str = r#"UPDATE pdf_signing_operations
   SET java_gate_version=java_gate_version+1
 WHERE operation_uuid=? AND java_gate_version=?
   AND action='fill_signature_field'
   AND (
       state='completed'
       OR (state='manual_review' AND lease_owner IS NULL)
       OR (state IN ('processing','promoted') AND lease_epoch=?
           AND lease_owner IS NOT NULL AND lease_expires_at>CURRENT_TIMESTAMP)
   )
   AND operation_input_manifest_hash=? AND input_fingerprint=? AND policy_hash=?"#;

pub const OPEN_VERIFIED_RESULT_1: &str = r#"UPDATE pdf_java_signing_executions
   SET result_last_verified_at=?, lock_version=lock_version+1, updated_at=?
 WHERE operation_uuid=? AND state='completed' AND result_integrity_state='available'
   AND input_fingerprint=? AND policy_hash=? AND lock_version=?"#;

pub const VALIDATE_EXECUTION_READ_LEASE_0: &str = r#"SELECT state, lease_epoch, lease_owner, lease_expires_at
  FROM pdf_signing_operations
 WHERE operation_uuid=?"#;

pub const MARK_RESULT_INTEGRITY_0: &str = r#"UPDATE pdf_java_signing_executions
   SET result_integrity_state=?, result_integrity_error_code=?,
       lock_version=lock_version+1, updated_at=CURRENT_TIMESTAMP
 WHERE operation_uuid=? AND state='completed' AND result_integrity_state='available'
   AND input_fingerprint=? AND policy_hash=? AND lock_version=?"#;

pub const POLICY_0: &str = r#"SELECT id, version_uuid, policy_hash, config_bundle_hash, pades_profile,
       java_execution_timeout_seconds, pre_private_key_max_attempts,
       pre_private_key_retry_backoff_seconds, pre_private_key_retryable_error_codes,
       generated_revision_max_bytes, max_signature_increment_bytes
  FROM pdf_signing_policy_versions
 WHERE id=? AND immutable_at IS NOT NULL"#;

pub const VALIDATE_EXISTING_CLAIM_0: &str = r#"SELECT 1
  FROM pdf_signing_operations
 WHERE operation_uuid=?
   AND action='fill_signature_field'
   AND operation_input_manifest_hash=? AND input_fingerprint=?
   AND expected_source_sha256=? AND signing_policy_version_id=?
   AND policy_hash=? AND config_bundle_hash=?
   AND appearance_manifest_hash=? AND appearance_sha256=?
   AND pdf_signature_role=? AND target_field_name=?
   AND expected_certificate_fingerprint=? AND field_lock_policy_hash=?"#;

pub const SIGN_AUTHORIZE_GATE_AT_VERSION_0: &str = r#"UPDATE pdf_signing_operations
   SET java_gate_version=java_gate_version+1
 WHERE operation_uuid=? AND java_gate_version=? AND lease_epoch=?
   AND state='processing' AND stage IN ('java_call','java_polling')
   AND action='fill_signature_field' AND lease_expires_at>CURRENT_TIMESTAMP
   AND document_evidence_hold_mask=0
   AND cancellation_requested_at IS NULL
   AND operation_input_manifest_hash=? AND input_fingerprint=?
   AND expected_source_sha256=? AND signing_policy_version_id=?
   AND policy_hash=? AND config_bundle_hash=?
   AND appearance_manifest_hash=? AND appearance_sha256=?
   AND pdf_signature_role=? AND target_field_name=?
   AND expected_certificate_fingerprint=? AND field_lock_policy_hash=?"#;

pub const APPEND_EVENT_0: &str = r#"INSERT INTO pdf_java_signing_execution_events (
    operation_uuid, attempt_number, event_type, old_state, new_state,
    old_lock_version, new_lock_version, error_code, event_at, event_hash
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"#;

#[cfg(test)]
mod parity_tests {
    #[test]
    fn every_ported_sql_statement_matches_java_reference() {
        let java=include_str!("../../../../pdf-renderer-java/src/main/java/com/luang/pdfsigner/execution/SigningExecutionRepository.java");
        let normalized = java.split_whitespace().collect::<Vec<_>>().join(" ");
        for (name, sql) in [
            ("CLAIM_0", super::CLAIM_0),
            ("CLAIM_1", super::CLAIM_1),
            (
                "MARK_PRIVATE_KEY_STARTED_0",
                super::MARK_PRIVATE_KEY_STARTED_0,
            ),
            ("MARK_COMPLETED_0", super::MARK_COMPLETED_0),
            ("MARK_FAILURE_0", super::MARK_FAILURE_0),
            (
                "MARK_PRE_PRIVATE_KEY_FAILURE_0",
                super::MARK_PRE_PRIVATE_KEY_FAILURE_0,
            ),
            ("AUTHORIZE_RETRY_0", super::AUTHORIZE_RETRY_0),
            ("RECOVERY_MAXIMUM_BYTES_0", super::RECOVERY_MAXIMUM_BYTES_0),
            ("RETIREMENT_CANDIDATES_0", super::RETIREMENT_CANDIDATES_0),
            ("BEGIN_RETIREMENT_0", super::BEGIN_RETIREMENT_0),
            ("APPLY_RETIREMENT_STAGE_0", super::APPLY_RETIREMENT_STAGE_0),
            ("APPLY_RETIREMENT_PURGE_0", super::APPLY_RETIREMENT_PURGE_0),
            (
                "APPLY_RETIREMENT_RESTORE_0",
                super::APPLY_RETIREMENT_RESTORE_0,
            ),
            ("RETIREMENT_TRANSITION_0", super::RETIREMENT_TRANSITION_0),
            (
                "RETIREMENT_AUTHORIZATION_0",
                super::RETIREMENT_AUTHORIZATION_0,
            ),
            ("RETIREMENT_GATE_0", super::RETIREMENT_GATE_0),
            (
                "APPEND_RETIREMENT_EVENT_0",
                super::APPEND_RETIREMENT_EVENT_0,
            ),
            ("OPEN_VERIFIED_RESULT_0", super::OPEN_VERIFIED_RESULT_0),
            ("OPEN_VERIFIED_RESULT_1", super::OPEN_VERIFIED_RESULT_1),
            (
                "VALIDATE_EXECUTION_READ_LEASE_0",
                super::VALIDATE_EXECUTION_READ_LEASE_0,
            ),
            ("MARK_RESULT_INTEGRITY_0", super::MARK_RESULT_INTEGRITY_0),
            ("POLICY_0", super::POLICY_0),
            (
                "VALIDATE_EXISTING_CLAIM_0",
                super::VALIDATE_EXISTING_CLAIM_0,
            ),
            (
                "SIGN_AUTHORIZE_GATE_AT_VERSION_0",
                super::SIGN_AUTHORIZE_GATE_AT_VERSION_0,
            ),
            ("APPEND_EVENT_0", super::APPEND_EVENT_0),
        ] {
            assert!(
                normalized.contains(&sql.split_whitespace().collect::<Vec<_>>().join(" ")),
                "SQL changed from Java reference: {name}"
            );
        }
    }
}
