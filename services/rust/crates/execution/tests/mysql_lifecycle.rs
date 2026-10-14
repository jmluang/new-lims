//! Real MySQL test, opt-in only. No SQLite/H2 or ignored-success fallback.
//! Requires a fresh, empty, explicitly disposable database. See run-mysql-tests.sh.
#![cfg(feature = "mysql-integration")]
mod common;
use chrono::{Duration, Utc};
use common::*;
use lims_execution::*;
use serde_json::json;
use sqlx::{
    mysql::{MySqlConnectOptions, MySqlPoolOptions},
    Row,
};
use std::str::FromStr;
use tempfile::TempDir;

#[tokio::test(flavor = "multi_thread", worker_threads = 4)]
async fn real_mysql_claim_fencing_retry_result_and_retirement_lifecycle() {
    let url = std::env::var("PDF_EXECUTION_TEST_DATABASE_URL")
        .expect("BLOCKED: no isolated MySQL URL. This is not a passed test.");
    assert_eq!(
        std::env::var("PDF_EXECUTION_TEST_ALLOW_SCHEMA_CREATE").as_deref(),
        Ok("1"),
        "Explicit disposable schema opt-in required"
    );
    let options = MySqlConnectOptions::from_str(&url).expect("Invalid isolated test URL");
    assert!(
        matches!(options.get_host(), "localhost" | "127.0.0.1" | "::1"),
        "Only loopback isolated MySQL is allowed"
    );
    assert!(
        options
            .get_database()
            .is_some_and(|v| v.starts_with("lims_rust_execution_test_")),
        "Database must have disposable test prefix"
    );
    let pool = MySqlPoolOptions::new()
        .max_connections(8)
        .after_connect(|c, _| {
            Box::pin(async move {
                sqlx::query("SET time_zone = '+00:00'").execute(c).await?;
                Ok(())
            })
        })
        .connect_with(options)
        .await
        .expect("BLOCKED: cannot connect to isolated MySQL");
    let count: u64 =
        sqlx::query("SELECT CAST(COUNT(*) AS UNSIGNED) FROM information_schema.tables WHERE table_schema=DATABASE()")
            .fetch_one(&pool)
            .await
            .unwrap()
            .try_get(0)
            .unwrap();
    assert_eq!(count, 0, "Refusing to touch a nonempty schema");
    sqlx::raw_sql(include_str!("mysql_schema.sql"))
        .execute(&pool)
        .await
        .unwrap();
    let version: String = sqlx::query("SELECT VERSION()")
        .fetch_one(&pool)
        .await
        .unwrap()
        .try_get(0)
        .unwrap();
    println!("Real MySQL-protocol server: {version}; no production keys or services");
    let c = claim();
    seed_policy(&pool, &c).await;
    let repo = ExecutionRepository::new(pool.clone());
    assert!(repo.readiness_probe().await);
    let dir = TempDir::new().unwrap();
    let storage = ExecutionStorage::new(dir.path()).unwrap();
    seed_operation(&pool, &c).await;
    let first = repo.claim(&c).await.unwrap();
    assert!(first.newly_claimed);
    assert!(!repo.claim(&c).await.unwrap().newly_claimed);
    repo.mark_executing(c.operation_uuid, 90).await.unwrap();
    // A current Laravel worker may read status after takeover, but cannot cross the original key fence.
    sqlx::query("UPDATE pdf_signing_operations SET lease_epoch=8 WHERE operation_uuid=?")
        .bind(c.operation_uuid.to_string())
        .execute(&pool)
        .await
        .unwrap();
    let mut takeover = c.clone();
    takeover.lease_epoch = 8;
    assert_eq!(
        repo.require_matching_execution(&takeover.read_claim())
            .await
            .unwrap()
            .state,
        "executing"
    );
    assert!(repo.mark_private_key_started(&c).await.is_err());
    assert!(repo.mark_private_key_started(&takeover).await.is_err());
    let row = repo.find(c.operation_uuid).await.unwrap().unwrap();
    assert!(row.private_key_started_at.is_none());
    assert_eq!(row.authorized_lease_epoch, 7);
    let gate: u64 =
        sqlx::query("SELECT java_gate_version FROM pdf_signing_operations WHERE operation_uuid=?")
            .bind(c.operation_uuid.to_string())
            .fetch_one(&pool)
            .await
            .unwrap()
            .try_get(0)
            .unwrap();
    assert_eq!(
        gate, 1,
        "Rejected boundary must roll back owning-operation CAS"
    );
    // Resume original unexpired authorized worker for this fixture.
    sqlx::query("UPDATE pdf_signing_operations SET lease_epoch=7 WHERE operation_uuid=?")
        .bind(c.operation_uuid.to_string())
        .execute(&pool)
        .await
        .unwrap();
    repo.mark_private_key_started(&c).await.unwrap();
    assert!(repo.mark_private_key_started(&c).await.is_err());
    let stored = storage.persist(c.operation_uuid, PDF, 1024 * 1024).unwrap();
    let completed = repo
        .mark_completed(c.operation_uuid, &stored, &"7".repeat(64))
        .await
        .unwrap();
    assert_eq!(completed.state, "completed");
    assert_eq!(
        repo.mark_completed(c.operation_uuid, &stored, &"7".repeat(64))
            .await
            .unwrap()
            .lock_version,
        completed.lock_version
    );
    assert!(repo
        .open_verified_result(&c.read_claim(), &storage)
        .await
        .is_ok());
    // Reconstruct repository after a dropped result response: same durable identity.
    let restarted = ExecutionRepository::new(pool.clone());
    assert_eq!(
        restarted.claim(&c).await.unwrap().execution.result_sha256,
        Some(stored.sha256.clone())
    );
    assert!(restarted
        .open_verified_result(&c.read_claim(), &storage)
        .await
        .is_ok());
    let count: u64 = sqlx::query(
        "SELECT CAST(COUNT(*) AS UNSIGNED) FROM pdf_java_signing_executions WHERE operation_uuid=?",
    )
    .bind(c.operation_uuid.to_string())
    .fetch_one(&pool)
    .await
    .unwrap()
    .try_get(0)
    .unwrap();
    assert_eq!(count, 1);

    // Independent concurrent deliveries converge on a single unique execution.
    let mut concurrent = c.clone();
    concurrent.operation_uuid = uuid::Uuid::new_v4();
    seed_operation(&pool, &concurrent).await;
    let (a, b) = tokio::join!(repo.claim(&concurrent), restarted.claim(&concurrent));
    assert!(a.is_ok() || b.is_ok());
    assert!(!repo.claim(&concurrent).await.unwrap().newly_claimed);
    let count: u64 = sqlx::query(
        "SELECT CAST(COUNT(*) AS UNSIGNED) FROM pdf_java_signing_executions WHERE operation_uuid=?",
    )
    .bind(concurrent.operation_uuid.to_string())
    .fetch_one(&pool)
    .await
    .unwrap()
    .try_get(0)
    .unwrap();
    assert_eq!(count, 1);

    // Retry is explicit, before-key only, and checks backoff and expected lock version.
    let mut retry = c.clone();
    retry.operation_uuid = uuid::Uuid::new_v4();
    seed_operation(&pool, &retry).await;
    let claimed = repo.claim(&retry).await.unwrap();
    repo.mark_executing(retry.operation_uuid, 90).await.unwrap();
    let failed = repo
        .mark_pre_private_key_failure(retry.operation_uuid, "PDF_BUSY", &claimed.policy)
        .await
        .unwrap();
    assert_eq!(failed.retryability.as_deref(), Some("same_operation"));
    assert!(failed.next_retry_at.is_some());
    retry.java_gate_version = 1;
    retry.lease_epoch = 8;
    sqlx::query("UPDATE pdf_signing_operations SET lease_epoch=8 WHERE operation_uuid=?")
        .bind(retry.operation_uuid.to_string())
        .execute(&pool)
        .await
        .unwrap();
    assert!(repo
        .authorize_retry(&retry, failed.lock_version + 1)
        .await
        .is_err());
    // Laravel uses TIMESTAMP(0): MySQL can round fractional input up to the
    // next second. Wait against the real persisted deadline, not a fixed 1.1s.
    let remaining = (failed.next_retry_at.expect("persisted retry deadline") - Utc::now())
        .to_std()
        .unwrap_or_default();
    tokio::time::sleep(remaining + std::time::Duration::from_millis(100)).await;
    repo.authorize_retry(&retry, failed.lock_version)
        .await
        .unwrap();
    repo.mark_executing(retry.operation_uuid, 90).await.unwrap();
    repo.mark_private_key_started(&retry).await.unwrap();
    assert!(repo
        .mark_pre_private_key_failure(retry.operation_uuid, "PDF_BUSY", &claimed.policy)
        .await
        .is_err());
    let unknown = repo
        .mark_failure(
            retry.operation_uuid,
            "outcome_unknown",
            "none",
            "EXECUTION_DEADLINE_OUTCOME_UNKNOWN",
        )
        .await
        .unwrap();
    assert_eq!(unknown.retryability.as_deref(), Some("none"));
    assert!(repo
        .authorize_retry(&retry, unknown.lock_version)
        .await
        .is_err());

    // Retirement gate requires a matching canonical authorization and elapsed retention.
    assert!(repo.begin_retirement(c.operation_uuid).await.is_err());
    authorize_retirement(&pool, &completed).await;
    sqlx::query("UPDATE pdf_java_signing_executions SET retention_until=? WHERE operation_uuid=?")
        .bind((Utc::now() - Duration::seconds(1)).naive_utc())
        .bind(c.operation_uuid.to_string())
        .execute(&pool)
        .await
        .unwrap();
    let intent = repo.begin_retirement(c.operation_uuid).await.unwrap();
    assert_eq!(intent.retirement_phase, "stage_intent");
    // Normal crash point: filesystem rename durable; transaction acknowledgement not yet recorded.
    storage
        .stage_for_retirement(&intent, intent.retirement_epoch)
        .unwrap();
    let staged = restarted
        .apply_retirement_stage(c.operation_uuid, intent.retirement_epoch, &storage)
        .await
        .unwrap();
    assert_eq!(staged.retirement_phase, "staged");
    assert!(
        repo.begin_retirement_purge(c.operation_uuid, staged.retirement_epoch)
            .await
            .is_err(),
        "Grace period must be honored"
    );
    sqlx::query("UPDATE pdf_java_signing_executions SET evidence_hold_mask=1,evidence_hold_state='active',lock_version=lock_version+1 WHERE operation_uuid=?").bind(c.operation_uuid.to_string()).execute(&pool).await.unwrap();
    assert!(repo
        .begin_retirement_purge(c.operation_uuid, staged.retirement_epoch)
        .await
        .is_err());
    let restored = repo
        .apply_retirement_restore(c.operation_uuid, staged.retirement_epoch, &storage)
        .await
        .unwrap();
    assert_eq!(restored.retirement_phase, "none");
    assert_eq!(restored.result_integrity_state, "available");
    assert!(storage.open_verified(&restored).is_ok());
    sqlx::query("UPDATE pdf_java_signing_executions SET evidence_hold_mask=0,evidence_hold_state='none',legal_hold_until=?,lock_version=lock_version+1 WHERE operation_uuid=?").bind((Utc::now()+Duration::seconds(60)).naive_utc()).bind(c.operation_uuid.to_string()).execute(&pool).await.unwrap();
    assert!(
        repo.begin_retirement(c.operation_uuid).await.is_err(),
        "Legal hold blocks retirement"
    );
    sqlx::query(
        "UPDATE pdf_java_signing_executions SET legal_hold_until=NULL WHERE operation_uuid=?",
    )
    .bind(c.operation_uuid.to_string())
    .execute(&pool)
    .await
    .unwrap();
    let intent = repo.begin_retirement(c.operation_uuid).await.unwrap();
    let staged = repo
        .apply_retirement_stage(c.operation_uuid, intent.retirement_epoch, &storage)
        .await
        .unwrap();
    // Advance the test-only ledger clock to avoid a 60-second integration-test wait.
    sqlx::query("UPDATE pdf_java_signing_executions SET retirement_purge_not_before=? WHERE operation_uuid=?").bind((Utc::now()-Duration::seconds(1)).naive_utc()).bind(c.operation_uuid.to_string()).execute(&pool).await.unwrap();
    let purge = repo
        .begin_retirement_purge(c.operation_uuid, staged.retirement_epoch)
        .await
        .unwrap();
    storage.purge_staged(&purge).unwrap();
    let retired = restarted
        .apply_retirement_purge(c.operation_uuid, purge.retirement_epoch, &storage)
        .await
        .unwrap();
    assert_eq!(retired.retirement_phase, "retired");
    assert!(retired.bytes_deleted_at.is_some());
    println!("PASS: real MySQL duplicate delivery/CAS rollback, lease fencing, explicit retry, immutable result reopen, and stage/restore/purge restart lifecycle. Cryptographic signing is separately tested.");
    pool.close().await;
}
async fn seed_policy(pool: &sqlx::MySqlPool, c: &OperationClaim) {
    sqlx::query("INSERT INTO pdf_signing_policy_versions (id,version_uuid,policy_hash,immutable_at,digest_algorithm_oid,signature_algorithm_oid,organization_certificate_fingerprints,signing_material_version,key_locator,tsa_url_set,tsa_timeout_seconds,trust_bundle_hash,revocation_policy,reserved_size,pre_private_key_retry_backoff_seconds,pre_private_key_retryable_error_codes,java_execution_registration_timeout_seconds,java_execution_timeout_seconds,java_status_poll_policy,java_result_min_bytes_per_second,java_result_read_timeout_seconds,generated_revision_max_bytes,max_signature_increment_bytes,policy_manifest,config_bundle_hash) VALUES (1,?,?,CURRENT_TIMESTAMP,'2.16.840.1.101.3.4.2.1','1.2.840.113549.1.1.11','[]','local-test','no-key','[]',5,?,'{}',32768,'[1,2]','[\"PDF_BUSY\"]',30,90,'{}',1024,30,33554432,2097152,'{}',?)")
 .bind(c.policy_version_uuid.to_string()).bind(&c.policy_hash).bind("9".repeat(64)).bind(&c.config_bundle_hash).execute(pool).await.unwrap();
}
async fn seed_operation(pool: &sqlx::MySqlPool, c: &OperationClaim) {
    sqlx::query("INSERT INTO pdf_signing_operations (operation_uuid,idempotency_key,idempotency_scope_key,scope_type,actor_user_id,document_id,action,state,stage,lease_owner,lease_epoch,lease_expires_at,java_gate_version,operation_input_manifest_hash,input_fingerprint,expected_source_sha256,signing_policy_version_id,policy_hash,config_bundle_hash,appearance_manifest_hash,appearance_sha256,pdf_signature_role,target_field_name,expected_certificate_fingerprint,field_lock_policy_hash,audit_context,audit_context_hash) VALUES (?,?,?,'document',1,1,'fill_signature_field','processing','java_call',?,7,DATE_ADD(CURRENT_TIMESTAMP,INTERVAL 1 HOUR),0,?,?,?,?,?,?,?,?,?,?,?,?,'{}',?)")
 .bind(c.operation_uuid.to_string()).bind(c.operation_uuid.to_string()).bind(c.operation_uuid.to_string()).bind(uuid::Uuid::new_v4().to_string()).bind(&c.operation_input_manifest_hash).bind(&c.input_fingerprint).bind(&c.expected_source_sha256).bind(c.policy_version_id).bind(&c.policy_hash).bind(&c.config_bundle_hash).bind(&c.appearance_manifest_hash).bind(&c.appearance_sha256).bind(&c.pdf_signature_role).bind(&c.target_field_name).bind(&c.expected_certificate_fingerprint).bind(&c.field_lock_policy_hash).bind("a".repeat(64)).execute(pool).await.unwrap();
}
async fn authorize_retirement(pool: &sqlx::MySqlPool, e: &ExecutionRecord) {
    let manifest = json!({"operation_uuid":e.operation_uuid,"execution_result_path":e.result_path,"execution_result_sha256":e.result_sha256,"execution_result_size":e.result_size,"grace_seconds":60,"formal_revision_uuid":uuid::Uuid::new_v4(),"formal_revision_sha256":e.result_sha256});
    let hash = sha256(&serde_json::to_vec(&manifest).unwrap());
    sqlx::query("UPDATE pdf_signing_operations SET state='completed',result_retirement_not_before=?,result_retirement_authorization_expires_at=?,result_retirement_authorization_manifest=?,result_retirement_authorization_hash=? WHERE operation_uuid=?")
 .bind((Utc::now()-Duration::seconds(1)).naive_utc()).bind((Utc::now()+Duration::seconds(300)).naive_utc()).bind(sqlx::types::Json(manifest)).bind(hash).bind(e.operation_uuid.to_string()).execute(pool).await.unwrap();
}
