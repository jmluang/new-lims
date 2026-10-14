mod common;
use common::*;
use lims_execution::*;
use std::collections::BTreeMap;
fn headers(c: &OperationClaim) -> BTreeMap<String, String> {
    [
        ("x-pdf-operation-uuid", c.operation_uuid.to_string()),
        ("x-pdf-lease-epoch", c.lease_epoch.to_string()),
        (
            "x-pdf-operation-manifest-sha256",
            c.operation_input_manifest_hash.clone(),
        ),
        ("x-pdf-input-fingerprint", c.input_fingerprint.clone()),
        ("x-pdf-policy-sha256", c.policy_hash.clone()),
        ("x-pdf-policy-version-id", c.policy_version_id.to_string()),
        (
            "x-pdf-policy-version-uuid",
            c.policy_version_uuid.to_string(),
        ),
        ("x-pdf-config-bundle-sha256", c.config_bundle_hash.clone()),
    ]
    .into_iter()
    .map(|(k, v)| (k.into(), v))
    .collect()
}
#[test]
fn java_execution_metadata_matches_case_insensitive_headers() {
    let c = claim();
    let h = headers(&c);
    assert_eq!(
        read_headers(&c.operation_uuid.to_string(), &h).unwrap(),
        c.read_claim()
    );
    validate_headers(&h, &c).unwrap();
}
#[test]
fn missing_mismatch_invalid_headers_keep_distinct_status() {
    let c = claim();
    assert_eq!(
        read_headers(&c.operation_uuid.to_string(), &BTreeMap::new())
            .unwrap_err()
            .status,
        400
    );
    let mut h = headers(&c);
    h.insert(
        "x-pdf-operation-uuid".into(),
        uuid::Uuid::new_v4().to_string(),
    );
    assert_eq!(
        read_headers(&c.operation_uuid.to_string(), &h)
            .unwrap_err()
            .status,
        401
    );
    let mut h = headers(&c);
    h.insert("x-pdf-lease-epoch".into(), "bad".into());
    assert_eq!(
        read_headers(&c.operation_uuid.to_string(), &h)
            .unwrap_err()
            .status,
        400
    );
}
#[test]
fn frozen_inputs_and_response_contract_match_php() {
    let c = claim();
    validate_frozen_inputs(&c, &policy(), PDF, APPEARANCE, &command()).unwrap();
    let mut cmd = command();
    cmd["fieldName"] = "other".into();
    assert!(validate_frozen_inputs(&c, &policy(), PDF, APPEARANCE, &cmd).is_err());
    let mut r = record(&c);
    assert!(!r.is_terminal());
    let response = r.response();
    assert_eq!(response["maximumAttempts"], 3);
    assert_eq!(response["resultSize"], 0);
    assert!(response.get("resultUrl").is_none());
    r.state = "completed".into();
    r.result_integrity_state = "available".into();
    assert!(r.is_terminal());
    assert!(r.response()["resultUrl"]
        .as_str()
        .unwrap()
        .ends_with("/result"));
}
#[test]
fn required_http_states_have_stable_error_codes() {
    for (e, status, code) in [
        (ExecutionError::NotFound, 404, "EXECUTION_NOT_FOUND"),
        (
            ExecutionError::Gate("stale".into()),
            409,
            "EXECUTION_GATE_REJECTED",
        ),
        (
            ExecutionError::Unavailable,
            409,
            "EXECUTION_RESULT_UNAVAILABLE",
        ),
        (ExecutionError::NotReady, 503, "EXECUTION_LEDGER_NOT_READY"),
        (
            ExecutionError::Missing,
            503,
            "EXECUTION_RESULT_INTEGRITY_ERROR",
        ),
        (ExecutionError::Busy, 503, "PDF_BUSY"),
    ] {
        let mapped = error_response(e);
        assert_eq!((mapped.status, mapped.code.as_str()), (status, code));
    }
}
