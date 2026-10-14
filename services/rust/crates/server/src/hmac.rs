//! PDF-HMAC-V1: exact Java/PHP canonical request contract, no URL normalization.
use crate::config::{token, Config};
use axum::http::{HeaderMap, Method, Uri};
use hmac::{Hmac, Mac};
use lims_core::{sha256, MultipartData, ServiceError, ServiceResult};
use serde_json::{json, Value};
use sha2::Sha256;
use std::collections::BTreeSet;
use subtle::ConstantTimeEq;

pub const VERSION: &str = "PDF-HMAC-V1";
pub const NONCE_TTL_SECONDS: u64 = 300;
pub const BODY_LIMIT: usize = 24 * 1024 * 1024;
pub const FILE_LIMIT: usize = 20 * 1024 * 1024;
pub const BODY_DEADLINE_SECONDS: u64 = 120;
pub const AUTH_HEADERS: &[&str] = &[
    "x-pdf-auth-version",
    "x-pdf-key-id",
    "x-pdf-timestamp",
    "x-pdf-nonce",
    "x-pdf-correlation-id",
    "x-pdf-operation-id",
    "x-pdf-metadata-sha256",
    "x-pdf-part-manifest-sha256",
    "x-pdf-signature",
    "x-pdf-operation-uuid",
    "x-pdf-lease-epoch",
    "x-pdf-operation-manifest-sha256",
    "x-pdf-input-fingerprint",
    "x-pdf-policy-version-id",
    "x-pdf-policy-version-uuid",
    "x-pdf-policy-sha256",
    "x-pdf-config-bundle-sha256",
];
#[derive(Clone)]
pub struct Authenticated {
    pub key_id: String,
    pub nonce: String,
    pub correlation_id: String,
    pub manifest: String,
}
fn reject(code: &str) -> ServiceError {
    ServiceError::new(401, code)
}
pub fn header<'a>(headers: &'a HeaderMap, name: &str) -> Option<&'a str> {
    headers.get(name)?.to_str().ok()
}
fn hash(value: Option<&str>) -> Option<String> {
    value
        .filter(|s| {
            s.len() == 64
                && s.bytes()
                    .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
        })
        .map(str::to_ascii_lowercase)
}
fn valid_uuid(value: &str) -> bool {
    uuid::Uuid::parse_str(value).is_ok_and(|u| u.to_string() == value)
}
fn ct_eq(a: &str, b: &str) -> bool {
    bool::from(a.as_bytes().ct_eq(b.as_bytes()))
}

pub fn verify_headers(
    config: &Config,
    method: &Method,
    uri: &Uri,
    headers: &HeaderMap,
    now: u64,
) -> ServiceResult<Authenticated> {
    for name in AUTH_HEADERS {
        if headers.get_all(*name).iter().count() > 1 {
            return Err(reject("PDF_HMAC_HEADER_AMBIGUOUS"));
        }
    }
    let get = |name| header(headers, name).unwrap_or("");
    let version = get("x-pdf-auth-version");
    let key_id = get("x-pdf-key-id");
    let timestamp = get("x-pdf-timestamp");
    let nonce = get("x-pdf-nonce");
    let correlation = get("x-pdf-correlation-id");
    let operation = get("x-pdf-operation-id");
    let metadata_hash = hash(header(headers, "x-pdf-metadata-sha256"));
    let manifest = hash(header(headers, "x-pdf-part-manifest-sha256"));
    let signature = hash(header(headers, "x-pdf-signature"));
    let timestamp_syntax = timestamp == "0"
        || (timestamp.len() <= 11
            && timestamp.starts_with(|c: char| matches!(c, '1'..='9'))
            && timestamp.bytes().all(|c| c.is_ascii_digit()));
    if version != VERSION
        || !token(key_id, 1, 64)
        || !timestamp_syntax
        || nonce.trim().is_empty()
        || !valid_uuid(correlation)
        || !(operation == "-" || valid_uuid(operation))
        || metadata_hash.is_none()
        || manifest.is_none()
        || signature.is_none()
        || method.as_str() != method.as_str().to_ascii_uppercase()
    {
        return Err(reject("PDF_HMAC_REQUIRED"));
    }
    if !token(nonce, 16, 128) {
        return Err(reject("PDF_HMAC_NONCE_INVALID"));
    }
    let seconds = timestamp
        .parse::<u64>()
        .map_err(|_| reject("PDF_HMAC_TIMESTAMP_INVALID"))?;
    if now.abs_diff(seconds) > config.max_clock_skew_seconds {
        return Err(reject("PDF_HMAC_EXPIRED"));
    }
    let secret = config
        .hmac_keys
        .get(key_id)
        .ok_or_else(|| reject("PDF_HMAC_KEY_UNKNOWN"))?;
    let target = canonical_target(uri).map_err(|_| reject("PDF_HMAC_CANONICALIZATION_INVALID"))?;
    let metadata = request_metadata(headers, correlation, operation)
        .map_err(|_| reject("PDF_HMAC_CANONICALIZATION_INVALID"))?;
    let metadata_hash = metadata_hash.unwrap();
    let manifest = manifest.unwrap();
    if !ct_eq(&canonical_digest(&metadata)?, &metadata_hash) {
        return Err(reject("PDF_HMAC_METADATA_MISMATCH"));
    }
    let canonical = [
        version,
        key_id,
        method.as_str(),
        &target,
        &metadata_hash,
        &manifest,
        timestamp,
        nonce,
        correlation,
        operation,
    ]
    .join("\n");
    let mut mac = Hmac::<Sha256>::new_from_slice(secret).expect("HMAC accepts any key length");
    mac.update(canonical.as_bytes());
    let supplied = hex::decode(signature.unwrap()).map_err(|_| reject("PDF_HMAC_REQUIRED"))?;
    mac.verify_slice(&supplied)
        .map_err(|_| reject("PDF_HMAC_INVALID"))?;
    Ok(Authenticated {
        key_id: key_id.into(),
        nonce: nonce.into(),
        correlation_id: correlation.into(),
        manifest,
    })
}

