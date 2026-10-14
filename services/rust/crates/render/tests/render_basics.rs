use lims_render::*;
use lopdf::{Document, Object};
use serde_json::{Value, json};
fn verify(bytes: &[u8], report: &str) {
    let d = Document::load_mem(bytes).unwrap();
    assert!(!d.get_pages().is_empty());
    assert!(
        d.objects
            .values()
            .any(|o| matches!(o,Object::Dictionary(x) if x.get(b"ToUnicode").is_ok()))
    );
    let texts = d
        .extract_text(&d.get_pages().keys().copied().collect::<Vec<_>>())
        .unwrap();
    assert!(
        texts.contains(report),
        "report missing from native searchable text"
    );
}
#[test]
fn four_native_renderers() {
    let p: Value = serde_json::from_str(include_str!("fixtures/lighting.json")).unwrap();
    verify(&render_lighting_report(&p).unwrap(), "SYN-LIGHTING-001");
    let p: Value = serde_json::from_str(include_str!("fixtures/entrust.json")).unwrap();
    let b = render_entrust_order(&p).unwrap();
    assert!(!Document::load_mem(&b).unwrap().get_pages().is_empty());
    let p: Value = serde_json::from_str(include_str!("fixtures/contract.json")).unwrap();
    let b = render_contract(&p).unwrap();
    assert!(!Document::load_mem(&b).unwrap().get_pages().is_empty());
    let p = json!({"reportNumber":"SYN-LM79-001","labName":"合成实验室","sections":[]});
    verify(&render_lm79_report(&p).unwrap(), "SYN-LM79-001");
}
#[test]
fn json_coercion_lm79_and_negatives() {
    let p = json!({"reportNumber":123,"labName":true,"sections":[]});
    verify(&render_lm79_report(&p).unwrap(), "123");
    assert_eq!(
        render_lm79_report(&json!({"reportNumber":"SYN1","labName":"Lab","sections":[null]}))
            .unwrap_err()
            .status,
        422
    );
    assert_eq!(
        render_lm79_report(&json!({"reportNumber":{},"labName":"Lab","sections":[]}))
            .unwrap_err()
            .status,
        400
    );
    assert!(render_lighting_report(&json!({})).is_err());
    // Spring Boot's Jackson configuration ignores unknown DTO keys.
    assert!(
        render_lm79_report(
            &json!({"reportNumber":"SYN1","labName":"Lab","sections":[],"unknown":true})
        )
        .is_ok()
    );
}
