use crate::{sha256_hex, types::*};
use lopdf::{dictionary, Dictionary, Document, Object, ObjectId, Stream};
use std::collections::HashSet;

pub(crate) fn invalid(s: &str) -> SignatureError {
    SignatureError::InvalidInput(s.into())
}
pub(crate) fn resolve<'a>(doc: &'a Document, value: &'a Object) -> Result<&'a Object> {
    Ok(doc.dereference(value)?.1)
}
pub(crate) fn dict<'a>(doc: &'a Document, value: &'a Object) -> Result<&'a Dictionary> {
    Ok(resolve(doc, value)?.as_dict()?)
}
pub(crate) fn root_id(doc: &Document) -> Result<ObjectId> {
    Ok(doc.trailer.get(b"Root")?.as_reference()?)
}
pub(crate) fn form(doc: &Document) -> Result<Option<&Object>> {
    Ok(doc.catalog()?.get(b"AcroForm").ok())
}
pub(crate) fn string(o: &Object) -> Result<String> {
    Ok(lopdf::decode_text_string(o)?)
}
pub(crate) fn name(o: &Object) -> Option<String> {
    o.as_name()
        .ok()
        .map(|b| String::from_utf8_lossy(b).into_owned())
}
pub(crate) fn text_string(value: &str) -> Object {
    if value.is_ascii() {
        Object::string_literal(value)
    } else {
        let mut bytes = vec![0xfe, 0xff];
        for c in value.encode_utf16() {
            bytes.extend_from_slice(&c.to_be_bytes());
        }
        Object::String(bytes, lopdf::StringFormat::Hexadecimal)
    }
}
pub(crate) fn reference(o: &Object) -> Option<String> {
    o.as_reference().ok().map(|(n, g)| format!("{n} {g} R"))
}
pub(crate) fn number(o: &Object) -> Result<f64> {
    match o {
        Object::Integer(v) => Ok(*v as f64),
        Object::Real(v) => Ok(*v as f64),
        _ => Err(invalid("PDF geometry must be numeric")),
    }
}
fn inherited<'a>(doc: &'a Document, page: ObjectId, key: &[u8]) -> Result<Option<&'a Object>> {
    let mut id = page;
    let mut visited = HashSet::new();
    loop {
        if !visited.insert(id) {
            return Err(invalid("Page tree cycle"));
        }
        let d = doc.get_dictionary(id)?;
        if let Ok(o) = d.get(key) {
            return Ok(Some(resolve(doc, o)?));
        }
        match d.get(b"Parent").and_then(Object::as_reference) {
            Ok(p) => id = p,
            Err(_) => return Ok(None),
        }
    }
}
#[derive(Clone)]
pub(crate) struct Geometry {
    pub crop: [f32; 4],
    pub rotation: i32,
    pub unit: f32,
}
pub(crate) fn geometry(doc: &Document, id: ObjectId) -> Result<Geometry> {
    let media =
        inherited(doc, id, b"MediaBox")?.ok_or_else(|| invalid("Page MediaBox is required"))?;
    let get_box = |o: &Object| -> Result<[f32; 4]> {
        let a = o.as_array()?;
        if a.len() != 4 {
            return Err(invalid("Page box requires four coordinates"));
        }
        Ok([
            number(&a[0])? as f32,
            number(&a[1])? as f32,
            number(&a[2])? as f32,
            number(&a[3])? as f32,
        ])
    };
    let m = get_box(media)?;
    let c = get_box(inherited(doc, id, b"CropBox")?.unwrap_or(media))?;
    let crop = [
        c[0].max(m[0]),
        c[1].max(m[1]),
        c[2].min(m[2]),
        c[3].min(m[3]),
    ];
    if crop[2] <= crop[0] || crop[3] <= crop[1] {
        return Err(invalid("Page CropBox must have positive dimensions"));
    }
    let rotation = inherited(doc, id, b"Rotate")?
        .map(Object::as_i64)
        .transpose()?
        .unwrap_or(0)
        .rem_euclid(360) as i32;
    if rotation % 90 != 0 {
        return Err(invalid(
            "Only PDF page rotations in 90-degree increments are supported",
        ));
    }
    let unit = doc
        .get_dictionary(id)?
        .get(b"UserUnit")
        .ok()
        .map(number)
        .transpose()?
        .unwrap_or(1.0);
    Ok(Geometry {
        crop,
        rotation,
        unit: unit as f32,
    })
}
fn coordinate(s: &str) -> Result<f64> {
    // Match Java BigDecimal scale, including exponent syntax.
    let (mantissa, exponent) = if let Some(i) = s.find(['e', 'E']) {
        (
            &s[..i],
            s[i + 1..]
                .parse::<i32>()
                .map_err(|_| invalid("Normalized coordinate is invalid"))?,
        )
    } else {
        (s, 0)
    };
    let decimals = mantissa
        .split_once('.')
        .map(|(_, d)| d.len() as i32)
        .unwrap_or(0)
        .checked_sub(exponent)
        .ok_or_else(|| invalid("Normalized coordinate is invalid"))?;
    if mantissa.is_empty()
        || mantissa.trim() != mantissa
        || !mantissa
            .chars()
            .enumerate()
            .all(|(i, c)| c.is_ascii_digit() || c == '.' || (i == 0 && (c == '-' || c == '+')))
    {
        return Err(invalid("Normalized coordinate is invalid"));
    }
    let n = s
        .parse::<f64>()
        .map_err(|_| invalid("Normalized coordinate is invalid"))?;
    if !n.is_finite() || !(0.0..=1.0).contains(&n) || decimals > 6 {
        return Err(invalid(
            "Normalized coordinates require 0..1 with at most six decimals",
        ));
    }
    Ok(n)
}
pub(crate) fn to_rect(g: &Geometry, n: &NormalizedRectangle) -> Result<[f64; 4]> {
    let [x, y, w, h] = [
        coordinate(&n.x)?,
        coordinate(&n.y)?,
        coordinate(&n.width)?,
        coordinate(&n.height)?,
    ];
    if w <= 0.0 || h <= 0.0 || x + w > 1.0 + 1e-12 || y + h > 1.0 + 1e-12 {
        return Err(invalid(
            "Signature field rectangle must fit within the normalized page",
        ));
    }
    // PDFBox's PDRectangle and page-space arithmetic use single precision.
    // Preserve those rounding points before the six-decimal DTO conversion.
    let (x, y, w, h) = (x as f32, y as f32, w as f32, h as f32);
    let cw = g.crop[2] - g.crop[0];
    let ch = g.crop[3] - g.crop[1];
    let (vw, vh) = if g.rotation % 180 == 90 {
        (ch, cw)
    } else {
        (cw, ch)
    };
    let (vx, vy, vw, vh) = (x * vw, (1.0 - y - h) * vh, w * vw, h * vh);
    let (ux, uy, uw, uh) = match g.rotation {
        90 => (cw - vy - vh, vx, vh, vw),
        180 => (cw - vx - vw, ch - vy - vh, vw, vh),
        270 => (vy, ch - vx - vw, vh, vw),
        _ => (vx, vy, vw, vh),
    };
    Ok([
        f64::from(g.crop[0] + ux),
        f64::from(g.crop[1] + uy),
        f64::from(g.crop[0] + ux + uw),
        f64::from(g.crop[1] + uy + uh),
    ])
}
fn normalized(g: &Geometry, r: &[Object]) -> Result<NormalizedRectangle> {
    if r.len() != 4 {
        return Err(invalid("Signature widget rectangle is required"));
    }
    let ux = number(&r[0])? as f32 - g.crop[0];
    let uy = number(&r[1])? as f32 - g.crop[1];
    let uw = number(&r[2])? as f32 - number(&r[0])? as f32;
    let uh = number(&r[3])? as f32 - number(&r[1])? as f32;
    let cw = g.crop[2] - g.crop[0];
    let ch = g.crop[3] - g.crop[1];
    let (vwidth, vheight) = if g.rotation % 180 == 90 {
        (ch, cw)
    } else {
        (cw, ch)
    };
    let (vx, vy, vw, vh) = match g.rotation {
        90 => (uy, cw - ux - uw, uh, uw),
        180 => (cw - ux - uw, ch - uy - uh, uw, uh),
        270 => (ch - uy - uh, ux, uh, uw),
        _ => (ux, uy, uw, uh),
    };
    Ok(NormalizedRectangle {
        x: decimal6(f64::from(vx / vwidth)),
        y: decimal6(f64::from(1.0 - (vy + vh) / vheight)),
        width: decimal6(f64::from(vw / vwidth)),
        height: decimal6(f64::from(vh / vheight)),
    })
}
#[derive(Clone)]
pub(crate) struct Field {
    pub object: Object,
    pub name: String,
    pub dictionary: Dictionary,
    pub widgets: Vec<Object>,
}
pub(crate) fn fields(doc: &Document) -> Result<Vec<Field>> {
    let mut out = Vec::new();
    let Some(f) = form(doc)? else { return Ok(out) };
    let f = dict(doc, f)?;
    let roots = resolve(doc, f.get(b"Fields")?)?.as_array()?;
    let mut seen = HashSet::new();
    fn visit(
        doc: &Document,
        o: &Object,
        prefix: &str,
        parent_ft: Option<String>,
        seen: &mut HashSet<ObjectId>,
        out: &mut Vec<Field>,
    ) -> Result<()> {
        if let Ok(id) = o.as_reference() {
            if !seen.insert(id) {
                return Err(invalid("AcroForm field cycle or duplicate field"));
            }
        }
        let d = dict(doc, o)?;
        let partial = d
            .get(b"T")
            .ok()
            .map(string)
            .transpose()?
            .unwrap_or_default();
        let full = if prefix.is_empty() {
            partial
        } else if partial.is_empty() {
            prefix.into()
        } else {
            format!("{prefix}.{partial}")
        };
        let ft = d.get(b"FT").ok().and_then(name).or(parent_ft);
        let kids = d
            .get(b"Kids")
            .ok()
            .map(|o| resolve(doc, o)?.as_array().map_err(SignatureError::from))
            .transpose()?
            .cloned()
            .unwrap_or_default();
        let mut widget_kids = Vec::new();
        let mut field_kids = Vec::new();
        for k in kids {
            let kd = dict(doc, &k)?;
            if kd.get(b"Subtype").ok().and_then(name).as_deref() == Some("Widget")
                && kd.get(b"T").is_err()
            {
                widget_kids.push(k)
            } else {
                field_kids.push(k)
            }
        }
        if ft.as_deref() == Some("Sig") && field_kids.is_empty() {
            if widget_kids.is_empty()
                && d.get(b"Subtype").ok().and_then(name).as_deref() == Some("Widget")
            {
                widget_kids.push(o.clone());
            }
            out.push(Field {
                object: o.clone(),
                name: full.clone(),
                dictionary: d.clone(),
                widgets: widget_kids,
            });
        }
        for k in field_kids {
            visit(doc, &k, &full, ft.clone(), seen, out)?;
        }
        Ok(())
    }
    for o in roots {
        visit(doc, o, "", None, &mut seen, &mut out)?;
    }
    Ok(out)
}
pub(crate) fn self_lock(doc: &Document, f: &Field) -> bool {
    (|| -> Result<bool> {
        let lock = dict(doc, f.dictionary.get(b"Lock")?)?;
        let action = resolve(doc, lock.get(b"Action")?)?;
        let names = resolve(doc, lock.get(b"Fields")?)?.as_array()?;
        Ok(name(action).as_deref() == Some("Include")
            && names.len() == 1
            && string(resolve(doc, &names[0])?)? == f.name)
    })()
    .unwrap_or(false)
}

