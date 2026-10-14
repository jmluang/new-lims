use crate::*;
use async_trait::async_trait;
use chrono::Utc;
use serde_json::Value;
use std::sync::{Arc, Mutex};
use tokio::sync::{Notify, Semaphore};
use uuid::Uuid;

/// Opaque single-use preparation. Constructing this must not use the private key.
/// The closure is consumed only after durable PRIVATE_KEY_STARTED commit.
pub struct PreparedSignature {
    run: Box<dyn FnOnce() -> Result<Vec<u8>> + Send>,
}
impl PreparedSignature {
    pub fn new(f: impl FnOnce() -> Result<Vec<u8>> + Send + 'static) -> Self {
        Self { run: Box::new(f) }
    }
    fn sign(self) -> Result<Vec<u8>> {
        (self.run)()
    }
}
/// Actual adapters must perform ready-configuration, target/geometry, document-policy,
/// historical-signature and appearance validation during preflight, without signing.
pub trait ExecutionSigner: Send + Sync {
    fn preflight(
        &self,
        pdf: &[u8],
        appearance: &[u8],
        command: &Value,
        expected_certificate_fingerprint: &str,
    ) -> Result<PreparedSignature>;
    fn verify(&self, pdf: &[u8]) -> Result<Value>;
}
#[async_trait]
pub trait ExecutionLedger: Send + Sync {
    async fn readiness_probe(&self) -> bool;
    async fn claim(&self, c: &OperationClaim) -> Result<ClaimResult>;
    async fn mark_executing(&self, id: Uuid, timeout: u32) -> Result<ExecutionRecord>;
    async fn mark_private_key_started(&self, c: &OperationClaim) -> Result<ExecutionRecord>;
    async fn mark_completed(
        &self,
        id: Uuid,
        result: &StoredResult,
        report: &str,
    ) -> Result<ExecutionRecord>;
    async fn mark_failure(
        &self,
        id: Uuid,
        state: &str,
        retryability: &str,
        code: &str,
    ) -> Result<ExecutionRecord>;
    async fn mark_pre_private_key_failure(
        &self,
        id: Uuid,
        code: &str,
        p: &PolicySnapshot,
    ) -> Result<ExecutionRecord>;
    async fn authorize_retry(&self, c: &OperationClaim, version: u64) -> Result<ExecutionRecord>;
    async fn require_matching_execution(&self, c: &ExecutionReadClaim) -> Result<ExecutionRecord>;
    async fn recovery_maximum_bytes(&self, id: Uuid) -> Result<u64>;
    async fn open_verified_result(
        &self,
        c: &ExecutionReadClaim,
        s: &ExecutionStorage,
    ) -> Result<OpenResult>;
}
#[async_trait]
impl ExecutionLedger for ExecutionRepository {
    async fn readiness_probe(&self) -> bool {
        self.readiness_probe().await
    }
    async fn claim(&self, c: &OperationClaim) -> Result<ClaimResult> {
        self.claim(c).await
    }
    async fn mark_executing(&self, id: Uuid, t: u32) -> Result<ExecutionRecord> {
        self.mark_executing(id, t).await
    }
    async fn mark_private_key_started(&self, c: &OperationClaim) -> Result<ExecutionRecord> {
        self.mark_private_key_started(c).await
    }
    async fn mark_completed(&self, id: Uuid, r: &StoredResult, h: &str) -> Result<ExecutionRecord> {
        self.mark_completed(id, r, h).await
    }
    async fn mark_failure(&self, id: Uuid, s: &str, r: &str, c: &str) -> Result<ExecutionRecord> {
        self.mark_failure(id, s, r, c).await
    }
    async fn mark_pre_private_key_failure(
        &self,
        id: Uuid,
        c: &str,
        p: &PolicySnapshot,
    ) -> Result<ExecutionRecord> {
        self.mark_pre_private_key_failure(id, c, p).await
    }
    async fn authorize_retry(&self, c: &OperationClaim, v: u64) -> Result<ExecutionRecord> {
        self.authorize_retry(c, v).await
    }
    async fn require_matching_execution(&self, c: &ExecutionReadClaim) -> Result<ExecutionRecord> {
        self.require_matching_execution(c).await
    }
    async fn recovery_maximum_bytes(&self, id: Uuid) -> Result<u64> {
        self.recovery_maximum_bytes(id).await
    }
    async fn open_verified_result(
        &self,
        c: &ExecutionReadClaim,
        s: &ExecutionStorage,
    ) -> Result<OpenResult> {
        self.open_verified_result(c, s).await
    }
}
#[derive(Clone)]
pub struct ExecutionService {
    lifecycle: Arc<Lifecycle>,
    pub(crate) ledger: Option<Arc<dyn ExecutionLedger>>,
    pub(crate) storage: ExecutionStorage,
    pub(crate) signer: Arc<dyn ExecutionSigner>,
    pub(crate) limiter: Arc<Semaphore>,
    pub(crate) repository: Option<ExecutionRepository>,
}
impl ExecutionService {
    pub fn new(
        ledger: Arc<dyn ExecutionLedger>,
        storage: ExecutionStorage,
        signer: Arc<dyn ExecutionSigner>,
        limiter: Arc<Semaphore>,
    ) -> Self {
        Self {
            lifecycle: Arc::new(Lifecycle::default()),
            ledger: Some(ledger),
            storage,
            signer,
            limiter,
            repository: None,
        }
    }
    /// Construct an explicitly configured real repository, including retirement support.
    pub fn with_repository(
        repository: ExecutionRepository,
        storage: ExecutionStorage,
        signer: Arc<dyn ExecutionSigner>,
        limiter: Arc<Semaphore>,
    ) -> Self {
        Self {
            lifecycle: Arc::new(Lifecycle::default()),
            ledger: Some(Arc::new(repository.clone())),
            storage,
            signer,
            limiter,
            repository: Some(repository),
        }
    }
    pub fn from_env(signer: Arc<dyn ExecutionSigner>, limiter: Arc<Semaphore>) -> Self {
        let enabled = std::env::var("PDF_EXECUTION_LEDGER_ENABLED")
            .is_ok_and(|v| v.eq_ignore_ascii_case("true"));
        let root = std::env::var("PDF_EXECUTION_RESULT_ROOT")
            .or_else(|_| std::env::var("PDF_EXECUTION_LEDGER_RESULT_ROOT"))
            .unwrap_or_else(|_| "./data/signing-results".into());
        let storage = ExecutionStorage::new(root)
            .expect("Cannot resolve signing result storage root; refusing an alternate data path");
        let url = std::env::var("PDF_EXECUTION_DB_URL")
            .or_else(|_| std::env::var("PDF_EXECUTION_LEDGER_JDBC_URL"))
            .or_else(|_| std::env::var("PDF_EXECUTION_LEDGER_DATABASE_URL"))
            .unwrap_or_default();
        let username = std::env::var("PDF_EXECUTION_DB_USERNAME")
            .or_else(|_| std::env::var("PDF_EXECUTION_LEDGER_USERNAME"))
            .unwrap_or_default();
        let password = std::env::var("PDF_EXECUTION_DB_PASSWORD")
            .or_else(|_| std::env::var("PDF_EXECUTION_LEDGER_PASSWORD"))
            .unwrap_or_default();
        let repository = if enabled {
            match ExecutionRepository::connect_lazy(&url, &username, &password) {
                Ok(repository) => Some(repository),
                Err(error) => {
                    // Configuration errors contain fixed descriptions only, never URL values.
                    tracing::error!(reason=%error, "Execution database configuration is not ready");
                    None
                }
            }
        } else {
            None
        };
        Self {
            lifecycle: Arc::new(Lifecycle::default()),
            ledger: repository
                .clone()
                .map(|r| Arc::new(r) as Arc<dyn ExecutionLedger>),
            storage,
            signer,
            limiter,
            repository,
        }
    }
    /// Stops admission without interrupting a key operation already in progress.
    pub fn begin_shutdown(&self) {
        self.lifecycle
            .state
            .lock()
            .expect("Lifecycle mutex")
            .accepting = false;
    }
    /// Call after begin_shutdown; waits for detached signing/recovery and current retirement jobs.
    pub async fn wait_idle(&self) {
        loop {
            let notified = self.lifecycle.notify.notified();
            tokio::pin!(notified);
            notified.as_mut().enable();
            if self.lifecycle.state.lock().expect("Lifecycle mutex").active == 0 {
                return;
            }
            notified.await;
        }
    }
    fn ledger(&self) -> Result<&dyn ExecutionLedger> {
        self.ledger.as_deref().ok_or(ExecutionError::NotReady)
    }
    pub async fn database_ready(&self) -> bool {
        match &self.ledger {
            Some(l) => l.readiness_probe().await,
            None => false,
        }
    }
    pub fn storage_ready(&self) -> bool {
        self.storage.readiness_probe()
    }
    pub async fn readiness(&self) -> bool {
        self.database_ready().await && self.storage_ready()
    }
    pub async fn execute_claim(
        &self,
        c: &OperationClaim,
        pdf: Vec<u8>,
        appearance: Vec<u8>,
        command: Value,
    ) -> Result<ExecutionRecord> {
        // Dropping a transport request detaches this task; it cannot drop the heavy
        // permit while a blocking private-key operation is still running.
        let job = self.lifecycle.start()?;
        let service = self.clone();
        let c = c.clone();
        tokio::spawn(async move {
            let _job = job;
            service.execute_inner(&c, pdf, appearance, command).await
        })
        .await
        .map_err(|e| ExecutionError::OutcomeUncertain(format!("Execution task terminated: {e}")))?
    }
    async fn execute_inner(
        &self,
        c: &OperationClaim,
        pdf: Vec<u8>,
        appearance: Vec<u8>,
        command: Value,
    ) -> Result<ExecutionRecord> {
        let ledger = self.ledger()?;
        let claimed = ledger.claim(c).await?;
        if !claimed.newly_claimed {
            if claimed.execution.state != "failed_before_private_key"
                || claimed.execution.retryability.as_deref() != Some("same_operation")
            {
                return Ok(claimed.execution);
            }
            ledger
                .authorize_retry(c, claimed.execution.lock_version)
                .await?;
        }
        let mut private_key_started = false;
        let result = async {
            let executing = ledger
                .mark_executing(c.operation_uuid, claimed.policy.execution_timeout_seconds)
                .await?;
            if executing.state != "executing" {
                return Ok(executing);
            }
            let _permit = self
                .limiter
                .clone()
                .try_acquire_owned()
                .map_err(|_| ExecutionError::Busy)?;
            validate_frozen_inputs(c, &claimed.policy, &pdf, &appearance, &command)?;
            let maximum = claimed.policy.maximum_generated_revision_bytes.min(
                (pdf.len() as u64)
                    .checked_add(claimed.policy.maximum_signature_increment_bytes)
                    .ok_or_else(|| ExecutionError::Invalid("Size budget overflow".into()))?,
            );
            let signer = self.signer.clone();
            let cert = c.expected_certificate_fingerprint.clone();
            let prepared = tokio::task::spawn_blocking(move || {
                signer.preflight(&pdf, &appearance, &command, &cert)
            })
            .await
            .map_err(|e| ExecutionError::Signing(format!("Preflight worker failed: {e}")))??;
            ledger.mark_private_key_started(c).await?;
            private_key_started = true;
            let signed = tokio::task::spawn_blocking(move || prepared.sign())
                .await
                .map_err(|e| {
                    ExecutionError::OutcomeUncertain(format!("Signing worker terminated: {e}"))
                })??;
            let signer = self.signer.clone();
            let storage = self.storage.clone();
            let id = c.operation_uuid;
            let (stored, report_hash) = tokio::task::spawn_blocking(move || -> Result<_> {
                let report = signer.verify(&signed)?;
                if !acceptable(&report) {
                    return Err(ExecutionError::GeneratedVerification);
                }
                let stored = storage.persist(id, &signed, maximum)?;
                Ok((stored, sha256(&serde_json::to_vec(&report)?)))
            })
            .await
            .map_err(|e| {
                ExecutionError::OutcomeUncertain(format!("Result worker terminated: {e}"))
            })??;
            ledger
                .mark_completed(c.operation_uuid, &stored, &report_hash)
                .await
        }
        .await;
        match result {
            Ok(v) => Ok(v),
            Err(error) if !private_key_started => {
                tracing::warn!(operation_uuid=%c.operation_uuid, error=%error, "Signing refused before the private-key boundary");
                ledger
                    .mark_pre_private_key_failure(
                        c.operation_uuid,
                        stable_error(&error, "PRE_KEY_VALIDATION_FAILED"),
                        &claimed.policy,
                    )
                    .await
            }
            Err(error) if is_outcome_uncertain(&error) => {
                tracing::warn!(operation_uuid=%c.operation_uuid, error=%error, "Post-key outcome uncertain; preserving executing state for deadline recovery");
                Err(error)
            }
            Err(error) => {
                tracing::error!(operation_uuid=%c.operation_uuid, error=%error, "Known signing failure after the private-key boundary");
                ledger
                    .mark_failure(
                        c.operation_uuid,
                        "failed_after_private_key_known",
                        "none",
                        stable_error(&error, "POST_KEY_KNOWN_FAILURE"),
                    )
                    .await
            }
        }
    }
    pub async fn status_claim(&self, c: &ExecutionReadClaim) -> Result<ExecutionRecord> {
        let job = self.lifecycle.start()?;
        let service = self.clone();
        let c = c.clone();
        tokio::spawn(async move {
            let _job = job;
            service.status_inner(&c).await
        })
        .await
        .map_err(|e| ExecutionError::OutcomeUncertain(format!("Recovery task terminated: {e}")))?
    }
    async fn status_inner(&self, c: &ExecutionReadClaim) -> Result<ExecutionRecord> {
        let ledger = self.ledger()?;
        let e = ledger.require_matching_execution(c).await?;
        if e.state == "executing" && e.execution_deadline_at.is_some_and(|v| Utc::now() >= v) {
            if e.private_key_started_at.is_none() {
                return ledger
                    .mark_failure(
                        c.operation_uuid,
                        "failed_before_private_key",
                        "none",
                        "EXECUTION_DEADLINE_BEFORE_PRIVATE_KEY",
                    )
                    .await;
            }
            let _permit = self
                .limiter
                .clone()
                .try_acquire_owned()
                .map_err(|_| ExecutionError::Busy)?;
            return self.recover_deadline(c.operation_uuid).await;
        }
        Ok(e)
    }
    pub async fn open_result_claim(&self, c: &ExecutionReadClaim) -> Result<OpenResult> {
        let e = self.status_claim(c).await?;
        if e.state != "completed" || e.result_integrity_state != "available" {
            return Err(ExecutionError::Unavailable);
        }
        self.ledger()?.open_verified_result(c, &self.storage).await
    }
    async fn recover_deadline(&self, id: Uuid) -> Result<ExecutionRecord> {
        let ledger = self.ledger()?;
        let maximum = ledger.recovery_maximum_bytes(id).await?;
        let storage = self.storage.clone();
        let candidate =
            tokio::task::spawn_blocking(move || storage.find_recovery_candidate(id, maximum))
                .await
                .map_err(|e| ExecutionError::OutcomeUncertain(e.to_string()))?;
        let candidate = match candidate {
            Err(ExecutionError::Breached(_)) => {
                return ledger
                    .mark_failure(
                        id,
                        "outcome_unknown",
                        "none",
                        "RECOVERY_RESULT_IDENTITY_AMBIGUOUS",
                    )
                    .await
            }
            Err(e) => return Err(e),
            Ok(None) => {
                return ledger
                    .mark_failure(
                        id,
                        "outcome_unknown",
                        "none",
                        "EXECUTION_DEADLINE_OUTCOME_UNKNOWN",
                    )
                    .await
            }
            Ok(Some(c)) => c,
        };
        let signer = self.signer.clone();
        let (candidate, report) = tokio::task::spawn_blocking(move || {
            let report = signer.verify(&candidate.bytes);
            (candidate, report)
        })
        .await
        .map_err(|e| ExecutionError::OutcomeUncertain(e.to_string()))?;
        let report = report?;
        if !acceptable(&report) {
            return ledger
                .mark_failure(
                    id,
                    "failed_after_private_key_known",
                    "none",
                    "RECOVERY_RESULT_VERIFICATION_FAILED",
                )
                .await;
        }
        let storage = self.storage.clone();
        let stored =
            tokio::task::spawn_blocking(move || storage.promote_recovery_candidate(&candidate))
                .await
                .map_err(|e| ExecutionError::OutcomeUncertain(e.to_string()))?;
        match stored {
            Ok(stored) => {
                ledger
                    .mark_completed(id, &stored, &sha256(&serde_json::to_vec(&report)?))
                    .await
            }
            Err(ExecutionError::Breached(_)) => {
                ledger
                    .mark_failure(
                        id,
                        "outcome_unknown",
                        "none",
                        "RECOVERY_RESULT_PROMOTION_AMBIGUOUS",
                    )
                    .await
            }
            Err(e) => Err(e),
        }
    }
    /// Advances authorized lifecycle stages only; SQL rows gate every filesystem mutation.
    pub async fn retirement_sweep(&self, limit: u32) -> Result<RetirementSweep> {
        let _job = self.lifecycle.start()?;
        let repo = self.repository.as_ref().ok_or(ExecutionError::NotReady)?;
        let mut report = RetirementSweep::default();
        for candidate in repo.retirement_candidates(limit).await? {
            let id = candidate.operation_uuid;
            let mut advanced = 0;
            let progress = async {
                let mut current = candidate;
                if current.retirement_phase != "none"
                    && current.evidence_hold_mask != 0
                    && current.evidence_hold_state == "active"
                {
                    repo.apply_retirement_restore(id, current.retirement_epoch, &self.storage)
                        .await?;
                    advanced += 1;
                    return Ok::<(), ExecutionError>(());
                }
                if current.retirement_phase == "none" {
                    current = repo.begin_retirement(id).await?;
                    advanced += 1
                }
                if current.retirement_phase == "stage_intent" {
                    current = repo
                        .apply_retirement_stage(id, current.retirement_epoch, &self.storage)
                        .await?;
                    advanced += 1
                }
                if current.retirement_phase == "staged"
                    && current
                        .retirement_purge_not_before
                        .is_some_and(|v| Utc::now() >= v)
                {
                    self.storage.verify_staged(&current)?;
                    current = repo
                        .begin_retirement_purge(id, current.retirement_epoch)
                        .await?;
                    advanced += 1
                }
                if current.retirement_phase == "purge_intent" {
                    repo.apply_retirement_purge(id, current.retirement_epoch, &self.storage)
                        .await?;
                    advanced += 1
                }
                Ok(())
            }
            .await;
            report.advanced += advanced;
            match progress {
                Ok(()) => (),
                Err(ExecutionError::Gate(message)) => report.gate_rejections.push((id, message)),
                Err(e) => report.failures.push((id, e.to_string())),
            }
        }
        Ok(report)
    }
}
#[derive(Debug, Default)]
pub struct RetirementSweep {
    pub advanced: u32,
    pub gate_rejections: Vec<(Uuid, String)>,
    pub failures: Vec<(Uuid, String)>,
}
pub fn validate_frozen_inputs(
    c: &OperationClaim,
    p: &PolicySnapshot,
    pdf: &[u8],
    appearance: &[u8],
    command: &Value,
) -> Result<()> {
    if pdf.is_empty() || pdf.len() > 20 * 1024 * 1024 {
        return Err(ExecutionError::Invalid(
            "Source PDF exceeds V1 input boundary".into(),
        ));
    }
    if sha256(pdf) != c.expected_source_sha256 {
        return Err(ExecutionError::Invalid(
            "Source SHA-256 differs from frozen operation".into(),
        ));
    }
    if sha256(appearance) != c.appearance_sha256 {
        return Err(ExecutionError::Invalid(
            "Appearance SHA-256 differs from frozen operation".into(),
        ));
    }
    if command["fieldName"].as_str() != Some(&c.target_field_name)
        || command["signatureRole"].as_str() != Some(&c.pdf_signature_role)
    {
        return Err(ExecutionError::Invalid(
            "Signature command differs from frozen target/role".into(),
        ));
    }
    if (pdf.len() as u64)
        .checked_add(p.maximum_signature_increment_bytes)
        .is_none_or(|v| v > p.maximum_generated_revision_bytes)
    {
        return Err(ExecutionError::Invalid(
            "Projected revision exceeds frozen size budget".into(),
        ));
    }
    Ok(())
}
fn acceptable(report: &Value) -> bool {
    report["documentCurrentState"] == "valid"
        && report["signatures"]
            .as_array()
            .is_some_and(|v| !v.is_empty())
}
fn stable_error<'a>(e: &ExecutionError, fallback: &'a str) -> &'a str {
    match e {
        ExecutionError::Busy => "PDF_BUSY",
        ExecutionError::GeneratedVerification => "GENERATED_REVISION_VERIFICATION_FAILED",
        ExecutionError::Gate(_) => "EXECUTION_GATE_REJECTED",
        _ => fallback,
    }
}

