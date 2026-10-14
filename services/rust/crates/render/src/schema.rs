//! Structural and scalar coercion boundary from the pinned Jackson Java record DTOs.
use super::*;
use serde_json::Value as V;
#[cfg(test)]
pub(super) fn validate(renderer: &str, p: &mut V) -> Result<()> {
    let schemas: V = serde_json::from_str(include_str!("dto-schema.json"))?;
    let schema = &schemas[renderer];
    let root = schema["root"].as_str().context("Unknown renderer")?;
    value(p, root, &schema["records"], false)
}
pub(super) fn validate_http(renderer: &str, p: &mut V) -> Result<()> {
    let schemas: V = serde_json::from_str(include_str!("dto-schema.json"))?;
    let schema = &schemas[renderer];
    value(
        p,
        schema["root"].as_str().context("Unknown renderer")?,
        &schema["records"],
        true,
    )
}
fn value(v: &mut V, t: &str, records: &V, ignore_unknown: bool) -> Result<()> {
    if v.is_null() {
        return Ok(());
    }
    if t == "String" {
        match v {
            V::String(_) => (),
            V::Number(n) => *v = V::String(n.to_string()),
            V::Bool(b) => *v = V::String(b.to_string()),
            _ => bail!("Cannot deserialize String from structured value"),
        };
        return Ok(());
    }
    if matches!(t, "int" | "Integer" | "Long") {
        let n = match &*v {
            V::Number(n) => n.as_i64().or_else(|| n.as_f64().map(|n| n as i64)),
            V::String(s) => {
                if s.trim_matches(|c: char| c <= '\u{20}').is_empty()
                    || s.trim_matches(|c: char| c <= '\u{20}') == "null"
                {
                    None
                } else {
                    Some(s.trim_matches(|c: char| c <= '\u{20}').parse::<i64>()?)
                }
            }
            _ => bail!("Cannot deserialize {t} from value"),
        };
        if let Some(n) = n {
            ensure!(
                t == "Long" || (n >= i32::MIN as i64 && n <= i32::MAX as i64),
                "Integer out of range"
            );
            *v = V::Number(n.into());
        } else {
            *v = V::Null;
        }
        return Ok(());
    }
    if t == "Double" {
        match &*v {
            V::Number(_) => (),
            V::String(s) => {
                let s = s.trim_matches(|c: char| c <= '\u{20}');
                *v = if s.is_empty() || s == "null" {
                    V::Null
                } else {
                    serde_json::Number::from_f64(s.parse::<f64>()?)
                        .map(V::Number)
                        .unwrap_or_else(|| V::String(s.to_owned()))
                };
            }
            _ => bail!("Cannot deserialize Double"),
        }
        return Ok(());
    }
    if t == "Boolean" {
        match &*v {
            V::Bool(_) => (),
            V::Number(n) => *v = V::Bool(n.as_i64().context("Boolean cannot use decimal")? != 0),
            V::String(s) => {
                let s = s.trim_matches(|c: char| c <= '\u{20}');
                *v = if matches!(s, "true" | "True" | "TRUE") {
                    V::Bool(true)
                } else if matches!(s, "false" | "False" | "FALSE") {
                    V::Bool(false)
                } else if s.is_empty() || s == "null" {
                    V::Null
                } else {
                    bail!("Cannot deserialize Boolean")
                };
            }
            _ => bail!("Cannot deserialize Boolean"),
        };
        return Ok(());
    }
    if t == "byte[]" {
        match v {
            V::String(s) => {
                let mut compact = String::new();
                for c in s.chars() {
                    if (c as u32) <= 32 {
                        ensure!(
                            compact.len().is_multiple_of(4),
                            "Base64 whitespace is only allowed between four-character groups"
                        );
                    } else {
                        compact.push(c);
                    }
                }
                base64::engine::general_purpose::STANDARD
                    .decode(compact.as_bytes())
                    .context("Invalid Base64")?;
                *s = compact;
            }
            V::Array(a) => {
                for b in a {
                    let n = b.as_i64().context("Invalid byte array value")?;
                    ensure!((-128..=255).contains(&n), "Byte value out of range");
                    *b = V::Number((n as u8).into());
                }
            }
            _ => bail!("Byte array must be Base64 or array"),
        };
        return Ok(());
    }
    if let Some(t) = t.strip_prefix("List<").and_then(|t| t.strip_suffix('>')) {
        for item in v.as_array_mut().context("DTO list must be an array")? {
            value(item, t, records, ignore_unknown)?;
        }
        return Ok(());
    }
    if t.starts_with("Map<") {
        ensure!(v.is_object(), "DTO map must be an object");
        return Ok(());
    }
    if t == "Object" {
        return Ok(());
    }
    let definition = records[t]
        .as_object()
        .with_context(|| format!("Unknown DTO record {t}"))?;
    let obj = v
        .as_object_mut()
        .with_context(|| format!("DTO {t} must be an object"))?;
    let original = std::mem::take(obj);
    let mut assigned = std::collections::HashSet::new();
    for (k, mut v) in original {
        let Some(field) = definition.get(&k) else {
            ensure!(ignore_unknown, "Unrecognized DTO field {t}.{k}");
            continue;
        };
        ensure!(
            assigned.insert(field["canonical"].as_str().unwrap()),
            "Duplicate Java creator property {}",
            field["canonical"]
        );
        value(
            &mut v,
            field["type"].as_str().unwrap(),
            records,
            ignore_unknown,
        )?;
        obj.insert(field["canonical"].as_str().unwrap().to_string(), v);
    }
    Ok(())
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn unknown_nested_dto_and_wrong_shape_rejected() {
        let mut p = serde_json::json!({"base":{"surprise":1}});
        assert!(validate("entrust", &mut p).is_err());
        let mut p = serde_json::json!({"form":[]});
        assert!(validate("lighting", &mut p).is_err());
    }
    #[test]
    fn jackson_alias_scalar_and_bytes_normalized() {
        let mut p = serde_json::json!({"client":{"name":"Synthetic","phone":123},"samples":[{"quantity":"3"}]});
        validate("entrust", &mut p).unwrap();
        assert_eq!(p["client"]["company_name"], "Synthetic");
        assert_eq!(p["client"]["phone"], "123");
        assert_eq!(p["samples"][0]["quantity"], 3);
        let mut p = serde_json::json!({"signatures":{"testedImage":[-1,0,127]}});
        validate("lighting", &mut p).unwrap();
        assert_eq!(p["signatures"]["testedImage"][0], 255);
    }
}
#[cfg(test)]
mod jackson_edges {
    use super::*;
    #[test]
    fn integer_null_and_base64_whitespace() {
        let mut p = serde_json::json!({"sample":{"quantity":"null"}});
        validate("entrust", &mut p).unwrap();
        assert!(p["sample"]["quantity"].is_null());
        let mut p = serde_json::json!({"signatures":{"testedImage":" \nAQID\nBA==\t"}});
        validate("lighting", &mut p).unwrap();
        assert_eq!(p["signatures"]["testedImage"], "AQIDBA==");
        let mut p = serde_json::json!({"signatures":{"testedImage":"AQ ID"}});
        assert!(validate("lighting", &mut p).is_err());
    }
}
#[cfg(test)]
mod http_edges {
    use super::*;
    #[test]
    fn jackson_record_alias_collision_is_not_last_wins() {
        let mut p = serde_json::json!({"client":{"name":"Alias","company_name":"Canonical"}});
        assert!(validate_http("entrust", &mut p).is_err());
        let mut p = serde_json::json!({"client":{"name":"Alias","unknown":42}});
        validate_http("entrust", &mut p).unwrap();
        assert_eq!(p["client"]["company_name"], "Alias");
        assert!(p["client"].get("unknown").is_none());
    }
}
