use crate::Result;
use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use sqlx::{mysql::MySqlRow, Row};
use std::collections::BTreeSet;
use uuid::Uuid;

#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ExecutionRecord {
    pub operation_uuid: Uuid,
    pub operation_input_manifest_hash: String,
    pub input_fingerprint: String,
    pub policy_hash: String,
    pub attempt_number: u32,
    pub attempt_count: u32,
    pub max_attempts: u32,
    pub state: String,
    pub retryability: Option<String>,
    pub authorized_lease_epoch: u64,
    pub lock_version: u64,
    pub claimed_at: Option<DateTime<Utc>>,
    pub execution_started_at: Option<DateTime<Utc>>,
    pub private_key_started_at: Option<DateTime<Utc>>,
    pub execution_deadline_at: Option<DateTime<Utc>>,
    pub next_retry_at: Option<DateTime<Utc>>,
    pub retry_exhausted_at: Option<DateTime<Utc>>,
    pub completed_at: Option<DateTime<Utc>>,
    pub terminal_at: Option<DateTime<Utc>>,
    pub error_code: Option<String>,
    pub result_path: Option<String>,
    pub result_sha256: Option<String>,
    pub result_size: u64,
    pub result_file_key: Option<String>,
    pub validation_report_hash: Option<String>,
    pub result_integrity_state: String,
    pub result_last_verified_at: Option<DateTime<Utc>>,
    pub result_integrity_error_code: Option<String>,
    pub retention_until: Option<DateTime<Utc>>,
    pub retirement_phase: String,
    pub retirement_epoch: u64,
    pub retirement_staged_path: Option<String>,
    pub retirement_started_at: Option<DateTime<Utc>>,
    pub retirement_purge_not_before: Option<DateTime<Utc>>,
    pub evidence_hold_mask: u64,
    pub evidence_hold_state: String,
    pub legal_hold_until: Option<DateTime<Utc>>,
    pub bytes_deleted_at: Option<DateTime<Utc>>,
}
impl ExecutionRecord {
    pub fn from_row(row: &MySqlRow) -> Result<Self> {
        Ok(Self {
            operation_uuid: Uuid::parse_str(row.try_get::<&str, _>("operation_uuid")?)
                .map_err(|_| crate::gate("Invalid operation UUID in ledger"))?,
            operation_input_manifest_hash: row.try_get("operation_input_manifest_hash")?,
            input_fingerprint: row.try_get("input_fingerprint")?,
            policy_hash: row.try_get("policy_hash")?,
            attempt_number: row.try_get("attempt_number")?,
            attempt_count: row.try_get("attempt_count")?,
            max_attempts: row.try_get("max_attempts")?,
            state: row.try_get("state")?,
            retryability: row.try_get("retryability")?,
            authorized_lease_epoch: row.try_get("authorized_lease_epoch")?,
            lock_version: row.try_get("lock_version")?,
            claimed_at: row.try_get::<Option<DateTime<Utc>>, _>("claimed_at")?,
            execution_started_at: row
                .try_get::<Option<DateTime<Utc>>, _>("execution_started_at")?,
            private_key_started_at: row
                .try_get::<Option<DateTime<Utc>>, _>("private_key_started_at")?,
            execution_deadline_at: row
                .try_get::<Option<DateTime<Utc>>, _>("execution_deadline_at")?,
            next_retry_at: row.try_get::<Option<DateTime<Utc>>, _>("next_retry_at")?,
            retry_exhausted_at: row.try_get::<Option<DateTime<Utc>>, _>("retry_exhausted_at")?,
            completed_at: row.try_get::<Option<DateTime<Utc>>, _>("completed_at")?,
            terminal_at: row.try_get::<Option<DateTime<Utc>>, _>("terminal_at")?,
            error_code: row.try_get("error_code")?,
            result_path: row.try_get("result_path")?,
            result_sha256: row.try_get("result_sha256")?,
            result_size: row.try_get::<Option<u64>, _>("result_size")?.unwrap_or(0),
            result_file_key: row.try_get("result_file_key")?,
            validation_report_hash: row.try_get("validation_report_hash")?,
            result_integrity_state: row.try_get("result_integrity_state")?,
            result_last_verified_at: row
                .try_get::<Option<DateTime<Utc>>, _>("result_last_verified_at")?,
            result_integrity_error_code: row.try_get("result_integrity_error_code")?,
            retention_until: row.try_get::<Option<DateTime<Utc>>, _>("retention_until")?,
            retirement_phase: row.try_get("retirement_phase")?,
            retirement_epoch: row.try_get("retirement_epoch")?,
            retirement_staged_path: row.try_get("retirement_staged_path")?,
            retirement_started_at: row
                .try_get::<Option<DateTime<Utc>>, _>("retirement_started_at")?,
            retirement_purge_not_before: row
                .try_get::<Option<DateTime<Utc>>, _>("retirement_purge_not_before")?,
            evidence_hold_mask: row.try_get("evidence_hold_mask")?,
            evidence_hold_state: row.try_get("evidence_hold_state")?,
            legal_hold_until: row.try_get::<Option<DateTime<Utc>>, _>("legal_hold_until")?,
            bytes_deleted_at: row.try_get::<Option<DateTime<Utc>>, _>("bytes_deleted_at")?,
        })
    }
    pub fn is_terminal(&self) -> bool {
        matches!(
            self.state.as_str(),
            "completed"
                | "failed_before_private_key"
                | "failed_after_private_key_known"
                | "outcome_unknown"
        )
    }
    pub fn response(&self) -> Value {
        let mut value = json!({"operationUuid": self.operation_uuid, "state":self.state,
   "attemptNumber":self.attempt_number,"attemptCount":self.attempt_count,"maximumAttempts":self.max_attempts,
   "retryability":self.retryability,"nextRetryAt":self.next_retry_at,"retryExhaustedAt":self.retry_exhausted_at,
   "privateKeyStartedAt":self.private_key_started_at,"executionDeadlineAt":self.execution_deadline_at,
   "terminalAt":self.terminal_at,"errorCode":self.error_code,"resultIntegrityState":self.result_integrity_state,
   "resultSha256":self.result_sha256,"resultSize":self.result_size,
   "statusUrl":format!("/internal/pdf/signatures/executions/{}",self.operation_uuid)});
        if self.state == "completed" && self.result_integrity_state == "available" {
            value["resultUrl"] = format!(
                "/internal/pdf/signatures/executions/{}/result",
                self.operation_uuid
            )
            .into();
        }
        value
    }
    pub fn hold_blocks(&self, now: DateTime<Utc>) -> bool {
        self.evidence_hold_mask != 0
            || self.evidence_hold_state != "none"
            || self.legal_hold_until.is_some_and(|v| now < v)
    }
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct OperationClaim {
    pub operation_uuid: Uuid,
    pub java_gate_version: u64,
    pub lease_epoch: u64,
    pub operation_input_manifest_hash: String,
    pub input_fingerprint: String,
    pub expected_source_sha256: String,
    pub policy_version_id: u64,
    pub policy_version_uuid: Uuid,
    pub policy_hash: String,
    pub config_bundle_hash: String,
    pub appearance_manifest_hash: String,
    pub appearance_sha256: String,
    pub pdf_signature_role: String,
    pub target_field_name: String,
    pub expected_certificate_fingerprint: String,
    pub field_lock_policy_hash: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct ExecutionReadClaim {
    pub operation_uuid: Uuid,
    pub lease_epoch: u64,
    pub operation_input_manifest_hash: String,
    pub input_fingerprint: String,
    pub policy_hash: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct PolicySnapshot {
    pub id: u64,
    pub version_uuid: Uuid,
    pub policy_hash: String,
    pub config_bundle_hash: String,
    pub execution_timeout_seconds: u32,
    pub maximum_attempts: u32,
    pub retry_backoff_seconds: Vec<u64>,
    pub retryable_error_codes: BTreeSet<String>,
    pub maximum_generated_revision_bytes: u64,
    pub maximum_signature_increment_bytes: u64,
}

impl OperationClaim {
    pub fn read_claim(&self) -> ExecutionReadClaim {
        ExecutionReadClaim {
            operation_uuid: self.operation_uuid,
            lease_epoch: self.lease_epoch,
            operation_input_manifest_hash: self.operation_input_manifest_hash.clone(),
            input_fingerprint: self.input_fingerprint.clone(),
            policy_hash: self.policy_hash.clone(),
        }
    }
}
#[derive(Debug, Clone)]
pub struct ClaimResult {
    pub execution: ExecutionRecord,
    pub newly_claimed: bool,
    pub policy: PolicySnapshot,
}
