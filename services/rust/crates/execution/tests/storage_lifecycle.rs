mod common;
use common::*;
use lims_execution::*;
use std::io::Read;
use tempfile::TempDir;
#[test]
fn persists_syncs_and_reopens_exact_readonly_result() {
    let dir = TempDir::new().unwrap();
    let storage = ExecutionStorage::new(dir.path()).unwrap();
    assert!(storage.readiness_probe());
    let c = claim();
    let stored = storage.persist(c.operation_uuid, PDF, 1024).unwrap();
    assert_eq!(stored.sha256, sha256(PDF));
    let mut opened = storage.open_verified(&stored_record(&c, &stored)).unwrap();
    let mut bytes = Vec::new();
    opened.input.read_to_end(&mut bytes).unwrap();
    assert_eq!(bytes, PDF);
    assert!(storage.persist(c.operation_uuid, PDF, 1024).is_err());
}
#[test]
fn recovers_persisted_result_after_lost_completion_response_without_resigning() {
    let dir = TempDir::new().unwrap();
    let storage = ExecutionStorage::new(dir.path()).unwrap();
    let id = claim().operation_uuid;
    let stored = storage.persist(id, PDF, 1024).unwrap();
    drop(storage);
    let restarted = ExecutionStorage::new(dir.path()).unwrap();
    let c = restarted
        .find_recovery_candidate(id, 1024)
        .unwrap()
        .unwrap();
    assert!(c.canonical);
    assert_eq!(c.sha256, stored.sha256);
    assert_eq!(restarted.promote_recovery_candidate(&c).unwrap(), stored);
}
#[test]
fn resumes_durable_temporary_result_before_rename() {
    let dir = TempDir::new().unwrap();
    let storage = ExecutionStorage::new(dir.path()).unwrap();
    let id = claim().operation_uuid;
    let temp = dir
        .path()
        .join(format!(".{id}.tmp-{}", uuid::Uuid::new_v4()));
    std::fs::write(&temp, PDF).unwrap();
    std::fs::File::open(&temp).unwrap().sync_all().unwrap();
    let c = storage.find_recovery_candidate(id, 1024).unwrap().unwrap();
    assert!(!c.canonical);
    let stored = storage.promote_recovery_candidate(&c).unwrap();
    assert_eq!(stored.sha256, sha256(PDF));
    assert!(!temp.exists());
    assert!(storage.result_path(id).exists());
}
#[test]
fn retirement_stage_restore_and_purge_preserve_descriptor() {
    let dir = TempDir::new().unwrap();
    let s = ExecutionStorage::new(dir.path()).unwrap();
    let c = claim();
    let stored = s.persist(c.operation_uuid, PDF, 1024).unwrap();
    let mut r = stored_record(&c, &stored);
    r.retirement_epoch = 1;
    r.retirement_phase = "stage_intent".into();
    r.retirement_staged_path = Some(s.stage_for_retirement(&r, 1).unwrap());
    assert!(!s.canonical_exists(&r).unwrap());
    assert!(s.staged_exists(&r).unwrap());
    assert_eq!(
        s.inspect_retirement_evidence(c.operation_uuid, 1, "staged", &stored.sha256, stored.size)
            .unwrap()
            .state,
        "staged"
    );
    s.restore_staged(&r).unwrap();
    let mut opened = s.open_verified(&r).unwrap();
    s.stage_for_retirement(&r, 1).unwrap();
    s.purge_staged(&r).unwrap();
    let mut bytes = Vec::new();
    opened.input.read_to_end(&mut bytes).unwrap();
    assert_eq!(bytes, PDF);
    assert_eq!(
        s.inspect_retirement_evidence(
            c.operation_uuid,
            1,
            "purge_intent",
            &stored.sha256,
            stored.size
        )
        .unwrap()
        .state,
        "missing"
    );
}
#[test]
fn normal_absence_and_invalid_snapshot_are_distinct() {
    let dir = TempDir::new().unwrap();
    let s = ExecutionStorage::new(dir.path()).unwrap();
    let id = claim().operation_uuid;
    assert!(s.find_recovery_candidate(id, 1024).unwrap().is_none());
    assert_eq!(
        s.inspect_retirement_evidence(id, 0, "none", &sha256(PDF), PDF.len() as u64)
            .unwrap()
            .state,
        "missing"
    );
    assert!(s
        .inspect_retirement_evidence(id, 0, "staged", &sha256(PDF), PDF.len() as u64)
        .is_err());
    assert!(s.persist(id, &[], 1024).is_err());
    assert!(s.persist(id, PDF, 1).is_err());
}
#[test]
fn retirement_intent_rename_is_recoverable_after_process_reopen() {
    let dir = TempDir::new().unwrap();
    let s = ExecutionStorage::new(dir.path()).unwrap();
    let c = claim();
    let stored = s.persist(c.operation_uuid, PDF, 1024).unwrap();
    let mut r = stored_record(&c, &stored);
    r.retirement_epoch = 8;
    r.retirement_phase = "stage_intent".into();
    r.retirement_staged_path = Some(s.stage_for_retirement(&r, 8).unwrap());
    drop(s);
    let recovered = ExecutionStorage::new(dir.path()).unwrap();
    assert!(!recovered.canonical_exists(&r).unwrap());
    recovered.verify_staged(&r).unwrap();
    recovered.restore_staged(&r).unwrap();
    assert!(recovered.open_verified(&r).is_ok());
}
