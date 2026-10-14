//! Connects the execution ledger's durable private-key barrier to the real signer.
use lims_execution::{ExecutionError, ExecutionSigner, PreparedSignature, Result};
use lims_signatures::{SignCommand, SignatureError, SignatureService, TrustConfig};
use serde_json::Value;

pub struct EnvironmentSigner;
impl ExecutionSigner for EnvironmentSigner {
    fn preflight(
        &self,
        pdf: &[u8],
        appearance: &[u8],
        command: &Value,
        expected_certificate_fingerprint: &str,
    ) -> Result<PreparedSignature> {
        let command: SignCommand = serde_json::from_value(command.clone())
            .map_err(|e| ExecutionError::Invalid(e.to_string()))?;
        let service = SignatureService::from_env().map_err(map_error)?;
        let inspection = lims_signatures::inspect(pdf).map_err(map_error)?;
        match command.signature_role.as_str() {
            "certification_p2"
                if inspection.signature_count != 0 || inspection.doc_mdp_permission.is_some() =>
            {
                return Err(ExecutionError::Invalid(
                    "SOURCE_SIGNATURE_POLICY_INVALID".into(),
                ))
            }
            "approval" => {
                let verification = service.verify(pdf);
                if verification.document_current_state != "valid" {
                    return Err(ExecutionError::Invalid(
                        "SOURCE_SIGNATURE_POLICY_INVALID".into(),
                    ));
                }
            }
            _ => {}
        }
        let prepared = service
            .preflight(
                pdf,
                appearance,
                &command,
                Some(expected_certificate_fingerprint),
            )
            .map_err(map_error)?;
        // No private-key operation occurs until the ledger consumes this closure.
        Ok(PreparedSignature::new(move || {
            service.sign_preflighted(prepared).map_err(map_error)
        }))
    }
    fn verify(&self, pdf: &[u8]) -> Result<Value> {
        let trust = TrustConfig::from_env().map_err(map_error)?;
        serde_json::to_value(lims_signatures::verify(pdf, &trust)).map_err(ExecutionError::from)
    }
}
fn map_error(error: SignatureError) -> ExecutionError {
    match error {
        SignatureError::InvalidInput(message) => ExecutionError::Invalid(message),
        SignatureError::Configuration(_) => ExecutionError::NotReady,
        SignatureError::OutcomeUnknown(message) => ExecutionError::OutcomeUncertain(message),
        other => ExecutionError::Signing(other.to_string()),
    }
}