pub(crate) fn signatures(doc: &Document) -> Result<Vec<(Field, Dictionary)>> {
    let mut out = Vec::new();
    for f in fields(doc)? {
        if let Ok(v) = f.dictionary.get(b"V") {
            if !matches!(resolve(doc, v)?, Object::Null) {
                let d = dict(doc, v)?.clone();
                out.push((f, d));
            }
        }
    }
    Ok(out)
}
pub(crate) fn doc_mdp(doc: &Document) -> Result<Option<i64>> {
    let Some(p) = doc.catalog()?.get(b"Perms").ok() else {
        return Ok(None);
    };
    let p = dict(doc, p)?;
    let Some(s) = p.get(b"DocMDP").ok() else {
        return Ok(None);
    };
    let s = dict(doc, s)?;
    if let Ok(a) = s.get(b"Reference").and_then(Object::as_array) {
        for r in a {
            let d = dict(doc, r)?;
            if d.get(b"TransformMethod").ok().and_then(name).as_deref() == Some("DocMDP") {
                return Ok(dict(doc, d.get(b"TransformParams")?)?
                    .get(b"P")
                    .ok()
                    .and_then(|n| n.as_i64().ok()));
            }
        }
    }
    Ok(None)
}
fn appearance_refs(
    doc: &Document,
    o: &Object,
    refs: &mut Vec<String>,
    seen: &mut HashSet<ObjectId>,
) -> Result<()> {
    if let Ok(id) = o.as_reference() {
        if !seen.insert(id) {
            return Ok(());
        }
        refs.push(format!("{} {} R", id.0, id.1));
    }
    if let Object::Dictionary(d) = resolve(doc, o)? {
        for (_, v) in d {
            appearance_refs(doc, v, refs, seen)?;
        }
    }
    Ok(())
}
pub fn inspect(pdf: &[u8]) -> Result<Inspection> {
    let doc = Document::load_mem(pdf)?;
    inspect_document(&doc, pdf)
}
pub(crate) fn inspect_document(doc: &Document, pdf: &[u8]) -> Result<Inspection> {
    let page_ids: Vec<_> = doc.get_pages().into_values().collect();
    let mut pages = Vec::new();
    for (i, id) in page_ids.iter().enumerate() {
        let g = geometry(doc, *id)?;
        pages.push(PageGeometry {
            page_index: i,
            crop_lower_left_x: java_decimal(g.crop[0]),
            crop_lower_left_y: java_decimal(g.crop[1]),
            crop_width: java_decimal(g.crop[2] - g.crop[0]),
            crop_height: java_decimal(g.crop[3] - g.crop[1]),
            rotation: g.rotation,
            user_unit: java_decimal(g.unit),
        });
    }
    let mut inspections = Vec::new();
    for f in fields(doc)? {
        let mut widgets = Vec::new();
        for (i, w) in f.widgets.iter().enumerate() {
            let wd = dict(doc, w)?;
            let mut page = None;
            for (pi, id) in page_ids.iter().enumerate() {
                if let Ok(a) = doc.get_dictionary(*id)?.get(b"Annots") {
                    if resolve(doc, a)?.as_array()?.iter().any(|a| {
                        a.as_reference()
                            .ok()
                            .is_some_and(|id| Some(id) == w.as_reference().ok())
                    }) {
                        page = Some(pi);
                        break;
                    }
                }
            }
            let pi =
                page.ok_or_else(|| invalid("Signature widget is not attached to a document page"))?;
            let mut refs = Vec::new();
            if let Ok(ap) = wd.get(b"AP") {
                let ap = dict(doc, ap)?;
                let mut visited = HashSet::new();
                for key in [b"N", b"R", b"D"] {
                    if let Ok(o) = ap.get(key) {
                        appearance_refs(doc, o, &mut refs, &mut visited)?;
                    }
                }
            }
            widgets.push(WidgetInspection {
                widget_index: i,
                page_index: pi,
                normalized_rectangle: normalized(
                    &geometry(doc, page_ids[pi])?,
                    wd.get(b"Rect")?.as_array()?,
                )?,
                object_ref: reference(w),
                appearance_object_refs: refs,
            });
        }
        let signed = f
            .dictionary
            .get(b"V")
            .ok()
            .map(|v| resolve(doc, v).map(|o| !matches!(o, Object::Null)))
            .transpose()?
            .unwrap_or(false);
        inspections.push(FieldInspection {
            field_name: f.name.clone(),
            signed,
            self_only_lock: self_lock(doc, &f),
            widget_count: widgets.len(),
            object_ref: reference(&f.object),
            widgets,
        });
    }
    Ok(Inspection {
        sha256: sha256_hex(pdf),
        page_count: pages.len(),
        encrypted: doc.is_encrypted(),
        signature_count: signatures(doc)?.len(),
        doc_mdp_permission: doc_mdp(doc)?,
        pages,
        fields: inspections,
    })
}
pub fn finalize_unsigned(pdf: &[u8]) -> Result<Vec<u8>> {
    let i = inspect(pdf)?;
    if i.encrypted || i.page_count == 0 || i.signature_count != 0 || i.doc_mdp_permission.is_some()
    {
        return Err(invalid("PDF_SOURCE_NOT_UNSIGNED"));
    }
    Ok(pdf.to_vec())
}
pub fn prepare_fields(pdf: &[u8], plans: &[FieldPlan]) -> Result<Vec<u8>> {
    if plans.is_empty() {
        return Err(invalid("At least one signature field is required"));
    }
    let mut doc = Document::load_mem(pdf)?;
    if doc.is_encrypted() {
        return Err(invalid("Encrypted PDFs are not supported"));
    }
    if !signatures(&doc)?.is_empty() || doc_mdp(&doc)?.is_some() {
        return Err(invalid(
            "Fields must be prepared before the first signature",
        ));
    }
    let page_ids: Vec<_> = doc.get_pages().into_values().collect();
    let mut names = all_field_names(&doc)?;
    let mut plan_names = HashSet::new();
    let mut form_dict = if let Some(o) = form(&doc)? {
        dict(&doc, o)?.clone()
    } else {
        Dictionary::new()
    };
    if form_dict.has(b"XFA") {
        return Err(invalid("XFA forms are not supported"));
    }
    let mut field_array = form_dict
        .get(b"Fields")
        .ok()
        .map(|o| {
            resolve(&doc, o)?
                .as_array()
                .cloned()
                .map_err(SignatureError::from)
        })
        .transpose()?
        .unwrap_or_default();
    for p in plans {
        if p.field_name.is_empty()
            || p.field_name.len() > 128
            || !p
                .field_name
                .bytes()
                .all(|b| b.is_ascii_alphanumeric() || b == b'_' || b == b'-')
        {
            return Err(invalid("Invalid signature field name"));
        }
        if !plan_names.insert(p.field_name.clone()) {
            return Err(invalid("A signature field supports exactly one widget"));
        }
        if !names.insert(p.field_name.clone()) {
            return Err(invalid(&format!(
                "Duplicate signature field name: {}",
                p.field_name
            )));
        }
        let page = *page_ids
            .get(
                usize::try_from(p.page_index)
                    .map_err(|_| invalid("Signature field page is outside the document"))?,
            )
            .ok_or_else(|| invalid("Signature field page is outside the document"))?;
        let rect = to_rect(&geometry(&doc, page)?, &p.rectangle)?;
        let field_id = doc.new_object_id();
        let ap_id=doc.add_object(Stream::new(dictionary!{"Type"=>"XObject","Subtype"=>"Form","BBox"=>vec![0.into(),0.into(),Object::Real((rect[2]-rect[0])as f32),Object::Real((rect[3]-rect[1])as f32)],"Resources"=>Dictionary::new()},Vec::new()));
        let wid=doc.add_object(dictionary!{"Type"=>"Annot","Subtype"=>"Widget","Parent"=>field_id,"P"=>page,"Rect"=>rect.iter().map(|v|Object::Real(*v as f32)).collect::<Vec<_>>(),"F"=>4,"AP"=>dictionary!{"N"=>ap_id}});
        doc.set_object(field_id,dictionary!{"FT"=>"Sig","T"=>Object::string_literal(p.field_name.clone()),"Kids"=>vec![Object::Reference(wid)],"Lock"=>dictionary!{"Type"=>"SigFieldLock","Action"=>"Include","Fields"=>vec![Object::string_literal(p.field_name.clone())]}});
        field_array.push(field_id.into());
        let mut annots = doc
            .get_dictionary(page)?
            .get(b"Annots")
            .ok()
            .map(|o| {
                resolve(&doc, o)?
                    .as_array()
                    .cloned()
                    .map_err(SignatureError::from)
            })
            .transpose()?
            .unwrap_or_default();
        annots.push(wid.into());
        doc.get_dictionary_mut(page)?.set("Annots", annots);
    }
    if !form_dict.has(b"DA") {
        form_dict.set("DA", Object::string_literal("/Helv 0 Tf 0 g "));
    }
    form_dict.set("Fields", field_array);
    form_dict.set("SigFlags", 3);
    form_dict.set("NeedAppearances", false);
    if !form_dict.has(b"DR") {
        form_dict.set("DR", Dictionary::new());
    }
    let form_id = match form(&doc)?.and_then(|o| o.as_reference().ok()) {
        Some(id) => {
            doc.set_object(id, form_dict);
            id
        }
        None => doc.add_object(form_dict),
    };
    doc.catalog_mut()?.set("AcroForm", form_id);
    let mut bytes = Vec::new();
    doc.save_to(&mut bytes)?;
    let i = inspect(&bytes)?;
    // Preserve the Java round-trip contract, including refusal of pre-existing fields.
    if i.signature_count != 0 || i.fields.len() != plans.len() {
        return Err(SignatureError::Integrity(
            "Prepared field plan failed round-trip inspection".into(),
        ));
    }
    Ok(bytes)
}
pub fn validate_sign_target(pdf: &[u8], command: &SignCommand) -> Result<()> {
    let doc = Document::load_mem(pdf)?;
    target(&doc, command).map(|_| ())
}
pub(crate) fn target(doc: &Document, c: &SignCommand) -> Result<Field> {
    if c.field_name.trim().is_empty() {
        return Err(invalid("A target signature field is required"));
    }
    if doc.is_encrypted() {
        return Err(invalid("Encrypted PDFs are not supported"));
    }
    let f = fields(doc)?
        .into_iter()
        .find(|f| f.name == c.field_name)
        .ok_or_else(|| invalid("The target signature field does not exist"))?;
    if f.dictionary
        .get(b"V")
        .ok()
        .map(|o| resolve(doc, o).map(|v| !matches!(v, Object::Null)))
        .transpose()?
        .unwrap_or(false)
    {
        return Err(invalid("The target signature field is already signed"));
    }
    if !self_lock(doc, &f) {
        return Err(invalid(
            "The target field does not have the required self-only lock",
        ));
    }
    let count = signatures(doc)?.len();
    let permission = doc_mdp(doc)?;
    match c.signature_role.to_ascii_lowercase().as_str() {
        "certification_p2" => {
            if count != 0 || permission.is_some() {
                return Err(invalid(
                    "A certification signature must be the first signature",
                ));
            }
        }
        "approval" => {
            if count == 0 || permission != Some(2) {
                return Err(invalid(
                    "Approval signatures require an existing DocMDP P=2 certification signature",
                ));
            }
        }
        _ => return Err(invalid("Unsupported PDF signature role")),
    }
    Ok(f)
}