fn request_metadata(h: &HeaderMap, correlation: &str, operation: &str) -> ServiceResult<Value> {
    let bad = || ServiceError::bad_request("PDF_HMAC_CANONICALIZATION_INVALID");
    let mut value = json!({"correlation_uuid":correlation,"operation_uuid":operation,"version":"pdf-request-metadata-v1"});
    if operation == "-" {
        if AUTH_HEADERS[9..]
            .iter()
            .any(|n| header(h, n).is_some_and(|v| !v.trim().is_empty()))
        {
            return Err(bad());
        }
        return Ok(value);
    }
    if header(h, "x-pdf-operation-uuid") != Some(operation) {
        return Err(bad());
    }
    for (name, key) in [
        ("x-pdf-lease-epoch", "lease_epoch"),
        ("x-pdf-policy-version-id", "signing_policy_version_id"),
    ] {
        let n = header(h, name)
            .ok_or_else(bad)?
            .parse::<i64>()
            .map_err(|_| bad())?;
        if !(1..=9_007_199_254_740_991).contains(&n) {
            return Err(bad());
        }
        value[key] = json!(n);
    }
    for (name, key) in [
        (
            "x-pdf-operation-manifest-sha256",
            "operation_input_manifest_hash",
        ),
        ("x-pdf-input-fingerprint", "input_fingerprint"),
        ("x-pdf-policy-sha256", "policy_hash"),
        ("x-pdf-config-bundle-sha256", "config_bundle_hash"),
    ] {
        value[key] = json!(hash(header(h, name)).ok_or_else(bad)?);
    }
    let policy = header(h, "x-pdf-policy-version-uuid").ok_or_else(bad)?;
    if !valid_uuid(policy) {
        return Err(bad());
    }
    value["signing_policy_version_uuid"] = json!(policy);
    Ok(value)
}

