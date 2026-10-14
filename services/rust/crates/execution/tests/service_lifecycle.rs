//! These tests use an explicitly fake in-memory ledger and counting signer.
//! They prove orchestration boundaries, NOT MySQL isolation or cryptography.
mod common;
use async_trait::async_trait;
use chrono::{Duration, Utc};
use common::*;
use lims_execution::*;
use serde_json::{json, Value};
use std::sync::{
    atomic::{AtomicBool, AtomicUsize, Ordering},
    Arc, Mutex,
};
use tempfile::TempDir;
use tokio::sync::Semaphore;
use uuid::Uuid;
struct FakeLedger {
    row: Mutex<Option<ExecutionRecord>>,
    marker: Arc<AtomicBool>,
    deny_marker: AtomicBool,
    lose_marker_response: AtomicBool,
    lose_completion_response: AtomicBool,
    completion_error_before_commit: Mutex<Option<sqlx::Error>>,
    failure_writes: AtomicUsize,
}
impl FakeLedger {
    fn new() -> Self {
        Self {
            row: Mutex::new(None),
            marker: Arc::new(AtomicBool::new(false)),
            deny_marker: AtomicBool::new(false),
            lose_marker_response: AtomicBool::new(false),
            lose_completion_response: AtomicBool::new(false),
            completion_error_before_commit: Mutex::new(None),
            failure_writes: AtomicUsize::new(0),
        }
    }
    fn update(&self, f: impl FnOnce(&mut ExecutionRecord)) -> ExecutionRecord {
        let mut row = self.row.lock().unwrap();
        let e = row.as_mut().unwrap();
        f(e);
        e.clone()
    }
}
#[async_trait]
impl ExecutionLedger for FakeLedger {
    async fn readiness_probe(&self) -> bool {
        true
    }
    async fn claim(&self, c: &OperationClaim) -> Result<ClaimResult> {
        let mut row = self.row.lock().unwrap();
        let new = row.is_none();
        let e = row.get_or_insert_with(|| record(c));
        Ok(ClaimResult {
            execution: e.clone(),
            newly_claimed: new,
            policy: policy(),
        })
    }
    async fn mark_executing(&self, _: Uuid, _: u32) -> Result<ExecutionRecord> {
        Ok(self.update(|r| {
            r.state = "executing".into();
            r.attempt_count += 1;
            r.attempt_number += 1;
            r.execution_deadline_at = Some(Utc::now() + Duration::seconds(90));
            r.lock_version += 1
        }))
    }
    async fn mark_private_key_started(&self, _: &OperationClaim) -> Result<ExecutionRecord> {
        if self.deny_marker.load(Ordering::SeqCst) {
            return Err(ExecutionError::Gate("Test marker commit rejected".into()));
        }
        let result = self.update(|r| {
            assert!(r.private_key_started_at.is_none());
            r.private_key_started_at = Some(Utc::now());
            r.lock_version += 1
        });
        self.marker.store(true, Ordering::SeqCst);
        if self.lose_marker_response.load(Ordering::SeqCst) {
            return Err(ExecutionError::OutcomeUncertain(
                "Test marker commit ACK lost".into(),
            ));
        }
        Ok(result)
    }
    async fn mark_completed(
        &self,
        _: Uuid,
        s: &StoredResult,
        hash: &str,
    ) -> Result<ExecutionRecord> {
        if let Some(error) = self.completion_error_before_commit.lock().unwrap().take() {
            return Err(ExecutionError::Database(error));
        }
        let result = self.update(|r| {
            assert!(r.private_key_started_at.is_some());
            r.state = "completed".into();
            r.result_path = Some(s.path.clone());
            r.result_sha256 = Some(s.sha256.clone());
            r.result_size = s.size;
            r.result_file_key = Some(s.file_key.clone());
            r.validation_report_hash = Some(hash.into());
            r.result_integrity_state = "available".into();
            r.lock_version += 1
        });
        if self.lose_completion_response.swap(false, Ordering::SeqCst) {
            return Err(ExecutionError::Database(sqlx::Error::PoolTimedOut));
        }
        Ok(result)
    }
    async fn mark_failure(
        &self,
        _: Uuid,
        state: &str,
        retryability: &str,
        code: &str,
    ) -> Result<ExecutionRecord> {
        self.failure_writes.fetch_add(1, Ordering::SeqCst);
        let existing = self.row.lock().unwrap().clone().unwrap();
        if !matches!(existing.state.as_str(), "claimed" | "executing") {
            return Ok(existing);
        }
        Ok(self.update(|r| {
            assert_eq!(
                state != "failed_before_private_key",
                r.private_key_started_at.is_some()
            );
            r.state = state.into();
            r.retryability = Some(retryability.into());
            r.error_code = Some(code.into());
            r.lock_version += 1
        }))
    }
    async fn mark_pre_private_key_failure(
        &self,
        id: Uuid,
        code: &str,
        p: &PolicySnapshot,
    ) -> Result<ExecutionRecord> {
        if self
            .row
            .lock()
            .unwrap()
            .as_ref()
            .unwrap()
            .private_key_started_at
            .is_some()
        {
            return Err(ExecutionError::Gate("Marker may have committed".into()));
        }
        self.mark_failure(
            id,
            "failed_before_private_key",
            if p.retryable_error_codes.contains(code) {
                "same_operation"
            } else {
                "none"
            },
            code,
        )
        .await
    }
    async fn authorize_retry(&self, _: &OperationClaim, _: u64) -> Result<ExecutionRecord> {
        Ok(self.update(|r| {
            assert!(r.private_key_started_at.is_none());
            r.state = "claimed".into()
        }))
    }
    async fn require_matching_execution(&self, _: &ExecutionReadClaim) -> Result<ExecutionRecord> {
        self.row
            .lock()
            .unwrap()
            .clone()
            .ok_or(ExecutionError::NotFound)
    }
    async fn recovery_maximum_bytes(&self, _: Uuid) -> Result<u64> {
        Ok(1024 * 1024)
    }
    async fn open_verified_result(
        &self,
        _: &ExecutionReadClaim,
        s: &ExecutionStorage,
    ) -> Result<OpenResult> {
        let r = self.row.lock().unwrap().clone().unwrap();
        s.open_verified(&r)
    }
}
struct CountingSigner {
    marker: Arc<AtomicBool>,
    key_count: Arc<AtomicUsize>,
    mode: &'static str,
    started: Arc<AtomicBool>,
    release: Arc<AtomicBool>,
}
impl ExecutionSigner for CountingSigner {
    fn preflight(&self, _: &[u8], _: &[u8], _: &Value, _: &str) -> Result<PreparedSignature> {
        assert!(!self.marker.load(Ordering::SeqCst));
        if self.mode == "preflight_failure" {
            return Err(ExecutionError::Invalid("Test preflight refusal".into()));
        }
        let marker = self.marker.clone();
        let key_count = self.key_count.clone();
        let mode = self.mode;
        let started = self.started.clone();
        let release = self.release.clone();
        Ok(PreparedSignature::new(move || {
            assert!(
                marker.load(Ordering::SeqCst),
                "Key called before durable marker"
            );
            key_count.fetch_add(1, Ordering::SeqCst);
            started.store(true, Ordering::SeqCst);
            if mode == "slow" {
                while !release.load(Ordering::SeqCst) {
                    std::thread::sleep(std::time::Duration::from_millis(5));
                }
            }
            match mode {
                "unknown" => Err(ExecutionError::OutcomeUncertain(
                    "Test lost TSA response".into(),
                )),
                "known" => Err(ExecutionError::Signing("Test known failure".into())),
                _ => Ok(PDF.to_vec()),
            }
        }))
    }
    fn verify(&self, _: &[u8]) -> Result<Value> {
        Ok(
            json!({"documentCurrentState":"valid","signatures":[{"testOnly":"fake verification; no cryptographic claim"}]}),
        )
    }
}
struct Fixture {
    _dir: TempDir,
    ledger: Arc<FakeLedger>,
    service: ExecutionService,
    count: Arc<AtomicUsize>,
    limiter: Arc<Semaphore>,
    started: Arc<AtomicBool>,
    release: Arc<AtomicBool>,
}
fn fixture(mode: &'static str) -> Fixture {
    let dir = TempDir::new().unwrap();
    let ledger = Arc::new(FakeLedger::new());
    let count = Arc::new(AtomicUsize::new(0));
    let started = Arc::new(AtomicBool::new(false));
    let release = Arc::new(AtomicBool::new(false));
    let signer = CountingSigner {
        marker: ledger.marker.clone(),
        key_count: count.clone(),
        mode,
        started: started.clone(),
        release: release.clone(),
    };
    let limiter = Arc::new(Semaphore::new(1));
    let service = ExecutionService::new(
        ledger.clone(),
        ExecutionStorage::new(dir.path()).unwrap(),
        Arc::new(signer),
        limiter.clone(),
    );
    Fixture {
        _dir: dir,
        ledger,
        service,
        count,
        limiter,
        started,
        release,
    }
}
async fn execute(f: &Fixture, c: &OperationClaim) -> Result<ExecutionRecord> {
    f.service
        .execute_claim(c, PDF.to_vec(), APPEARANCE.to_vec(), command())
        .await
}
#[tokio::test]
async fn duplicate_delivery_and_lost_response_never_resign() {
    let f = fixture("ok");
    let c = claim();
    let first = execute(&f, &c).await.unwrap();
    assert_eq!(first.state, "completed");
    let second = execute(&f, &c).await.unwrap();
    assert_eq!(second.result_sha256, first.result_sha256);
    assert_eq!(f.count.load(Ordering::SeqCst), 1);
    assert!(f.service.open_result_claim(&c.read_claim()).await.is_ok());
}
#[tokio::test]
async fn preflight_and_marker_rejection_cannot_use_private_key() {
    for mode in ["preflight_failure", "ok"] {
        let f = fixture(mode);
        if mode == "ok" {
            f.ledger.deny_marker.store(true, Ordering::SeqCst)
        }
        let r = execute(&f, &claim()).await.unwrap();
        assert_eq!(r.state, "failed_before_private_key");
        assert_eq!(f.count.load(Ordering::SeqCst), 0);
        assert!(!f.ledger.marker.load(Ordering::SeqCst));
    }
}
#[tokio::test]
async fn busy_is_only_prekey_retry_and_success_invokes_key_once() {
    let f = fixture("ok");
    let c = claim();
    let permit = f.limiter.clone().acquire_owned().await.unwrap();
    let r = execute(&f, &c).await.unwrap();
    assert_eq!(r.state, "failed_before_private_key");
    assert_eq!(r.retryability.as_deref(), Some("same_operation"));
    assert_eq!(r.error_code.as_deref(), Some("PDF_BUSY"));
    drop(permit);
    let r = execute(&f, &c).await.unwrap();
    assert_eq!(r.state, "completed");
    assert_eq!(r.attempt_count, 2);
    assert_eq!(f.count.load(Ordering::SeqCst), 1);
}
#[tokio::test]
async fn postkey_unknown_is_poll_only_until_deadline_without_result() {
    let f = fixture("unknown");
    let c = claim();
    assert!(matches!(
        execute(&f, &c).await,
        Err(ExecutionError::OutcomeUncertain(_))
    ));
    assert_eq!(execute(&f, &c).await.unwrap().state, "executing");
    f.ledger
        .update(|r| r.execution_deadline_at = Some(Utc::now() - Duration::seconds(1)));
    assert_eq!(
        f.service.status_claim(&c.read_claim()).await.unwrap().state,
        "outcome_unknown"
    );
    assert_eq!(execute(&f, &c).await.unwrap().state, "outcome_unknown");
    assert_eq!(f.count.load(Ordering::SeqCst), 1);
}
#[tokio::test]
async fn completed_bytes_survive_lost_database_response_and_restart() {
    let f = fixture("ok");
    let c = claim();
    let r = execute(&f, &c).await.unwrap();
    let hash = r.result_sha256;
    f.ledger.update(|r| {
        r.state = "executing".into();
        r.execution_deadline_at = Some(Utc::now() - Duration::seconds(1));
        r.result_integrity_state = "not_applicable".into();
        r.result_path = None;
        r.result_sha256 = None;
        r.result_file_key = None;
        r.result_size = 0;
    });
    let service = f.service.clone();
    let recovered = service.status_claim(&c.read_claim()).await.unwrap();
    assert_eq!(recovered.state, "completed");
    assert_eq!(recovered.result_sha256, hash);
    assert_eq!(f.count.load(Ordering::SeqCst), 1);
}
#[tokio::test]
async fn known_postkey_failure_is_terminal() {
    let f = fixture("known");
    let c = claim();
    let r = execute(&f, &c).await.unwrap();
    assert_eq!(r.state, "failed_after_private_key_known");
    assert_eq!(r.retryability.as_deref(), Some("none"));
    assert_eq!(execute(&f, &c).await.unwrap().state, r.state);
    assert_eq!(f.count.load(Ordering::SeqCst), 1);
}
#[tokio::test]
async fn before_key_deadline_does_not_resign() {
    let f = fixture("ok");
    let c = claim();
    f.ledger.claim(&c).await.unwrap();
    f.ledger.mark_executing(c.operation_uuid, 90).await.unwrap();
    f.ledger
        .update(|r| r.execution_deadline_at = Some(Utc::now() - Duration::seconds(1)));
    let r = f.service.status_claim(&c.read_claim()).await.unwrap();
    assert_eq!(r.state, "failed_before_private_key");
    assert_eq!(r.retryability.as_deref(), Some("none"));
    assert_eq!(f.count.load(Ordering::SeqCst), 0);
}
#[tokio::test]
async fn transport_cancellation_keeps_heavy_permit_until_key_worker_finishes() {
    let f = fixture("slow");
    let c = claim();
    let svc = f.service.clone();
    let cc = c.clone();
    let request = tokio::spawn(async move {
        svc.execute_claim(&cc, PDF.to_vec(), APPEARANCE.to_vec(), command())
            .await
    });
    tokio::time::timeout(std::time::Duration::from_secs(5), async {
        while !f.started.load(Ordering::SeqCst) {
            tokio::task::yield_now().await
        }
    })
    .await
    .unwrap();
    request.abort();
    assert_eq!(f.limiter.available_permits(), 0);
    f.release.store(true, Ordering::SeqCst);
    tokio::time::timeout(std::time::Duration::from_secs(5), async {
        while f.limiter.available_permits() == 0 {
            tokio::task::yield_now().await
        }
    })
    .await
    .unwrap();
    assert_eq!(
        f.ledger
            .require_matching_execution(&c.read_claim())
            .await
            .unwrap()
            .state,
        "completed"
    );
    assert_eq!(f.count.load(Ordering::SeqCst), 1);
}

