use crate::{gate, model::*, sha256, storage::*, ExecutionError as E, Result};
use chrono::{DateTime, Duration, SecondsFormat, Utc};
use sqlx::{
    mysql::{MySqlArguments, MySqlPoolOptions},
    query::Query,
    MySql, MySqlConnection, MySqlPool, Row,
};
use std::time::Duration as StdDuration;
use uuid::Uuid;
#[path = "sql.rs"]
mod sql;

#[derive(Clone)]
pub struct ExecutionRepository {
    pool: MySqlPool,
}
impl ExecutionRepository {
    pub fn new(pool: MySqlPool) -> Self {
        Self { pool }
    }
    pub fn pool(&self) -> &MySqlPool {
        &self.pool
    }
    pub fn connect_lazy(url: &str, username: &str, password: &str) -> Result<Self> {
        let options = crate::database_connect_options(url, username, password)?;
        let pool = MySqlPoolOptions::new()
            .max_connections(10)
            .acquire_timeout(StdDuration::from_secs(10))
            .after_connect(|conn, _| {
                Box::pin(async move {
                    sqlx::query("SET time_zone = '+00:00'")
                        .execute(conn)
                        .await?;
                    Ok(())
                })
            })
            .connect_lazy_with(options);
        Ok(Self { pool })
    }
    pub async fn readiness_probe(&self) -> bool {
        sqlx::query("SELECT COUNT(*) FROM pdf_signing_policy_versions")
            .fetch_one(&self.pool)
            .await
            .is_ok()
    }
    pub async fn find(&self, id: Uuid) -> Result<Option<ExecutionRecord>> {
        let mut c = self.pool.acquire().await?;
        find(&mut c, id, false).await
    }
    pub async fn claim(&self, c: &OperationClaim) -> Result<ClaimResult> {
        let mut tx = self.pool.begin().await?;
        if let Some(existing) = find(&mut tx, c.operation_uuid, false).await? {
            validate_existing(&mut tx, &existing, c).await?;
            let policy = policy(&mut tx, c).await?;
            tx.commit().await?;
            return Ok(ClaimResult {
                execution: existing,
                newly_claimed: false,
                policy,
            });
        }
        let policy = policy(&mut tx, c).await?;
        let changed = snapshot(
            sqlx::query(sql::CLAIM_0)
                .bind(c.operation_uuid.to_string())
                .bind(c.java_gate_version)
                .bind(c.lease_epoch),
            c,
        )
        .execute(&mut *tx)
        .await?
        .rows_affected();
        if changed != 1 {
            let raced = find(&mut tx, c.operation_uuid, false).await?;
            tx.rollback().await?;
            if let Some(execution) = raced {
                return Ok(ClaimResult {
                    execution,
                    newly_claimed: false,
                    policy,
                });
            }
            return Err(gate("SIGN_AUTHORIZE gate rejected operation snapshot"));
        }
        let now = Utc::now().naive_utc();
        sqlx::query(sql::CLAIM_1)
            .bind(c.operation_uuid.to_string())
            .bind(&c.operation_input_manifest_hash)
            .bind(&c.input_fingerprint)
            .bind(&c.policy_hash)
            .bind(policy.maximum_attempts)
            .bind(c.lease_epoch)
            .bind(now)
            .bind(now)
            .bind(now)
            .execute(&mut *tx)
            .await?;
        let after = required(&mut tx, c.operation_uuid, false).await?;
        event(&mut tx, None, &after, "CLAIMED", None).await?;
        tx.commit().await?;
        Ok(ClaimResult {
            execution: after,
            newly_claimed: true,
            policy,
        })
    }
    pub async fn mark_executing(&self, id: Uuid, timeout_seconds: u32) -> Result<ExecutionRecord> {
        let mut tx = self.pool.begin().await?;
        let before = required(&mut tx, id, true).await?;
        if before.state != "claimed" {
            tx.commit().await?;
            return Ok(before);
        }
        let now = Utc::now();
        let deadline = now + Duration::seconds(timeout_seconds.into());
        cas(sqlx::query("UPDATE pdf_java_signing_executions SET state='executing', execution_started_at=?, execution_deadline_at=?, attempt_count=attempt_count+1, attempt_number=attempt_number+1, lock_version=lock_version+1, updated_at=CURRENT_TIMESTAMP WHERE operation_uuid=? AND state='claimed' AND attempt_count < max_attempts")
   .bind(now.naive_utc()).bind(deadline.naive_utc()).bind(id.to_string()).execute(&mut *tx).await?.rows_affected(),"EXECUTION_STARTED")?;
        let after = record_event(&mut tx, &before, "EXECUTION_STARTED", None).await?;
        tx.commit().await?;
        Ok(after)
    }
    /// The COMMIT is awaited before returning. Any commit error MUST prevent the signer from using the key.
    pub async fn mark_private_key_started(&self, c: &OperationClaim) -> Result<ExecutionRecord> {
        let mut tx = self.pool.begin().await?;
        authorize_gate(
            &mut tx,
            c,
            c.java_gate_version
                .checked_add(1)
                .ok_or_else(|| gate("Gate version overflow"))?,
        )
        .await?;
        let before = required(&mut tx, c.operation_uuid, true).await?;
        let now = Utc::now();
        if before.state != "executing"
            || before.private_key_started_at.is_some()
            || before.hold_blocks(now)
            || before.input_fingerprint != c.input_fingerprint
            || before.policy_hash != c.policy_hash
            || before.authorized_lease_epoch != c.lease_epoch
            || before.execution_deadline_at.is_none_or(|v| now >= v)
        {
            return Err(gate(
                "Private-key boundary is not available for this execution state",
            ));
        }
        cas(
            sqlx::query(sql::MARK_PRIVATE_KEY_STARTED_0)
                .bind(now.naive_utc())
                .bind(now.naive_utc())
                .bind(c.operation_uuid.to_string())
                .bind(&c.input_fingerprint)
                .bind(&c.policy_hash)
                .bind(c.lease_epoch)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "Private-key boundary",
        )?;
        let after = record_event(&mut tx, &before, "PRIVATE_KEY_STARTED", None).await?;
        tx.commit().await?;
        Ok(after)
    }
    pub async fn mark_completed(
        &self,
        id: Uuid,
        stored: &StoredResult,
        report_hash: &str,
    ) -> Result<ExecutionRecord> {
        let mut tx = self.pool.begin().await?;
        let before = required(&mut tx, id, true).await?;
        if before.state == "completed" {
            if before.result_path.as_deref() == Some(&stored.path)
                && before.result_sha256.as_deref() == Some(&stored.sha256)
                && before.result_size == stored.size
                && before.result_file_key.as_deref() == Some(&stored.file_key)
                && before.validation_report_hash.as_deref() == Some(report_hash)
            {
                tx.commit().await?;
                return Ok(before);
            }
            return Err(gate("Completed execution result identity is immutable"));
        }
        if before.state != "executing" || before.private_key_started_at.is_none() {
            return Err(gate(
                "Completed execution must have crossed private-key boundary",
            ));
        }
        let now = Utc::now();
        cas(
            sqlx::query(sql::MARK_COMPLETED_0)
                .bind(now.naive_utc())
                .bind(now.naive_utc())
                .bind(&stored.path)
                .bind(&stored.sha256)
                .bind(stored.size)
                .bind(&stored.file_key)
                .bind(report_hash)
                .bind((now + Duration::days(7)).naive_utc())
                .bind(now.naive_utc())
                .bind(id.to_string())
                .bind(&before.input_fingerprint)
                .bind(&before.policy_hash)
                .bind(before.lock_version)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "Completed execution",
        )?;
        let after = record_event(&mut tx, &before, "COMPLETED", None).await?;
        tx.commit().await?;
        Ok(after)
    }
    pub async fn mark_failure(
        &self,
        id: Uuid,
        state: &str,
        retryability: &str,
        code: &str,
    ) -> Result<ExecutionRecord> {
        if !matches!(
            state,
            "failed_before_private_key" | "failed_after_private_key_known" | "outcome_unknown"
        ) {
            return Err(E::Invalid("Unsupported failure state".into()));
        }
        let mut tx = self.pool.begin().await?;
        let before = required(&mut tx, id, true).await?;
        if !matches!(before.state.as_str(), "claimed" | "executing") {
            tx.commit().await?;
            return Ok(before);
        }
        if (state != "failed_before_private_key") != before.private_key_started_at.is_some() {
            return Err(gate(
                "Failure classification contradicts private-key boundary",
            ));
        }
        let now = Utc::now().naive_utc();
        cas(
            sqlx::query(sql::MARK_FAILURE_0)
                .bind(state)
                .bind(retryability)
                .bind(code)
                .bind(now)
                .bind(state)
                .bind(now)
                .bind(now)
                .bind(id.to_string())
                .bind(&before.input_fingerprint)
                .bind(&before.policy_hash)
                .bind(before.lock_version)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "Failure classification",
        )?;
        let after = record_event(&mut tx, &before, &state.to_uppercase(), Some(code)).await?;
        tx.commit().await?;
        Ok(after)
    }
    pub async fn mark_pre_private_key_failure(
        &self,
        id: Uuid,
        code: &str,
        p: &PolicySnapshot,
    ) -> Result<ExecutionRecord> {
        let mut tx = self.pool.begin().await?;
        let before = required(&mut tx, id, true).await?;
        if before.state != "executing" || before.private_key_started_at.is_some() {
            return Err(gate("Pre-key failure contradicts execution state"));
        }
        let now = Utc::now();
        let retryable =
            p.retryable_error_codes.contains(code) && before.attempt_count < before.max_attempts;
        let backoff = *p
            .retry_backoff_seconds
            .get(
                (before.attempt_count.saturating_sub(1) as usize)
                    .min(p.retry_backoff_seconds.len().saturating_sub(1)),
            )
            .ok_or_else(|| gate("Pre-key retry backoff is invalid"))?;
        let next = if retryable {
            Some(
                (now + Duration::seconds(
                    i64::try_from(backoff).map_err(|_| gate("Retry backoff overflow"))?,
                ))
                .naive_utc(),
            )
        } else {
            None
        };
        let exhausted =
            (!retryable && before.attempt_count >= before.max_attempts).then_some(now.naive_utc());
        cas(
            sqlx::query(sql::MARK_PRE_PRIVATE_KEY_FAILURE_0)
                .bind(if retryable { "same_operation" } else { "none" })
                .bind(code)
                .bind(now.naive_utc())
                .bind(next)
                .bind(exhausted)
                .bind(now.naive_utc())
                .bind(id.to_string())
                .bind(&before.input_fingerprint)
                .bind(&before.policy_hash)
                .bind(before.lock_version)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "Pre-key failure",
        )?;
        let after = record_event(&mut tx, &before, "FAILED_BEFORE_PRIVATE_KEY", Some(code)).await?;
        tx.commit().await?;
        Ok(after)
    }
    pub async fn authorize_retry(
        &self,
        c: &OperationClaim,
        version: u64,
    ) -> Result<ExecutionRecord> {
        let mut tx = self.pool.begin().await?;
        authorize_gate(&mut tx, c, c.java_gate_version).await?;
        let before = required(&mut tx, c.operation_uuid, true).await?;
        let now = Utc::now();
        if before.state != "failed_before_private_key"
            || before.retryability.as_deref() != Some("same_operation")
            || before.private_key_started_at.is_some()
            || before.next_retry_at.is_none_or(|v| now < v)
            || before.attempt_count >= before.max_attempts
            || before.lock_version != version
            || before.input_fingerprint != c.input_fingerprint
            || before.policy_hash != c.policy_hash
        {
            return Err(gate("Pre-key retry is not authorized by execution ledger"));
        }
        cas(
            sqlx::query(sql::AUTHORIZE_RETRY_0)
                .bind(c.lease_epoch)
                .bind(now.naive_utc())
                .bind(c.operation_uuid.to_string())
                .bind(&c.input_fingerprint)
                .bind(&c.policy_hash)
                .bind(version)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "Pre-key retry",
        )?;
        let after = record_event(&mut tx, &before, "RETRY_AUTHORIZED", None).await?;
        tx.commit().await?;
        Ok(after)
    }
    pub async fn recovery_maximum_bytes(&self, id: Uuid) -> Result<u64> {
        let row = sqlx::query(sql::RECOVERY_MAXIMUM_BYTES_0)
            .bind(id.to_string())
            .fetch_optional(&self.pool)
            .await?
            .ok_or_else(|| gate("Recovery policy snapshot does not exist"))?;
        let maximum: u64 = row.try_get(0)?;
        if maximum == 0 {
            return Err(gate("Recovery policy budget is invalid"));
        }
        Ok(maximum)
    }
    pub async fn require_matching_execution(
        &self,
        c: &ExecutionReadClaim,
    ) -> Result<ExecutionRecord> {
        let mut conn = self.pool.acquire().await?;
        let e = required(&mut conn, c.operation_uuid, false).await?;
        matching(&e, c)?;
        read_lease(&mut conn, c, &e).await?;
        Ok(e)
    }
    pub async fn open_verified_result(
        &self,
        c: &ExecutionReadClaim,
        storage: &ExecutionStorage,
    ) -> Result<OpenResult> {
        let mut tx = self.pool.begin().await?;
        let row = sqlx::query(
            "SELECT java_gate_version FROM pdf_signing_operations WHERE operation_uuid=?",
        )
        .bind(c.operation_uuid.to_string())
        .fetch_optional(&mut *tx)
        .await?
        .ok_or_else(|| gate("RESULT_READ operation does not exist"))?;
        let version: u64 = row.try_get(0)?;
        cas(
            sqlx::query(sql::OPEN_VERIFIED_RESULT_0)
                .bind(c.operation_uuid.to_string())
                .bind(version)
                .bind(c.lease_epoch)
                .bind(&c.operation_input_manifest_hash)
                .bind(&c.input_fingerprint)
                .bind(&c.policy_hash)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "RESULT_READ gate",
        )?;
        let before = required(&mut tx, c.operation_uuid, true).await?;
        if before.state != "completed" || before.result_integrity_state != "available" {
            return Err(gate("RESULT_READ execution is not available"));
        }
        matching(&before, c)?;
        read_lease(&mut tx, c, &before).await?;
        let opened = match storage.open_verified(&before) {
            Ok(v) => v,
            Err(error @ E::Missing) => {
                mark_integrity(&mut tx, &before, "missing", "RESULT_BYTES_MISSING").await?;
                tx.commit().await?;
                return Err(error);
            }
            Err(error @ E::Breached(_)) => {
                mark_integrity(&mut tx, &before, "breached", "RESULT_BYTES_BREACHED").await?;
                tx.commit().await?;
                return Err(error);
            }
            Err(error) => return Err(error),
        };
        let now = Utc::now().naive_utc();
        cas(
            sqlx::query(sql::OPEN_VERIFIED_RESULT_1)
                .bind(now)
                .bind(now)
                .bind(c.operation_uuid.to_string())
                .bind(&c.input_fingerprint)
                .bind(&c.policy_hash)
                .bind(before.lock_version)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "RESULT_READ verification",
        )?;
        record_event(&mut tx, &before, "RESULT_VERIFIED", None).await?;
        tx.commit().await?;
        Ok(opened)
    }
    pub async fn retirement_candidates(&self, limit: u32) -> Result<Vec<ExecutionRecord>> {
        sqlx::query(sql::RETIREMENT_CANDIDATES_0)
            .bind(limit.clamp(1, 500))
            .fetch_all(&self.pool)
            .await?
            .iter()
            .map(ExecutionRecord::from_row)
            .collect()
    }
    pub async fn begin_retirement(&self, id: Uuid) -> Result<ExecutionRecord> {
        let mut tx = self.pool.begin().await?;
        let auth = sqlx::query(sql::RETIREMENT_AUTHORIZATION_0)
            .bind(id.to_string())
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| gate("RESULT_RETIRE operation does not exist"))?;
        if auth.try_get::<&str, _>("state")? != "completed" {
            return Err(gate("RESULT_RETIRE operation is not completed"));
        }
        let manifest: serde_json::Value = auth
            .try_get::<Option<sqlx::types::Json<serde_json::Value>>, _>(
                "result_retirement_authorization_manifest",
            )?
            .ok_or_else(|| gate("RESULT_RETIRE authorization is missing"))?
            .0;
        let before = required(&mut tx, id, true).await?;
        let now = Utc::now();
        if before.state != "completed"
            || before.result_integrity_state != "available"
            || before.retirement_phase != "none"
            || before.retention_until.is_none_or(|v| now < v)
            || before.hold_blocks(now)
        {
            return Err(gate("RESULT_RETIRE_STAGE execution is not eligible"));
        }
        let hash: Option<String> = auth.try_get("result_retirement_authorization_hash")?;
        let canonical = canonical_json(&manifest)?;
        let calculated = sha256(&canonical);
        let not_before = instant(&auth, "result_retirement_not_before")?;
        let expires = instant(&auth, "result_retirement_authorization_expires_at")?;
        let grace = manifest
            .get("grace_seconds")
            .and_then(|v| v.as_i64())
            .unwrap_or(0);
        if hash.as_deref() != Some(&calculated)
            || not_before.is_none_or(|v| now < v)
            || expires.is_none_or(|v| now >= v)
            || manifest["operation_uuid"].as_str() != Some(&id.to_string())
            || manifest["execution_result_path"].as_str() != before.result_path.as_deref()
            || manifest["execution_result_sha256"].as_str() != before.result_sha256.as_deref()
            || manifest["execution_result_size"].as_u64() != Some(before.result_size)
            || grace < 60
        {
            return Err(gate("RESULT_RETIRE authorization snapshot is invalid"));
        }
        let version: u64 = auth.try_get("java_gate_version")?;
        cas(
            sqlx::query(sql::RETIREMENT_GATE_0)
                .bind(id.to_string())
                .bind(version)
                .bind(&hash)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "RESULT_RETIRE gate",
        )?;
        let epoch = before
            .retirement_epoch
            .checked_add(1)
            .ok_or_else(|| gate("Retirement epoch overflow"))?;
        let staged = format!(
            "{}.retirement-{epoch}",
            before
                .result_path
                .as_deref()
                .ok_or_else(|| gate("Result path is missing"))?
        );
        let purge = now
            .checked_add_signed(Duration::seconds(grace))
            .ok_or_else(|| gate("Retirement grace overflow"))?;
        cas(
            sqlx::query(sql::BEGIN_RETIREMENT_0)
                .bind(epoch)
                .bind(staged)
                .bind(now.naive_utc())
                .bind(purge.naive_utc())
                .bind(now.naive_utc())
                .bind(id.to_string())
                .bind(before.lock_version)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "RESULT_RETIRE_STAGE",
        )?;
        let after = retirement_event(&mut tx, &before, "RESULT_RETIRE_STAGE_INTENT").await?;
        tx.commit().await?;
        Ok(after)
    }
    pub async fn apply_retirement_stage(
        &self,
        id: Uuid,
        epoch: u64,
        storage: &ExecutionStorage,
    ) -> Result<ExecutionRecord> {
        let mut tx = self.pool.begin().await?;
        lock_completed_operation(&mut tx, id).await?;
        let before = required(&mut tx, id, true).await?;
        eligible_retirement(&before, "stage_intent", epoch, false)?;
        let canonical = storage.canonical_exists(&before)?;
        let staged = storage.staged_exists(&before)?;
        match (canonical, staged) {
            (true, true) => {
                return Err(gate(
                    "RESULT_RETIRE_STAGE_APPLY found duplicate evidence copies",
                ))
            }
            (false, true) => storage.verify_staged(&before)?,
            (true, false) => {
                if Some(storage.stage_for_retirement(&before, epoch)?)
                    != before.retirement_staged_path
                {
                    return Err(gate("RESULT_RETIRE_STAGE_APPLY path differs from ledger"));
                }
            }
            (false, false) => {
                return Err(gate(
                    "RESULT_RETIRE_STAGE_APPLY cannot locate exact result bytes",
                ))
            }
        }
        cas(
            sqlx::query(sql::APPLY_RETIREMENT_STAGE_0)
                .bind(Utc::now().naive_utc())
                .bind(id.to_string())
                .bind(epoch)
                .bind(before.lock_version)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "RESULT_RETIRE_STAGE_APPLY",
        )?;
        let after = retirement_event(&mut tx, &before, "RESULT_RETIRE_STAGED").await?;
        tx.commit().await?;
        Ok(after)
    }
    pub async fn begin_retirement_purge(&self, id: Uuid, epoch: u64) -> Result<ExecutionRecord> {
        let mut tx = self.pool.begin().await?;
        lock_completed_operation(&mut tx, id).await?;
        let before = required(&mut tx, id, true).await?;
        eligible_retirement(&before, "staged", epoch, true)?;
        cas(
            sqlx::query(sql::RETIREMENT_TRANSITION_0)
                .bind("purge_intent")
                .bind(Utc::now().naive_utc())
                .bind(id.to_string())
                .bind("staged")
                .bind(epoch)
                .bind(before.lock_version)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "RESULT_RETIRE_PURGE_INTENT",
        )?;
        let after = retirement_event(&mut tx, &before, "RESULT_RETIRE_PURGE_INTENT").await?;
        tx.commit().await?;
        Ok(after)
    }
    pub async fn apply_retirement_purge(
        &self,
        id: Uuid,
        epoch: u64,
        storage: &ExecutionStorage,
    ) -> Result<ExecutionRecord> {
        let mut tx = self.pool.begin().await?;
        lock_completed_operation(&mut tx, id).await?;
        let before = required(&mut tx, id, true).await?;
        eligible_retirement(&before, "purge_intent", epoch, true)?;
        if storage.canonical_exists(&before)? {
            return Err(gate(
                "RESULT_RETIRE_PURGE_APPLY found unexpected canonical evidence",
            ));
        }
        if storage.staged_exists(&before)? {
            storage.purge_staged(&before)?
        }
        let now = Utc::now().naive_utc();
        cas(
            sqlx::query(sql::APPLY_RETIREMENT_PURGE_0)
                .bind(now)
                .bind(now)
                .bind(id.to_string())
                .bind(epoch)
                .bind(before.lock_version)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "RESULT_RETIRE_PURGE_APPLY",
        )?;
        let after = retirement_event(&mut tx, &before, "RESULT_RETIRED").await?;
        tx.commit().await?;
        Ok(after)
    }
    pub async fn apply_retirement_restore(
        &self,
        id: Uuid,
        epoch: u64,
        storage: &ExecutionStorage,
    ) -> Result<ExecutionRecord> {
        let mut tx = self.pool.begin().await?;
        lock_completed_operation(&mut tx, id).await?;
        let before = required(&mut tx, id, true).await?;
        if !matches!(
            before.retirement_phase.as_str(),
            "stage_intent" | "staged" | "purge_intent"
        ) || before.retirement_epoch != epoch
            || before.evidence_hold_mask == 0
            || before.evidence_hold_state != "active"
        {
            return Err(gate("RESULT_RETIRE_RESTORE execution is not eligible"));
        }
        let mut canonical = storage.canonical_exists(&before)?;
        let staged = storage.staged_exists(&before)?;
        if canonical && staged {
            return Err(gate("RESULT_RETIRE_RESTORE found two evidence copies"));
        }
        if !canonical && staged {
            storage.restore_staged(&before)?;
            canonical = true
        }
        if !canonical {
            return Err(gate(
                "RESULT_RETIRE_RESTORE cannot locate exact result bytes",
            ));
        }
        drop(storage.open_verified(&before)?);
        cas(
            sqlx::query(sql::APPLY_RETIREMENT_RESTORE_0)
                .bind(Utc::now().naive_utc())
                .bind(id.to_string())
                .bind(epoch)
                .bind(before.lock_version)
                .execute(&mut *tx)
                .await?
                .rows_affected(),
            "RESULT_RETIRE_RESTORE",
        )?;
        let after = retirement_event(&mut tx, &before, "RESULT_RETIRE_RESTORED").await?;
        tx.commit().await?;
        Ok(after)
    }
}
fn snapshot<'a>(
    q: Query<'a, MySql, MySqlArguments>,
    c: &'a OperationClaim,
) -> Query<'a, MySql, MySqlArguments> {
    q.bind(&c.operation_input_manifest_hash)
        .bind(&c.input_fingerprint)
        .bind(&c.expected_source_sha256)
        .bind(c.policy_version_id)
        .bind(&c.policy_hash)
        .bind(&c.config_bundle_hash)
        .bind(&c.appearance_manifest_hash)
        .bind(&c.appearance_sha256)
        .bind(&c.pdf_signature_role)
        .bind(&c.target_field_name)
        .bind(&c.expected_certificate_fingerprint)
        .bind(&c.field_lock_policy_hash)
}
fn cas(affected: u64, label: &str) -> Result<()> {
    if affected == 1 {
        Ok(())
    } else {
        Err(gate(format!("{label} CAS lost")))
    }
}
async fn find(c: &mut MySqlConnection, id: Uuid, lock: bool) -> Result<Option<ExecutionRecord>> {
    let query = if lock {
        "SELECT * FROM pdf_java_signing_executions WHERE operation_uuid=? FOR UPDATE"
    } else {
        "SELECT * FROM pdf_java_signing_executions WHERE operation_uuid=?"
    };
    sqlx::query(query)
        .bind(id.to_string())
        .fetch_optional(c)
        .await?
        .as_ref()
        .map(ExecutionRecord::from_row)
        .transpose()
}
async fn required(c: &mut MySqlConnection, id: Uuid, lock: bool) -> Result<ExecutionRecord> {
    find(c, id, lock).await?.ok_or(E::NotFound)
}
async fn authorize_gate(
    conn: &mut MySqlConnection,
    c: &OperationClaim,
    version: u64,
) -> Result<()> {
    cas(
        snapshot(
            sqlx::query(sql::SIGN_AUTHORIZE_GATE_AT_VERSION_0)
                .bind(c.operation_uuid.to_string())
                .bind(version)
                .bind(c.lease_epoch),
            c,
        )
        .execute(conn)
        .await?
        .rows_affected(),
        "SIGN_AUTHORIZE private-key gate",
    )
}
async fn validate_existing(
    conn: &mut MySqlConnection,
    e: &ExecutionRecord,
    c: &OperationClaim,
) -> Result<()> {
    let retry = e.state == "failed_before_private_key"
        && e.retryability.as_deref() == Some("same_operation");
    if (!retry && e.authorized_lease_epoch != c.lease_epoch)
        || e.operation_input_manifest_hash != c.operation_input_manifest_hash
        || e.input_fingerprint != c.input_fingerprint
        || e.policy_hash != c.policy_hash
    {
        return Err(gate("Existing execution does not match immutable claim"));
    }
    if snapshot(
        sqlx::query(sql::VALIDATE_EXISTING_CLAIM_0).bind(c.operation_uuid.to_string()),
        c,
    )
    .fetch_optional(conn)
    .await?
    .is_none()
    {
        return Err(gate("Existing execution operation snapshot was modified"));
    }
    Ok(())
}
async fn policy(conn: &mut MySqlConnection, c: &OperationClaim) -> Result<PolicySnapshot> {
    let r = sqlx::query(sql::POLICY_0)
        .bind(c.policy_version_id)
        .fetch_optional(conn)
        .await?
        .ok_or_else(|| gate("Immutable policy does not exist"))?;
    if r.try_get::<&str, _>("version_uuid")? != c.policy_version_uuid.to_string()
        || r.try_get::<&str, _>("policy_hash")? != c.policy_hash
        || r.try_get::<&str, _>("config_bundle_hash")? != c.config_bundle_hash
        || r.try_get::<&str, _>("pades_profile")? != "B-T"
    {
        return Err(gate("Immutable PAdES-B-T policy snapshot does not match"));
    }
    let backoff: sqlx::types::Json<Vec<u64>> =
        r.try_get("pre_private_key_retry_backoff_seconds")?;
    if backoff.is_empty() || backoff.iter().any(|v| *v < 1) {
        return Err(gate("Pre-key retry backoff policy is invalid"));
    }
    let codes: sqlx::types::Json<std::collections::BTreeSet<String>> =
        r.try_get("pre_private_key_retryable_error_codes")?;
    Ok(PolicySnapshot {
        id: r.try_get("id")?,
        version_uuid: c.policy_version_uuid,
        policy_hash: c.policy_hash.clone(),
        config_bundle_hash: c.config_bundle_hash.clone(),
        execution_timeout_seconds: r.try_get("java_execution_timeout_seconds")?,
        maximum_attempts: u32::from(r.try_get::<u8, _>("pre_private_key_max_attempts")?),
        retry_backoff_seconds: backoff.0,
        retryable_error_codes: codes.0,
        maximum_generated_revision_bytes: r.try_get("generated_revision_max_bytes")?,
        maximum_signature_increment_bytes: r.try_get("max_signature_increment_bytes")?,
    })
}
fn matching(e: &ExecutionRecord, c: &ExecutionReadClaim) -> Result<()> {
    if e.operation_input_manifest_hash != c.operation_input_manifest_hash
        || e.input_fingerprint != c.input_fingerprint
        || e.policy_hash != c.policy_hash
    {
        return Err(gate(
            "Execution read metadata differs from immutable ledger",
        ));
    }
    Ok(())
}
fn instant(r: &sqlx::mysql::MySqlRow, col: &str) -> Result<Option<DateTime<Utc>>> {
    Ok(r.try_get::<Option<DateTime<Utc>>, _>(col)?)
}
async fn read_lease(
    conn: &mut MySqlConnection,
    c: &ExecutionReadClaim,
    e: &ExecutionRecord,
) -> Result<()> {
    let row = sqlx::query(sql::VALIDATE_EXECUTION_READ_LEASE_0)
        .bind(c.operation_uuid.to_string())
        .fetch_optional(conn)
        .await?
        .ok_or_else(|| gate("Execution read operation does not exist"))?;
    let state: &str = row.try_get("state")?;
    let epoch: u64 = row.try_get("lease_epoch")?;
    let owner: Option<String> = row.try_get("lease_owner")?;
    let expires = instant(&row, "lease_expires_at")?;
    let completed = state == "completed" && epoch == c.lease_epoch;
    let forensic =
        state == "manual_review" && owner.is_none() && e.authorized_lease_epoch == c.lease_epoch;
    let current = matches!(state, "processing" | "promoted")
        && epoch == c.lease_epoch
        && owner.is_some()
        && expires.is_some_and(|v| Utc::now() < v);
    if !(completed || forensic || current) {
        return Err(gate("Execution read lease is not current"));
    }
    Ok(())
}
async fn mark_integrity(
    conn: &mut MySqlConnection,
    b: &ExecutionRecord,
    state: &str,
    code: &str,
) -> Result<()> {
    cas(
        sqlx::query(sql::MARK_RESULT_INTEGRITY_0)
            .bind(state)
            .bind(code)
            .bind(b.operation_uuid.to_string())
            .bind(&b.input_fingerprint)
            .bind(&b.policy_hash)
            .bind(b.lock_version)
            .execute(&mut *conn)
            .await?
            .rows_affected(),
        "RESULT_INTEGRITY",
    )?;
    record_event(
        conn,
        b,
        &format!("RESULT_{}", state.to_uppercase()),
        Some(code),
    )
    .await?;
    Ok(())
}
async fn record_event(
    conn: &mut MySqlConnection,
    b: &ExecutionRecord,
    kind: &str,
    code: Option<&str>,
) -> Result<ExecutionRecord> {
    let after = required(conn, b.operation_uuid, false).await?;
    event(conn, Some(b), &after, kind, code).await?;
    Ok(after)
}
async fn event(
    conn: &mut MySqlConnection,
    b: Option<&ExecutionRecord>,
    a: &ExecutionRecord,
    kind: &str,
    code: Option<&str>,
) -> Result<()> {
    let now = Utc::now();
    let old = b.map(|v| v.state.as_str());
    let old_version = b.map_or(0, |v| v.lock_version);
    let material = format!(
        "{}|{}|{}|{}|{}|{}|{}|{}|{}",
        a.operation_uuid,
        a.attempt_number,
        kind,
        old.unwrap_or("null"),
        a.state,
        old_version,
        a.lock_version,
        code.unwrap_or("null"),
        java_instant(now)
    );
    sqlx::query(sql::APPEND_EVENT_0)
        .bind(a.operation_uuid.to_string())
        .bind(a.attempt_number)
        .bind(kind)
        .bind(old)
        .bind(&a.state)
        .bind(old_version)
        .bind(a.lock_version)
        .bind(code)
        .bind(now.naive_utc())
        .bind(sha256(material.as_bytes()))
        .execute(conn)
        .await?;
    Ok(())
}
async fn lock_completed_operation(conn: &mut MySqlConnection, id: Uuid) -> Result<()> {
    if sqlx::query("SELECT operation_uuid FROM pdf_signing_operations WHERE operation_uuid=? AND state='completed' FOR UPDATE").bind(id.to_string()).fetch_optional(conn).await?.is_none(){return Err(gate("RESULT_RETIRE owning operation is not completed"))}
    Ok(())
}
fn eligible_retirement(e: &ExecutionRecord, phase: &str, epoch: u64, grace: bool) -> Result<()> {
    let now = Utc::now();
    if e.retirement_phase != phase
        || e.retirement_epoch != epoch
        || e.hold_blocks(now)
        || (grace && e.retirement_purge_not_before.is_none_or(|v| now < v))
    {
        return Err(gate("RESULT_RETIRE execution is not eligible"));
    }
    Ok(())
}
async fn retirement_event(
    conn: &mut MySqlConnection,
    b: &ExecutionRecord,
    kind: &str,
) -> Result<ExecutionRecord> {
    let a = required(conn, b.operation_uuid, false).await?;
    let now = Utc::now();
    let material = format!(
        "{}|{}|{}|{}|{}|{}|{}|{}",
        b.operation_uuid,
        kind,
        b.retirement_phase,
        a.retirement_phase,
        a.retirement_epoch,
        b.lock_version,
        a.lock_version,
        java_instant(now)
    );
    sqlx::query(sql::APPEND_RETIREMENT_EVENT_0)
        .bind(a.operation_uuid.to_string())
        .bind(a.attempt_number)
        .bind(kind)
        .bind(&b.state)
        .bind(&a.state)
        .bind(&b.retirement_phase)
        .bind(&a.retirement_phase)
        .bind(b.lock_version)
        .bind(a.lock_version)
        .bind(a.authorized_lease_epoch)
        .bind(a.retirement_epoch)
        .bind(None::<&str>)
        .bind(now.naive_utc())
        .bind(sha256(material.as_bytes()))
        .execute(conn)
        .await?;
    Ok(a)
}
/// Jackson's ORDER_MAP_ENTRIES_BY_KEYS applies recursively. Do not depend on serde's preserve_order feature.
fn canonical_json(value: &serde_json::Value) -> Result<Vec<u8>> {
    fn sorted(v: &serde_json::Value) -> serde_json::Value {
        match v {
            serde_json::Value::Object(m) => {
                let mut pairs = m.iter().collect::<Vec<_>>();
                pairs.sort_by_key(|(k, _)| *k);
                let mut out = serde_json::Map::new();
                for (k, v) in pairs {
                    out.insert(k.clone(), sorted(v));
                }
                serde_json::Value::Object(out)
            }
            serde_json::Value::Array(a) => serde_json::Value::Array(a.iter().map(sorted).collect()),
            other => other.clone(),
        }
    }
    Ok(serde_json::to_vec(&sorted(value))?)
}
fn java_instant(now: DateTime<Utc>) -> String {
    let nanos = now.timestamp_subsec_nanos();
    let precision = if nanos == 0 {
        SecondsFormat::Secs
    } else if nanos.is_multiple_of(1_000_000) {
        SecondsFormat::Millis
    } else if nanos.is_multiple_of(1_000) {
        SecondsFormat::Micros
    } else {
        SecondsFormat::Nanos
    };
    now.to_rfc3339_opts(precision, true)
}

#[cfg(test)]
mod parity_tests {
    use super::*;
    #[test]
    fn retirement_manifest_canonicalization_matches_java_sorted_maps() {
        let value = serde_json::json!({"z":1,"a":{"y":2,"b":"中文/ok"},"list":[{"z":1,"a":2}]});
        assert_eq!(
            String::from_utf8(canonical_json(&value).unwrap()).unwrap(),
            r#"{"a":{"b":"中文/ok","y":2},"list":[{"a":2,"z":1}],"z":1}"#
        );
    }
    #[test]
    fn java_event_timestamp_precision_matches_instant_format() {
        for text in [
            "2026-10-09T13:00:00Z",
            "2026-10-09T13:00:00.100Z",
            "2026-10-09T13:00:00.123400Z",
            "2026-10-09T13:00:00.123456700Z",
        ] {
            let value = DateTime::parse_from_rfc3339(text)
                .unwrap()
                .with_timezone(&Utc);
            assert_eq!(java_instant(value), text);
        }
    }
}