pub fn canonical_digest(value: &Value) -> ServiceResult<String> {
    Ok(sha256(canonical_json(value)?.as_bytes()))
}
pub fn canonical_json(value: &Value) -> ServiceResult<String> {
    fn validate(v: &Value) -> bool {
        match v {
            Value::Null | Value::Bool(_) | Value::String(_) => true,
            Value::Number(n) => n
                .as_i64()
                .is_some_and(|x| (-9_007_199_254_740_991..=9_007_199_254_740_991).contains(&x)),
            Value::Array(a) => a.iter().all(validate),
            Value::Object(o) => o.iter().all(|(k, v)| {
                !k.is_empty() && k.bytes().all(|c| (32..=126).contains(&c)) && validate(v)
            }),
        }
    }
    if !validate(value) {
        return Err(ServiceError::bad_request("PDF_CANONICAL_JSON_INVALID"));
    }
    // serde_json's default map is BTreeMap. Explicit recursion keeps this true even
    // if another workspace dependency enables preserve_order by feature unification.
    fn sorted(v: &Value) -> Value {
        match v {
            Value::Object(o) => {
                let mut pairs = o.iter().collect::<Vec<_>>();
                pairs.sort_by_key(|(k, _)| *k);
                Value::Object(
                    pairs
                        .into_iter()
                        .map(|(k, v)| (k.clone(), sorted(v)))
                        .collect(),
                )
            }
            Value::Array(a) => Value::Array(a.iter().map(sorted).collect()),
            _ => v.clone(),
        }
    }
    serde_json::to_string(&sorted(value))
        .map_err(|_| ServiceError::internal("PDF_CANONICAL_JSON_INVALID"))
}
fn unreserved(c: u8) -> bool {
    c.is_ascii_alphanumeric() || b"-._~".contains(&c)
}
fn component(value: &str, path: bool, query: bool) -> bool {
    let b = value.as_bytes();
    let mut i = 0;
    while i < b.len() {
        let c = b[i];
        if c == b'%' {
            if i + 2 >= b.len()
                || !b[i + 1..=i + 2]
                    .iter()
                    .all(|x| x.is_ascii_digit() || (b'A'..=b'F').contains(x))
            {
                return false;
            }
            let decoded = u8::from_str_radix(&value[i + 1..=i + 2], 16).unwrap();
            if unreserved(decoded) || b"/\\".contains(&decoded) {
                return false;
            }
            i += 3;
            continue;
        }
        if !(unreserved(c)
            || b"!$&'()*+,;=:@".contains(&c)
            || (path && c == b'/')
            || (query && b"/?=".contains(&c)))
        {
            return false;
        }
        i += 1;
    }
    true
}
pub fn canonical_target(uri: &Uri) -> ServiceResult<String> {
    let bad = || ServiceError::bad_request("PDF_HMAC_CANONICALIZATION_INVALID");
    let path = uri.path();
    if !path.starts_with('/')
        || path.contains("//")
        || path.contains('\\')
        || path.split('/').any(|s| s == "." || s == "..")
        || !component(path, true, false)
    {
        return Err(bad());
    }
    if let Some(query) = uri.query() {
        if !query.is_empty() {
            let mut last = None;
            for pair in query.split('&') {
                let (key, value) = pair.split_once('=').unwrap_or((pair, ""));
                if key.is_empty()
                    || last.is_some_and(|prev| prev >= key)
                    || !component(key, false, true)
                    || !component(value, false, true)
                {
                    return Err(bad());
                }
                last = Some(key);
            }
        }
        Ok(format!("{path}?{query}"))
    } else {
        Ok(path.into())
    }
}
pub fn normalize_content_type(value: &str) -> String {
    if value.trim().is_empty() {
        return "application/octet-stream".into();
    }
    value
        .chars()
        .filter(|c| !c.is_ascii_whitespace())
        .collect::<String>()
        .to_ascii_lowercase()
}
pub fn body_manifest(body: &[u8], content_type: &str) -> ServiceResult<String> {
    let parts = if body.is_empty() {
        vec![]
    } else {
        vec![
            json!({"name":"body","content_type":normalize_content_type(content_type),"length":body.len(),"sha256":sha256(body)}),
        ]
    };
    canonical_digest(&json!({"parts":parts,"version":"pdf-part-manifest-v1"}))
}
pub fn multipart_manifest(data: &MultipartData, path: &str) -> ServiceResult<String> {
    validate_parts(data, path)?;
    let mut parts = vec![];
    for (name, value) in &data.fields {
        parts.push(json!({"name":name,"content_type":"text/plain;charset=utf-8","length":value.len(),"sha256":sha256(value.as_bytes())}));
    }
    for (name, value) in &data.files {
        parts.push(json!({"name":name,"content_type":normalize_content_type(&value.content_type),"length":value.bytes.len(),"sha256":sha256(&value.bytes)}));
    }
    parts.sort_by(|a, b| a["name"].as_str().cmp(&b["name"].as_str()));
    canonical_digest(&json!({"parts":parts,"version":"pdf-part-manifest-v1"}))
}
pub fn validate_parts(data: &MultipartData, path: &str) -> ServiceResult<()> {
    let bad = || reject("PDF_HMAC_BODY_MISMATCH");
    let (required, allowed): (&[&str], &[&str]) = match path {
        "/api/pdf/extract-cover"
        | "/internal/pdf/signatures/inspect"
        | "/internal/pdf/signatures/finalize-unsigned"
        | "/internal/pdf/signatures/verify" => (&["pdf"], &["pdf"]),
        "/internal/pdf/signatures/prepare" => (&["pdf", "field_plan"], &["pdf", "field_plan"]),
        "/internal/pdf/signatures/sign-existing-field" => (
            &["pdf", "appearance", "operation", "command"],
            &["pdf", "appearance", "operation", "command"],
        ),
        "/api/pdf/process" => (
            &["pdf", "mode"],
            &[
                "pdf",
                "mode",
                "perforation_image",
                "signature_appearance_image",
                "certificate_query_qr_code",
                "signature_contact",
                "signature_location",
                "signature_reason",
                "function_stamp_count",
                "certificate_query_qr_code_url",
                "report_number",
                "report_appid",
                "options[group_size]",
                "options[stamp_total_height_mm]",
                "options[signature_size_mm]",
                "options[signature_margin_mm]",
            ],
        ),
        _ => return Err(bad()),
    };
    let mut names = BTreeSet::new();
    for name in data.fields.keys().chain(data.files.keys()) {
        if !names.insert(name.as_str()) {
            return Err(bad());
        }
    }
    if !required.iter().all(|n| names.contains(n)) {
        return Err(bad());
    }
    let mut allowed = allowed
        .iter()
        .map(|s| s.to_string())
        .collect::<BTreeSet<_>>();
    if path == "/api/pdf/process" {
        let count = data
            .fields
            .get("function_stamp_count")
            .map(|s| s.parse::<i32>())
            .transpose()
            .map_err(|_| bad())?
            .unwrap_or(0);
        if !(0..=100).contains(&count) {
            return Err(bad());
        }
        for index in 0..count {
            let name = format!("function_stamp_{index}");
            if !names.contains(name.as_str()) {
                return Err(bad());
            }
            allowed.insert(name);
        }
    }
    if names.iter().any(|n| !allowed.contains(*n)) {
        return Err(bad());
    }
    Ok(())
}
pub fn verify_manifest(actual: &str, expected: &str) -> ServiceResult<()> {
    if ct_eq(actual, expected) {
        Ok(())
    } else {
        Err(reject("PDF_HMAC_BODY_MISMATCH"))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn canonical_path_is_not_normalized() {
        for v in [
            "/a?b=1&a=2",
            "/a?a=1&a=2",
            "/a/%2f",
            "/a/%41",
            "/a/../b",
            "/a//b",
        ] {
            assert!(canonical_target(&v.parse().unwrap()).is_err(), "{v}");
        }
        for v in ["/api/pdf/health", "/a?a=1&b=%E4%B8%AD", "/a?"] {
            assert_eq!(canonical_target(&v.parse().unwrap()).unwrap(), v);
        }
    }
    #[test]
    fn canonical_json_strict_numbers_keys_and_sorting() {
        assert_eq!(
            canonical_json(&json!({"z":"中/文","a":true})).unwrap(),
            "{\"a\":true,\"z\":\"中/文\"}"
        );
        for v in [json!(1.2), json!(9007199254740992i64), json!({"中":1})] {
            assert!(canonical_json(&v).is_err());
        }
    }
    #[test]
    fn manifest_vectors() {
        assert_eq!(
            body_manifest(b"", "").unwrap(),
            sha256(b"{\"parts\":[],\"version\":\"pdf-part-manifest-v1\"}")
        );
        assert_eq!(
            normalize_content_type(" Application/JSON ; charset=UTF-8 "),
            "application/json;charset=utf-8"
        );
        let mut d = MultipartData::default();
        d.fields.insert("mode".into(), "sign".into());
        d.files.insert(
            "pdf".into(),
            lims_core::FilePart {
                name: "pdf".into(),
                filename: Some("ignored.pdf".into()),
                content_type: "application/pdf".into(),
                bytes: b"pdf".to_vec(),
            },
        );
        assert!(multipart_manifest(&d, "/api/pdf/process").is_ok());
        d.fields.insert("extra".into(), "bad".into());
        assert!(multipart_manifest(&d, "/api/pdf/process").is_err());
    }
}