#[tokio::test]
async fn graceful_shutdown_rejects_new_work_and_waits_for_detached_key_job() {
    let f = fixture("slow");
    let c = claim();
    let svc = f.service.clone();
    let cc = c.clone();
    let request = tokio::spawn(async move {
        svc.execute_claim(&cc, PDF.to_vec(), APPEARANCE.to_vec(), command())
            .await
    });
    tokio::time::timeout(std::time::Duration::from_secs(5), async {
        while !f.started.load(Ordering::SeqCst) {
            tokio::task::yield_now().await
        }
    })
    .await
    .unwrap();
    request.abort();
    f.service.begin_shutdown();
    assert!(matches!(
        execute(&f, &claim()).await,
        Err(ExecutionError::ShuttingDown)
    ));
    assert!(
        tokio::time::timeout(std::time::Duration::from_millis(20), f.service.wait_idle())
            .await
            .is_err()
    );
    f.release.store(true, Ordering::SeqCst);
    tokio::time::timeout(std::time::Duration::from_secs(5), f.service.wait_idle())
        .await
        .unwrap();
    assert_eq!(
        f.ledger
            .require_matching_execution(&c.read_claim())
            .await
            .unwrap()
            .state,
        "completed"
    );
    assert_eq!(f.limiter.available_permits(), 1);
}