fn all_field_names(doc: &Document) -> Result<HashSet<String>> {
    let mut names = HashSet::new();
    let Some(form) = form(doc)? else {
        return Ok(names);
    };
    let roots = resolve(doc, dict(doc, form)?.get(b"Fields")?)?.as_array()?;
    fn walk(
        doc: &Document,
        o: &Object,
        parent: &str,
        names: &mut HashSet<String>,
        seen: &mut HashSet<ObjectId>,
    ) -> Result<()> {
        if let Ok(id) = o.as_reference() {
            if !seen.insert(id) {
                return Err(invalid("AcroForm field cycle or duplicate field"));
            }
        }
        let d = dict(doc, o)?;
        let part = d.get(b"T").ok().map(string).transpose()?;
        let name = match part {
            Some(s) => {
                if parent.is_empty() {
                    s
                } else {
                    format!("{parent}.{s}")
                }
            }
            None => parent.into(),
        };
        if !name.is_empty() {
            names.insert(name.clone());
        }
        if let Ok(kids) = d.get(b"Kids") {
            for child in resolve(doc, kids)?.as_array()? {
                walk(doc, child, &name, names, seen)?;
            }
        }
        Ok(())
    }
    let mut seen = HashSet::new();
    for root in roots {
        walk(doc, root, "", &mut names, &mut seen)?;
    }
    Ok(names)
}

