//! Render-only Jackson String coercion from original JSON number tokens.
//! Ordinary Value parsing remains unchanged for all non-String DTO targets.
use anyhow::{Context, Result, ensure};
use serde::{
    Deserialize, Deserializer,
    de::{MapAccess, Visitor},
};
use serde_json::{Value, value::RawValue};
use std::fmt;

struct Fields<'a>(Vec<(String, &'a RawValue)>);
impl<'de> Deserialize<'de> for Fields<'de> {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> std::result::Result<Self, D::Error> {
        struct FieldsVisitor;
        impl<'de> Visitor<'de> for FieldsVisitor {
            type Value = Fields<'de>;
            fn expecting(&self, f: &mut fmt::Formatter) -> fmt::Result {
                f.write_str("a JSON object")
            }
            fn visit_map<M: MapAccess<'de>>(
                self,
                mut map: M,
            ) -> std::result::Result<Self::Value, M::Error> {
                let mut fields = Vec::new();
                while let Some(entry) = map.next_entry::<String, &'de RawValue>()? {
                    fields.push(entry)
                }
                Ok(Fields(fields))
            }
        }
        deserializer.deserialize_map(FieldsVisitor)
    }
}

pub(crate) fn parse(kind: &str, bytes: &[u8]) -> Result<Value> {
    let schemas: Value = serde_json::from_str(include_str!("dto-schema.json"))?;
    let schema = &schemas[kind];
    let root = schema["root"].as_str().context("Unknown renderer")?;
    let raw: &RawValue = serde_json::from_slice(bytes)?;
    let mut value = node(root, raw, &schema["records"])?;
    crate::schema::validate_http(kind, &mut value)?;
    Ok(value)
}
fn node(target: &str, raw: &RawValue, records: &Value) -> Result<Value> {
    let token = raw.get();
    if target == "String"
        && token
            .as_bytes()
            .first()
            .is_some_and(|b| *b == b'-' || b.is_ascii_digit())
    {
        // Jackson's default stream constraint counts numeric digits, not a
        // conversion through f64. Do not lose trailing zeros or exponent case.
        ensure!(
            token.bytes().filter(u8::is_ascii_digit).count() <= 1000,
            "JSON numeric token exceeds Jackson's default length limit"
        );
        return Ok(Value::String(token.to_owned()));
    }
    if let Some(inner) = target
        .strip_prefix("List<")
        .and_then(|s| s.strip_suffix('>'))
    {
        if token.starts_with('[') {
            let items: Vec<&RawValue> = serde_json::from_str(token)?;
            return items
                .into_iter()
                .map(|value| node(inner, value, records))
                .collect::<Result<Vec<_>>>()
                .map(Value::Array);
        }
    } else if let Some(record) = records.get(target).and_then(Value::as_object)
        && token.starts_with('{')
    {
        let Fields(fields) = serde_json::from_str::<Fields<'_>>(token)?;
        let mut out = serde_json::Map::new();
        for (key, value) in fields {
            let converted = if let Some(field) = record.get(&key) {
                node(
                    field["type"].as_str().context("Invalid DTO schema")?,
                    value,
                    records,
                )?
            } else {
                serde_json::from_str(value.get())?
            };
            out.insert(key, converted);
        }
        return Ok(Value::Object(out));
    }
    // Integer, Double, Object, Map, byte[] and all other targets retain the
    // existing serde_json Value representation and existing coercion checks.
    Ok(serde_json::from_str(token)?)
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn only_string_targets_keep_number_lexemes() {
        let p=parse("lm79",br#"{"reportNumber":1.00,"labName":"Synthetic","applicant":1e2,"sections":[{"title":"S","headers":[1E2,"B"],"rows":[[1.00,"X"]]}],"spectrum":[[1e2,0.10],[2e2,1.00]]}"#).unwrap();
        assert_eq!(p["reportNumber"], "1.00");
        assert_eq!(p["applicant"], "1e2");
        assert_eq!(p["sections"][0]["headers"][0], "1E2");
        assert_eq!(p["sections"][0]["rows"][0][0], "1.00");
        assert!(p["spectrum"][0][0].is_number());
        assert_eq!(p["spectrum"][0][0].as_f64(), Some(100.));
        let p = parse(
            "entrust",
            br#"{"samples":[{"quantity":1.99}],"client":{"phone":1e2}}"#,
        )
        .unwrap();
        assert_eq!(p["samples"][0]["quantity"], 1);
        assert_eq!(p["client"]["phone"], "1e2");
        let p=parse("contract",br#"{"meta":{"contractId":1e2},"template_data":{"scope":{"number":1.00,"exponent":1e2}}}"#).unwrap();
        assert_eq!(p["meta"]["contractId"], 100);
        assert_eq!(p["template_data"]["scope"]["number"].to_string(), "1.0");
        assert_eq!(p["template_data"]["scope"]["exponent"].to_string(), "100.0");
    }
    #[test]
    fn ordinary_value_and_raw_request_bytes_do_not_change() {
        let raw = br#"{"reportNumber":1.00,"labName":"Synthetic","sections":[]}"#;
        let before = lims_core::sha256(raw);
        let ordinary: Value = serde_json::from_slice(raw).unwrap();
        assert_eq!(ordinary["reportNumber"].to_string(), "1.0");
        let _ = parse("lm79", raw).unwrap();
        assert_eq!(lims_core::sha256(raw), before);
        let sql_json: Value =
            serde_json::from_str(r#"{"number":1.00,"exponent":1e2,"integer":7}"#).unwrap();
        assert_eq!(
            serde_json::to_string(&sql_json).unwrap(),
            r#"{"number":1.0,"exponent":100.0,"integer":7}"#
        );
    }
    #[test]
    fn string_coercion_does_not_accept_structured_values_or_invalid_json() {
        assert!(
            parse(
                "lm79",
                br#"{"reportNumber":{"$serde_json::private::Number":"1.00"}}"#
            )
            .is_err()
        );
        assert!(parse("lm79", br#"{"reportNumber":01}"#).is_err());
        assert!(parse("entrust", br#"{"client":{"name":1.00,"company_name":1e2}}"#).is_err());
        assert_eq!(
            parse("lm79", br#"{"reportNumber":1e500}"#).unwrap()["reportNumber"],
            "1e500"
        );
    }
}