#[tokio::test]
async fn lost_private_key_marker_ack_never_invokes_or_retries_key() {
    let f = fixture("ok");
    let c = claim();
    f.ledger.lose_marker_response.store(true, Ordering::SeqCst);
    assert!(execute(&f, &c).await.is_err());
    let e = f
        .ledger
        .require_matching_execution(&c.read_claim())
        .await
        .unwrap();
    assert_eq!(e.state, "executing");
    assert!(e.private_key_started_at.is_some());
    assert_eq!(f.count.load(Ordering::SeqCst), 0);
    assert_eq!(execute(&f, &c).await.unwrap().state, "executing");
    assert_eq!(f.count.load(Ordering::SeqCst), 0);
    f.ledger
        .update(|r| r.execution_deadline_at = Some(Utc::now() - Duration::seconds(1)));
    assert_eq!(
        f.service.status_claim(&c.read_claim()).await.unwrap().state,
        "outcome_unknown"
    );
}
#[tokio::test]
async fn lost_completion_commit_ack_returns_already_committed_result_without_resigning() {
    let f = fixture("ok");
    let c = claim();
    f.ledger
        .lose_completion_response
        .store(true, Ordering::SeqCst);
    let e = execute(&f, &c).await.unwrap();
    assert_eq!(e.state, "completed");
    assert_eq!(
        execute(&f, &c).await.unwrap().result_sha256,
        e.result_sha256
    );
    assert_eq!(f.count.load(Ordering::SeqCst), 1);
}

