use axum::http::{HeaderMap, HeaderValue, Method};
use base64::{engine::general_purpose::STANDARD, Engine};
use hmac::{Hmac, Mac};
use lims_server::{config::Config, hmac::*};
use serde_json::{json, Value};
use sha2::Sha256;
fn vector() -> Value {
    serde_json::from_str(include_str!("../../../fixtures/pdf-hmac-v1-vectors.json")).unwrap()
}
fn config() -> Config {
    Config::from_lookup(|k| match k {
        "PDF_SERVICE_HMAC_KEYS" => Some(format!(
            "primary:{}",
            vector()["secret_base64"].as_str().unwrap()
        )),
        _ => None,
    })
    .unwrap()
}
fn headers() -> HeaderMap {
    let v = vector();
    let p = &v["positive"];
    let mut h = HeaderMap::new();
    for (name, key) in [
        ("x-pdf-auth-version", "version"),
        ("x-pdf-key-id", "key_id"),
        ("x-pdf-timestamp", "timestamp"),
        ("x-pdf-nonce", "nonce"),
        ("x-pdf-correlation-id", "correlation_uuid"),
        ("x-pdf-operation-id", "operation_uuid"),
        ("x-pdf-operation-uuid", "operation_uuid"),
        ("x-pdf-metadata-sha256", "metadata_sha256"),
        ("x-pdf-part-manifest-sha256", "part_manifest_sha256"),
        ("x-pdf-signature", "signature"),
    ] {
        h.insert(name, p[key].as_str().unwrap().parse().unwrap());
    }
    for (name, key) in [
        ("x-pdf-config-bundle-sha256", "config_bundle_hash"),
        ("x-pdf-input-fingerprint", "input_fingerprint"),
        (
            "x-pdf-operation-manifest-sha256",
            "operation_input_manifest_hash",
        ),
        ("x-pdf-policy-sha256", "policy_hash"),
        ("x-pdf-policy-version-uuid", "signing_policy_version_uuid"),
    ] {
        h.insert(name, p["metadata"][key].as_str().unwrap().parse().unwrap());
    }
    h.insert("x-pdf-lease-epoch", HeaderValue::from_static("3"));
    h.insert("x-pdf-policy-version-id", HeaderValue::from_static("7"));
    h
}
#[test]
fn java_php_shared_positive_vector() {
    let v = vector();
    let p = &v["positive"];
    assert_eq!(canonical_json(&p["metadata"]).unwrap(), p["metadata_jcs"]);
    assert_eq!(
        canonical_digest(&p["metadata"]).unwrap(),
        p["metadata_sha256"]
    );
    assert_eq!(
        canonical_json(&p["part_manifest"]).unwrap(),
        p["part_manifest_jcs"]
    );
    assert_eq!(
        canonical_digest(&p["part_manifest"]).unwrap(),
        p["part_manifest_sha256"]
    );
    let uri = p["path_and_query"].as_str().unwrap().parse().unwrap();
    verify_headers(&config(), &Method::POST, &uri, &headers(), 1786870800).unwrap();
    let key = STANDARD
        .decode(v["secret_base64"].as_str().unwrap())
        .unwrap();
    let mut mac = Hmac::<Sha256>::new_from_slice(&key).unwrap();
    mac.update(p["canonical"].as_str().unwrap().as_bytes());
    assert_eq!(hex::encode(mac.finalize().into_bytes()), p["signature"]);
}
#[test]
fn duplicate_noncanonical_expired_and_metadata_are_rejected() {
    let uri = vector()["positive"]["path_and_query"]
        .as_str()
        .unwrap()
        .parse()
        .unwrap();
    let mut h = headers();
    h.append("x-pdf-key-id", HeaderValue::from_static("primary"));
    assert_eq!(
        verify_headers(&config(), &Method::POST, &uri, &h, 1786870800)
            .err()
            .unwrap()
            .code,
        "PDF_HMAC_HEADER_AMBIGUOUS"
    );
    let mut h = headers();
    h.insert("x-pdf-signature", "A".repeat(64).parse().unwrap());
    assert_eq!(
        verify_headers(&config(), &Method::POST, &uri, &h, 1786870800)
            .err()
            .unwrap()
            .code,
        "PDF_HMAC_REQUIRED"
    );
    assert_eq!(
        verify_headers(&config(), &Method::POST, &uri, &headers(), 1786870861)
            .err()
            .unwrap()
            .code,
        "PDF_HMAC_EXPIRED"
    );
    assert!(verify_headers(&config(), &Method::POST, &uri, &headers(), 1786870860).is_ok());
    let mut h = headers();
    h.insert("x-pdf-policy-sha256", "a".repeat(64).parse().unwrap());
    assert_eq!(
        verify_headers(&config(), &Method::POST, &uri, &h, 1786870800)
            .err()
            .unwrap()
            .code,
        "PDF_HMAC_METADATA_MISMATCH"
    );
}
#[test]
fn nonoperation_signed_request_uses_body_manifest_and_unique_nonce() {
    let c = config();
    let correlation = "11111111-2222-4333-8444-555555555555";
    let body = b"{\"report\":\"XDP-1\"}";
    let metadata=canonical_digest(&json!({"correlation_uuid":correlation,"operation_uuid":"-","version":"pdf-request-metadata-v1"})).unwrap();
    let manifest = body_manifest(body, "application/json").unwrap();
    let canonical = [
        VERSION,
        "primary",
        "POST",
        "/api/pdf/contract",
        &metadata,
        &manifest,
        "1786870800",
        "nonce-valid-0001",
        correlation,
        "-",
    ]
    .join("\n");
    let mut mac = Hmac::<Sha256>::new_from_slice(&c.hmac_keys["primary"]).unwrap();
    mac.update(canonical.as_bytes());
    let signature = hex::encode(mac.finalize().into_bytes());
    let mut h = HeaderMap::new();
    for (n, v) in [
        ("x-pdf-auth-version", VERSION),
        ("x-pdf-key-id", "primary"),
        ("x-pdf-timestamp", "1786870800"),
        ("x-pdf-nonce", "nonce-valid-0001"),
        ("x-pdf-correlation-id", correlation),
        ("x-pdf-operation-id", "-"),
        ("x-pdf-metadata-sha256", &metadata),
        ("x-pdf-part-manifest-sha256", &manifest),
        ("x-pdf-signature", &signature),
    ] {
        h.insert(n, v.parse().unwrap());
    }
    verify_headers(
        &c,
        &Method::POST,
        &"/api/pdf/contract".parse().unwrap(),
        &h,
        1786870800,
    )
    .unwrap();
    assert!(verify_manifest(
        &body_manifest(b"{}", "application/json").unwrap(),
        &manifest
    )
    .is_err());
}