fn decimal6(value: f64) -> String {
    let rounded = (value * 1_000_000.0).round() / 1_000_000.0;
    if rounded == 0.0 {
        "0.000000".into()
    } else {
        format!("{rounded:.6}")
    }
}

/// Rewrite only the existing owner container when a baseline-supported field is
/// a direct dictionary. The permission verifier remains authoritative; Java's
/// own validator rejects direct-field value changes inside the AcroForm.
pub(crate) fn replace_field(
    inc: &mut lopdf::IncrementalDocument,
    field: &Field,
    updated: Dictionary,
) -> Result<()> {
    if let Ok(id) = field.object.as_reference() {
        inc.new_document.set_object(id, updated);
        return Ok(());
    }
    let mut changes = Vec::new();
    let mut found = false;
    {
        let doc = inc.get_prev_documents();
        fn replace(
            doc: &Document,
            o: &Object,
            prefix: &str,
            target: &str,
            updated: &Dictionary,
            changes: &mut Vec<(ObjectId, Object)>,
            found: &mut bool,
        ) -> Result<(Object, bool)> {
            let dictionary = dict(doc, o)?;
            let part = dictionary
                .get(b"T")
                .ok()
                .map(string)
                .transpose()?
                .unwrap_or_default();
            let full = if prefix.is_empty() {
                part
            } else if part.is_empty() {
                prefix.into()
            } else {
                format!("{prefix}.{part}")
            };
            if full == target && o.as_reference().is_err() {
                if *found {
                    return Err(invalid("The target signature field is ambiguous"));
                }
                *found = true;
                return Ok((Object::Dictionary(updated.clone()), true));
            }
            let mut result = dictionary.clone();
            let mut changed = false;
            if let Ok(kids) = dictionary.get(b"Kids") {
                let mut children = Vec::new();
                let mut child_changed = false;
                for kid in resolve(doc, kids)?.as_array()? {
                    let kd = dict(doc, kid)?;
                    if kd.get(b"T").is_ok() {
                        let (value, change) =
                            replace(doc, kid, &full, target, updated, changes, found)?;
                        children.push(value);
                        child_changed |= change;
                    } else {
                        children.push(kid.clone());
                    }
                }
                if child_changed {
                    if let Ok(id) = kids.as_reference() {
                        changes.push((id, Object::Array(children)));
                    } else {
                        result.set("Kids", children);
                        changed = true;
                    }
                }
            }
            if changed {
                if let Ok(id) = o.as_reference() {
                    changes.push((id, Object::Dictionary(result)));
                    Ok((o.clone(), false))
                } else {
                    Ok((Object::Dictionary(result), true))
                }
            } else {
                Ok((o.clone(), false))
            }
        }
        let form_obj =
            form(doc)?.ok_or_else(|| invalid("The target signature field does not exist"))?;
        let original_form = dict(doc, form_obj)?;
        let field_array = original_form.get(b"Fields")?;
        let mut fields = Vec::new();
        let mut changed = false;
        for entry in resolve(doc, field_array)?.as_array()? {
            let (value, change) = replace(
                doc,
                entry,
                "",
                &field.name,
                &updated,
                &mut changes,
                &mut found,
            )?;
            fields.push(value);
            changed |= change;
        }
        if changed {
            if let Ok(id) = field_array.as_reference() {
                changes.push((id, Object::Array(fields)));
            } else {
                let mut replacement = original_form.clone();
                replacement.set("Fields", fields);
                if let Ok(id) = form_obj.as_reference() {
                    changes.push((id, Object::Dictionary(replacement)));
                } else {
                    let root = root_id(doc)?;
                    let mut catalog = inc
                        .new_document
                        .objects
                        .get(&root)
                        .and_then(|o| o.as_dict().ok())
                        .cloned()
                        .unwrap_or(doc.catalog()?.clone());
                    catalog.set("AcroForm", replacement);
                    changes.push((root, Object::Dictionary(catalog)));
                }
            }
        }
    }
    if !found {
        return Err(invalid("The target signature field does not exist"));
    }
    for (id, object) in changes {
        inc.new_document.set_object(id, object);
    }
    Ok(())
}