/// Exercises the actual service's Database(Io) classification with an exact SQLx
/// error value and actual durable file; this remains an explicit fake-ledger test,
/// not evidence that a real database connection was interrupted.
#[tokio::test]
async fn database_connection_reset_after_persist_stays_executing_then_recovers_without_resigning() {
    let f = fixture("ok");
    let c = claim();
    *f.ledger.completion_error_before_commit.lock().unwrap() = Some(sqlx::Error::Io(
        std::io::Error::from(std::io::ErrorKind::ConnectionReset),
    ));
    assert!(matches!(
        execute(&f, &c).await,
        Err(ExecutionError::Database(sqlx::Error::Io(_)))
    ));
    let row = f
        .ledger
        .require_matching_execution(&c.read_claim())
        .await
        .unwrap();
    assert_eq!(row.state, "executing");
    assert!(row.private_key_started_at.is_some());
    assert_eq!(
        f.ledger.failure_writes.load(Ordering::SeqCst),
        0,
        "Transport uncertainty must not write a known failure"
    );
    assert_eq!(f.count.load(Ordering::SeqCst), 1);
    let storage = ExecutionStorage::new(f._dir.path()).unwrap();
    let candidate = storage
        .find_recovery_candidate(c.operation_uuid, 1024 * 1024)
        .unwrap()
        .unwrap();
    assert!(candidate.canonical);
    assert_eq!(candidate.sha256, sha256(PDF));
    assert_eq!(execute(&f, &c).await.unwrap().state, "executing");
    f.ledger
        .update(|r| r.execution_deadline_at = Some(Utc::now() - Duration::seconds(1)));
    let recovered = f.service.status_claim(&c.read_claim()).await.unwrap();
    assert_eq!(recovered.state, "completed");
    assert_eq!(
        recovered.result_sha256.as_deref(),
        Some(candidate.sha256.as_str())
    );
    assert_eq!(f.count.load(Ordering::SeqCst), 1);
    assert_eq!(f.ledger.failure_writes.load(Ordering::SeqCst), 0);
}
#[tokio::test]
async fn database_protocol_error_after_persist_is_not_silently_reclassified_as_network_uncertainty()
{
    let f = fixture("ok");
    let c = claim();
    *f.ledger.completion_error_before_commit.lock().unwrap() =
        Some(sqlx::Error::Protocol("ordinary protocol error".into()));
    let row = execute(&f, &c).await.unwrap();
    assert_eq!(row.state, "failed_after_private_key_known");
    assert_eq!(f.ledger.failure_writes.load(Ordering::SeqCst), 1);
    assert_eq!(f.count.load(Ordering::SeqCst), 1);
}
