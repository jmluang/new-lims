use crate::*;
use lims_core::{MultipartData, ServiceError, ServiceResult};
use serde::Deserialize;
use serde_json::Value;
use std::collections::BTreeMap;
use uuid::Uuid;
impl ExecutionService {
    pub async fn execute(
        &self,
        parts: &MultipartData,
        headers: &BTreeMap<String, String>,
    ) -> ServiceResult<(u16, Value)> {
        let c: OperationClaim = serde_json::from_str(parts.field("operation")?)
            .map_err(|_| ServiceError::bad_request("PDF_EXECUTION_METADATA_INVALID"))?;
        validate_headers(headers, &c)?;
        let command: Value = serde_json::from_str(parts.field("command")?)
            .map_err(|_| ServiceError::bad_request("PDF_COMMAND_INVALID"))?;
        let e = self
            .execute_claim(
                &c,
                parts.file("pdf")?.to_vec(),
                parts.file("appearance")?.to_vec(),
                command,
            )
            .await
            .map_err(error_response)?;
        Ok((if e.is_terminal() { 200 } else { 202 }, e.response()))
    }
    pub async fn status(
        &self,
        id: &str,
        headers: &BTreeMap<String, String>,
    ) -> ServiceResult<(u16, Value)> {
        let c = read_headers(id, headers)?;
        let e = self.status_claim(&c).await.map_err(error_response)?;
        Ok((if e.is_terminal() { 200 } else { 202 }, e.response()))
    }
    pub async fn result(
        &self,
        id: &str,
        headers: &BTreeMap<String, String>,
    ) -> ServiceResult<OpenResult> {
        let c = read_headers(id, headers)?;
        self.open_result_claim(&c).await.map_err(|e| match e {
            ExecutionError::Gate(_) => {
                ServiceError::unavailable("EXECUTION_RESULT_INTEGRITY_ERROR")
            }
            other => error_response(other),
        })
    }
    pub fn retirement_inspect(
        &self,
        id: &str,
        headers: &BTreeMap<String, String>,
        body: Value,
    ) -> ServiceResult<Value> {
        let c = read_headers(id, headers)?;
        let e: RetirementEvidenceRequest = serde_json::from_value(body)
            .map_err(|_| ServiceError::unprocessable("RETIREMENT_EVIDENCE_SNAPSHOT_INVALID"))?;
        let inspection = self
            .storage
            .inspect_retirement_evidence(
                c.operation_uuid,
                e.retirement_epoch,
                &e.retirement_phase,
                &e.expected_sha256,
                e.expected_size,
            )
            .map_err(|error| match error {
                ExecutionError::Invalid(_) => {
                    ServiceError::unprocessable("RETIREMENT_EVIDENCE_SNAPSHOT_INVALID")
                }
                other => error_response(other),
            })?;
        serde_json::to_value(inspection).map_err(|_| ServiceError::internal("PDF_INTERNAL_ERROR"))
    }
}
#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct RetirementEvidenceRequest {
    pub retirement_epoch: u64,
    pub retirement_phase: String,
    pub expected_sha256: String,
    pub expected_size: u64,
}
fn header<'a>(headers: &'a BTreeMap<String, String>, name: &str) -> ServiceResult<&'a str> {
    headers
        .iter()
        .find(|(k, _)| k.eq_ignore_ascii_case(name))
        .map(|(_, v)| v.as_str())
        .filter(|v| !v.trim().is_empty())
        .ok_or_else(|| ServiceError::bad_request("PDF_EXECUTION_METADATA_REQUIRED"))
}
pub fn read_headers(
    id: &str,
    headers: &BTreeMap<String, String>,
) -> ServiceResult<ExecutionReadClaim> {
    let uuid = Uuid::parse_str(id)
        .map_err(|_| ServiceError::bad_request("PDF_EXECUTION_METADATA_INVALID"))?;
    if header(headers, "X-Pdf-Operation-Uuid")? != uuid.to_string() {
        return Err(ServiceError::new(401, "PDF_EXECUTION_METADATA_MISMATCH"));
    }
    let epoch = header(headers, "X-Pdf-Lease-Epoch")?
        .parse::<u64>()
        .map_err(|_| ServiceError::bad_request("PDF_EXECUTION_METADATA_INVALID"))?;
    Ok(ExecutionReadClaim {
        operation_uuid: uuid,
        lease_epoch: epoch,
        operation_input_manifest_hash: header(headers, "X-Pdf-Operation-Manifest-Sha256")?.into(),
        input_fingerprint: header(headers, "X-Pdf-Input-Fingerprint")?.into(),
        policy_hash: header(headers, "X-Pdf-Policy-Sha256")?.into(),
    })
}
pub fn validate_headers(
    headers: &BTreeMap<String, String>,
    c: &OperationClaim,
) -> ServiceResult<()> {
    if read_headers(&c.operation_uuid.to_string(), headers)? != c.read_claim()
        || header(headers, "X-Pdf-Policy-Version-Id")? != c.policy_version_id.to_string()
        || header(headers, "X-Pdf-Policy-Version-Uuid")? != c.policy_version_uuid.to_string()
        || header(headers, "X-Pdf-Config-Bundle-Sha256")? != c.config_bundle_hash
    {
        return Err(ServiceError::new(401, "PDF_EXECUTION_METADATA_MISMATCH"));
    }
    Ok(())
}
pub fn error_response(e: ExecutionError) -> ServiceError {
    match e {
        ExecutionError::Gate(_) => ServiceError::new(409, "EXECUTION_GATE_REJECTED"),
        ExecutionError::NotReady | ExecutionError::DatabaseConfiguration(_) => {
            ServiceError::unavailable("EXECUTION_LEDGER_NOT_READY")
        }
        ExecutionError::NotFound => ServiceError::new(404, "EXECUTION_NOT_FOUND"),
        ExecutionError::Unavailable => ServiceError::new(409, "EXECUTION_RESULT_UNAVAILABLE"),
        ExecutionError::Missing | ExecutionError::Breached(_) => {
            ServiceError::unavailable("EXECUTION_RESULT_INTEGRITY_ERROR")
        }
        ExecutionError::ShuttingDown => ServiceError::unavailable("PDF_SHUTTING_DOWN"),
        ExecutionError::Busy => ServiceError::unavailable("PDF_BUSY"),
        ExecutionError::OutcomeUncertain(_) => {
            ServiceError::unavailable("PDF_SIGNING_OUTCOME_UNCERTAIN")
        }
        ExecutionError::Database(_) => ServiceError::unavailable("EXECUTION_LEDGER_UNAVAILABLE"),
        ExecutionError::Invalid(_) => ServiceError::unprocessable("PDF_EXECUTION_INPUT_INVALID"),
        _ => ServiceError::internal("PDF_INTERNAL_ERROR"),
    }
}