/// BigDecimal.valueOf((double) float).stripTrailingZeros().toPlainString().
/// Ryū selects the nearest shortest decimal with an even tie, as Java does;
/// Rust Display's tie choice can differ for promoted f32 page coordinates.
fn java_decimal(value: f32) -> String {
    if value == 0.0 {
        return "0".into();
    }
    let mut buffer = ryu::Buffer::new();
    let formatted = buffer.format_finite(f64::from(value));
    let (mantissa, exponent) = formatted
        .split_once('e')
        .map(|(m, e)| (m, e.parse::<i32>().expect("Ryū exponent")))
        .unwrap_or((formatted, 0));
    let negative = mantissa.starts_with('-');
    let magnitude = mantissa.strip_prefix('-').unwrap_or(mantissa);
    let (whole, fraction) = magnitude.split_once('.').unwrap_or((magnitude, ""));
    let digits = format!("{whole}{fraction}");
    let point = whole.len() as i32 + exponent;
    let mut result = if point <= 0 {
        format!("0.{}{}", "0".repeat((-point) as usize), digits)
    } else if point as usize >= digits.len() {
        format!("{}{}", digits, "0".repeat(point as usize - digits.len()))
    } else {
        format!(
            "{}.{}",
            &digits[..point as usize],
            &digits[point as usize..]
        )
    };
    if result.contains('.') {
        while result.ends_with('0') {
            result.pop();
        }
        if result.ends_with('.') {
            result.pop();
        }
    }
    if negative {
        result.insert(0, '-');
    }
    result
}
