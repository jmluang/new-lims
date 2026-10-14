//! DocMDP P=2 / self-only FieldMDP revision transition policy, matching the Java
//! service's pre-created-field contract. This is stricter than generic PDF P=2.
use crate::{pdf, service::byte_range, Result, SignatureError};
use lopdf::{Dictionary, Document, Object, ObjectId};
use std::collections::{BTreeMap, HashSet};
fn violation(code: &str) -> SignatureError {
    SignatureError::Integrity(code.into())
}
pub(crate) fn validate(bytes: &[u8]) -> Result<()> {
    let doc = Document::load_mem(bytes)?;
    let mut signatures = pdf::signatures(&doc)?;
    signatures.sort_by_key(|(_, d)| byte_range(d, bytes.len()).map(|r| r[2] + r[3]).unwrap_or(0));
    if signatures.is_empty() {
        return Ok(());
    }
    let mut previous = 0;
    for (index, (_, s)) in signatures.iter().enumerate() {
        let range = byte_range(s, bytes.len())?;
        let end = range[2] + range[3];
        if end <= previous
            || end > bytes.len()
            || (index == signatures.len() - 1 && end != bytes.len())
        {
            return Err(violation("UNSIGNED_OR_AMBIGUOUS_LATER_REVISION"));
        }
        let base_end = if index == 0 {
            let markers: Vec<_> = bytes[..end]
                .windows(5)
                .enumerate()
                .filter(|(_, v)| *v == b"%%EOF")
                .map(|(i, _)| i + 5)
                .collect();
            markers.iter().rev().nth(1).copied().unwrap_or(0)
        } else {
            previous
        };
        if base_end == 0 || base_end >= end {
            return Err(violation("SIGNED_REVISION_BOUNDARY_INVALID"));
        }
        transition(&bytes[..base_end], &bytes[..end], index)?;
        previous = end;
    }
    Ok(())
}
fn transition(before: &[u8], after: &[u8], index: usize) -> Result<()> {
    let base = Document::load_mem(before)?;
    let current = Document::load_mem(after)?;
    let mut sigs = pdf::signatures(&current)?;
    sigs.sort_by_key(|(_, d)| byte_range(d, after.len()).map(|r| r[2] + r[3]).unwrap_or(0));
    if pdf::signatures(&base)?.len() != index || sigs.len() != index + 1 {
        return Err(violation("SIGNATURE_REVISION_SEQUENCE_INVALID"));
    }
    let (target, signature) = &sigs[index];
    if !pdf::self_lock(&current, target) {
        return Err(violation("FIELD_LOCK_SCOPE_INVALID"));
    }
    validate_transforms(&current, signature, target, index == 0)?;
    if canonical_dict(base.catalog()?, &[b"Perms", b"AcroForm"])
        != canonical_dict(current.catalog()?, &[b"Perms", b"AcroForm"])
    {
        return Err(violation("CATALOG_CHANGED_OUTSIDE_SIGNATURE_PERMISSIONS"));
    }
    let base_form = pdf::form(&base)?
        .ok_or_else(|| violation("ACROFORM_CHANGED_OUTSIDE_SIGNATURE_RESOURCES"))?;
    let current_form = pdf::form(&current)?
        .ok_or_else(|| violation("ACROFORM_CHANGED_OUTSIDE_SIGNATURE_RESOURCES"))?;
    if canonical_form(pdf::dict(&base, base_form)?)
        != canonical_form(pdf::dict(&current, current_form)?)
    {
        return Err(violation("ACROFORM_CHANGED_OUTSIDE_SIGNATURE_RESOURCES"));
    }
    let old = pdf::fields(&base)?
        .into_iter()
        .find(|f| f.name == target.name)
        .ok_or_else(|| violation("TARGET_FIELD_NOT_PRECREATED"))?;
    if canonical_dict(&old.dictionary, &[b"V"]) != canonical_dict(&target.dictionary, &[b"V"]) {
        return Err(violation("TARGET_FIELD_CHANGED_OUTSIDE_SIGNATURE_VALUE"));
    }
    if old.widgets.len() != target.widgets.len() {
        return Err(violation("TARGET_WIDGET_SET_CHANGED"));
    }
    for (b, a) in old.widgets.iter().zip(&target.widgets) {
        let b = pdf::dict(&base, b)?;
        let a = pdf::dict(&current, a)?;
        let br = b.get(b"Rect")?.as_array()?;
        let ar = a.get(b"Rect")?.as_array()?;
        if br.len() != 4
            || ar.len() != 4
            || br
                .iter()
                .zip(ar)
                .any(|(b, a)| match (pdf::number(b), pdf::number(a)) {
                    (Ok(b), Ok(a)) => (b - a).abs() > 0.0001,
                    _ => true,
                })
            || canonical_dict(b, &[b"AP", b"Rect"]) != canonical_dict(a, &[b"AP", b"Rect"])
        {
            return Err(violation("TARGET_WIDGET_CHANGED_OUTSIDE_APPEARANCE"));
        }
    }
    let mut existing = HashSet::new();
    if let Ok(id) = target.object.as_reference() {
        existing.insert(id);
    }
    for w in &target.widgets {
        if let Ok(id) = w.as_reference() {
            existing.insert(id);
        }
    }
    if let Ok(id) = current_form.as_reference() {
        existing.insert(id);
    }
    let mut graph = HashSet::new();
    if let Ok(dr) = pdf::dict(&current, current_form)?.get(b"DR") {
        collect(&current, dr, &mut existing, &[])?;
        collect(&current, dr, &mut graph, &[])?;
    }
    if index == 0 {
        existing.insert(pdf::root_id(&current)?);
        if let Ok(perms) = current.catalog()?.get(b"Perms") {
            collect(&current, perms, &mut graph, &[b"Data"])?;
        }
    }
    collect(
        &current,
        target.dictionary.get(b"V")?,
        &mut graph,
        &[b"Data", b"Parent", b"P"],
    )?;
    for w in &target.widgets {
        if let Ok(ap) = pdf::dict(&current, w)?.get(b"AP") {
            collect(&current, ap, &mut graph, &[])?;
        }
    }
    for (id, b) in &base.objects {
        if !current.objects.contains_key(id) {
            return Err(violation("INCREMENTAL_OBJECT_REMOVED"));
        }
        let a = &current.objects[id];
        if canonical(b) == canonical(a) {
            continue;
        }
        if !existing.contains(id) {
            return Err(violation("UNAUTHORIZED_INCREMENTAL_OBJECT_CHANGE"));
        }
    }
    for id in current.objects.keys() {
        if base.objects.contains_key(id) {
            continue;
        }
        if !graph.contains(id) {
            return Err(violation("UNAUTHORIZED_INCREMENTAL_OBJECT_CHANGE"));
        }
    }
    Ok(())
}
fn validate_transforms(
    doc: &Document,
    s: &Dictionary,
    f: &pdf::Field,
    certification: bool,
) -> Result<()> {
    let refs = s
        .get(b"Reference")
        .map_err(|_| violation("SIGNATURE_TRANSFORMS_MISSING"))?
        .as_array()?;
    let mut field_count = 0;
    let mut doc_count = 0;
    for r in refs {
        let r = pdf::dict(doc, r)?;
        let params = pdf::dict(doc, r.get(b"TransformParams")?)?;
        match r
            .get(b"TransformMethod")
            .ok()
            .and_then(pdf::name)
            .as_deref()
        {
            Some("FieldMDP") => {
                field_count += 1;
                if params.get(b"Action").ok().and_then(pdf::name).as_deref() != Some("Include") {
                    return Err(violation("FIELD_MDP_ACTION_INVALID"));
                }
                let fields = params.get(b"Fields")?.as_array()?;
                if fields.len() != 1 || pdf::string(&fields[0])? != f.name {
                    return Err(violation("FIELD_MDP_SCOPE_INVALID"));
                }
            }
            Some("DocMDP") => {
                doc_count += 1;
                if params.get(b"P")?.as_i64()? != 2 {
                    return Err(violation("DOC_MDP_PERMISSION_INVALID"));
                }
            }
            _ => return Err(violation("UNSUPPORTED_SIGNATURE_TRANSFORM")),
        }
    }
    if field_count != 1 || doc_count != usize::from(certification) {
        return Err(violation("SIGNATURE_TRANSFORM_COUNT_INVALID"));
    }
    let perms = pdf::dict(
        doc,
        doc.catalog()?
            .get(b"Perms")
            .map_err(|_| violation("CATALOG_DOC_MDP_MISSING"))?,
    )?;
    let cert = perms
        .get(b"DocMDP")
        .map_err(|_| violation("CATALOG_DOC_MDP_MISSING"))?;
    if certification && cert != f.dictionary.get(b"V")? {
        return Err(violation("CATALOG_DOC_MDP_TARGET_INVALID"));
    }
    Ok(())
}
fn collect(doc: &Document, o: &Object, out: &mut HashSet<ObjectId>, skip: &[&[u8]]) -> Result<()> {
    if let Ok(id) = o.as_reference() {
        if !out.insert(id) {
            return Ok(());
        }
        return collect(doc, doc.get_object(id)?, out, skip);
    }
    match o {
        Object::Dictionary(d) => {
            for (k, v) in d {
                if !skip.contains(&k.as_slice()) {
                    collect(doc, v, out, skip)?;
                }
            }
        }
        Object::Stream(s) => {
            for (k, v) in &s.dict {
                if !skip.contains(&k.as_slice()) {
                    collect(doc, v, out, skip)?;
                }
            }
        }
        Object::Array(a) => {
            for v in a {
                collect(doc, v, out, skip)?;
            }
        }
        _ => {}
    }
    Ok(())
}
fn canonical_dict(d: &Dictionary, skip: &[&[u8]]) -> String {
    let ordered: BTreeMap<_, _> = d
        .iter()
        .filter(|(k, _)| !skip.contains(&k.as_slice()))
        .map(|(k, v)| (hex::encode(k), canonical(v)))
        .collect();
    format!("{ordered:?}")
}
fn canonical(o: &Object) -> String {
    match o {
        Object::Dictionary(d) => canonical_dict(d, &[]),
        Object::Array(a) => format!("{:?}", a.iter().map(canonical).collect::<Vec<_>>()),
        Object::Stream(s) => format!(
            "stream:{}:{}",
            canonical_dict(&s.dict, &[]),
            crate::sha256_hex(&s.content)
        ),
        Object::String(s, _) => format!("str:{}", hex::encode(s)),
        Object::Real(v) => format!("num:{v}"),
        Object::Integer(v) => format!("num:{v}"),
        _ => format!("{o:?}"),
    }
}

fn canonical_form(d: &Dictionary) -> String {
    // PDFBox getAcroForm applies AcroFormDefaultFixup before Java compares it.
    // Reproduce its absent-DA default, rather than allowing arbitrary DA changes.
    let mut normalized = d.clone();
    if !normalized.has(b"DA") {
        normalized.set("DA", Object::string_literal("/Helv 0 Tf 0 g "));
    }
    canonical_dict(&normalized, &[b"DR"])
}