struct LifecycleState {
    accepting: bool,
    active: usize,
}
struct Lifecycle {
    state: Mutex<LifecycleState>,
    notify: Notify,
}
impl Default for Lifecycle {
    fn default() -> Self {
        Self {
            state: Mutex::new(LifecycleState {
                accepting: true,
                active: 0,
            }),
            notify: Notify::new(),
        }
    }
}
impl Lifecycle {
    fn start(self: &Arc<Self>) -> Result<JobGuard> {
        let mut state = self.state.lock().expect("Lifecycle mutex");
        if !state.accepting {
            return Err(ExecutionError::ShuttingDown);
        }
        state.active += 1;
        Ok(JobGuard(self.clone()))
    }
}
struct JobGuard(Arc<Lifecycle>);
impl Drop for JobGuard {
    fn drop(&mut self) {
        let mut state = self.0.state.lock().expect("Lifecycle mutex");
        state.active -= 1;
        if state.active == 0 {
            self.0.notify.notify_waiters();
        }
    }
}

/// Match Java's recursive HttpTimeout/ConnectException/SocketException predicate.
/// SQLx preserves socket failures as Io errors. A generic protocol error, EOF,
/// socket read timeout, pool timeout or SQL rejection is not a Java SocketException.
fn is_outcome_uncertain(error: &ExecutionError) -> bool {
    if matches!(error, ExecutionError::OutcomeUncertain(_)) {
        return true;
    }
    let ExecutionError::Database(database) = error else {
        return false;
    };
    let mut current: Option<&(dyn std::error::Error + 'static)> = Some(database);
    while let Some(cause) = current {
        if let Some(io) = cause.downcast_ref::<std::io::Error>() {
            use std::io::ErrorKind;
            if matches!(
                io.kind(),
                ErrorKind::ConnectionRefused
                    | ErrorKind::ConnectionReset
                    | ErrorKind::ConnectionAborted
                    | ErrorKind::NotConnected
                    | ErrorKind::BrokenPipe
                    | ErrorKind::AddrInUse
                    | ErrorKind::AddrNotAvailable
                    | ErrorKind::HostUnreachable
                    | ErrorKind::NetworkUnreachable
                    | ErrorKind::NetworkDown
            ) {
                return true;
            }
        }
        current = cause.source();
    }
    false
}

#[cfg(test)]
mod uncertainty_tests {
    use super::*;
    #[test]
    fn database_socket_causes_match_java_but_other_database_errors_do_not() {
        use std::io::ErrorKind;
        for kind in [
            ErrorKind::ConnectionRefused,
            ErrorKind::ConnectionReset,
            ErrorKind::ConnectionAborted,
            ErrorKind::NotConnected,
            ErrorKind::BrokenPipe,
            ErrorKind::AddrInUse,
            ErrorKind::AddrNotAvailable,
            ErrorKind::HostUnreachable,
            ErrorKind::NetworkUnreachable,
            ErrorKind::NetworkDown,
        ] {
            assert!(
                is_outcome_uncertain(&ExecutionError::Database(sqlx::Error::Io(
                    std::io::Error::from(kind)
                ))),
                "{kind:?}"
            );
        }
        for kind in [
            ErrorKind::UnexpectedEof,
            ErrorKind::TimedOut,
            ErrorKind::NotFound,
            ErrorKind::PermissionDenied,
            ErrorKind::InvalidData,
            ErrorKind::Other,
        ] {
            assert!(
                !is_outcome_uncertain(&ExecutionError::Database(sqlx::Error::Io(
                    std::io::Error::from(kind)
                ))),
                "{kind:?}"
            );
        }
        for error in [
            sqlx::Error::Protocol("ordinary protocol error".into()),
            sqlx::Error::PoolTimedOut,
            sqlx::Error::PoolClosed,
            sqlx::Error::RowNotFound,
        ] {
            assert!(!is_outcome_uncertain(&ExecutionError::Database(error)));
        }
    }
    #[test]
    fn nested_database_socket_cause_is_not_lost_in_driver_wrappers() {
        let wrapped = sqlx::Error::Tls(Box::new(std::io::Error::from(
            std::io::ErrorKind::ConnectionReset,
        )));
        assert!(is_outcome_uncertain(&ExecutionError::Database(wrapped)));
        assert!(!is_outcome_uncertain(&ExecutionError::Io(
            std::io::Error::from(std::io::ErrorKind::PermissionDenied)
        )));
    }
}
