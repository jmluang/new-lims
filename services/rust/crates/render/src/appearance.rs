//! Native regeneration of the simple, positive-size core-font text fields that
//! PDFBox repairs while merging appendices. Existing non-text APs are retained.
use anyhow::Result;
use lopdf::{
    Dictionary, Document, Object, Stream,
    content::{Content, Operation},
    dictionary,
};
fn obj<'a>(d: &'a Document, o: &'a Object) -> Result<&'a Object> {
    Ok(d.dereference(o)?.1)
}
pub(crate) fn refresh(doc: &mut Document, form: &mut Dictionary) -> Result<()> {
    let Some(dr) = form
        .get(b"DR")
        .ok()
        .map(|o| obj(doc, o).and_then(|o| Ok(o.as_dict()?.clone())))
        .transpose()?
    else {
        return Ok(());
    };
    let Some(fields) = form
        .get(b"Fields")
        .ok()
        .map(|o| obj(doc, o).and_then(|o| Ok(o.as_array()?.clone())))
        .transpose()?
    else {
        return Ok(());
    };
    let force = form
        .get(b"NeedAppearances")
        .and_then(Object::as_bool)
        .unwrap_or(false);
    // PDFBox AcroFormDefaultFixup generates only when this flag is true.
    if !force {
        return Ok(());
    }
    let mut complete = true;
    for field in fields {
        if let Ok(id) = field.as_reference() {
            let f = doc.get_dictionary(id)?.clone();
            if f.has(b"Kids") {
                complete = false;
                continue;
            }
            if let Some(stream) = text_appearance(doc, &f, &dr, form)? {
                let id_ap = doc.add_object(stream);
                doc.get_dictionary_mut(id)?
                    .set("AP", dictionary! {"N"=>id_ap});
            } else {
                complete = false;
            }
        } else {
            complete = false;
        }
    }
    if complete {
        form.set("NeedAppearances", false);
    }
    Ok(())
}
fn text_appearance(
    doc: &Document,
    f: &Dictionary,
    dr: &Dictionary,
    form: &Dictionary,
) -> Result<Option<Stream>> {
    if f.get(b"FT").and_then(Object::as_name).ok() != Some(b"Tx") {
        return Ok(None);
    }
    let flags = f.get(b"Ff").and_then(Object::as_i64).unwrap_or(0);
    if flags & (4096 | 8192 | 16777216 | 33554432) != 0
        || f.has(b"MK")
        || f.has(b"BS")
        || f.has(b"Border")
    {
        return Ok(None);
    }
    let Some(da) = f.get(b"DA").ok().or_else(|| form.get(b"DA").ok()) else {
        return Ok(None);
    };
    let content = Content::decode_strict(da.as_str()?)?;
    let Some(tf) = content
        .operations
        .iter()
        .find(|o| o.operator == "Tf" && o.operands.len() == 2)
    else {
        return Ok(None);
    };
    let name = tf.operands[0].as_name()?;
    let size = tf.operands[1].as_float()?;
    if size <= 0. || !size.is_finite() {
        return Ok(None);
    }
    let fonts = obj(doc, dr.get(b"Font")?)?.as_dict()?;
    let font = obj(doc, fonts.get(name)?)?.as_dict()?;
    let base = font.get(b"BaseFont").and_then(Object::as_name)?;
    let Some(metrics) = crate::standard14::metrics(std::str::from_utf8(base)?) else {
        return Ok(None);
    };
    let Some(value) = f.get(b"V").ok() else {
        return Ok(None);
    };
    let value = lopdf::decode_text_string(value)?;
    if !value.is_ascii() || value.contains(['\r', '\n']) {
        return Ok(None);
    }
    let rect = obj(doc, f.get(b"Rect")?)?.as_array()?;
    if rect.len() != 4 {
        return Ok(None);
    }
    let w = rect[2].as_float()? - rect[0].as_float()?;
    let h = rect[3].as_float()? - rect[1].as_float()?;
    let width = value
        .bytes()
        .map(|b| metrics.ascii[b as usize])
        .sum::<f32>()
        * size
        / 1000.;
    let q = f
        .get(b"Q")
        .ok()
        .or_else(|| form.get(b"Q").ok())
        .and_then(|o| o.as_i64().ok())
        .unwrap_or(0);
    let x = match q {
        1 => (w - width) / 2.,
        2 => w - 2. - width,
        _ => 2.,
    };
    let y = (h - metrics.cap * size / 1000.) / 2.;
    let op = |name: &str, operands: Vec<Object>| Operation::new(name, operands);
    let mut operations = vec![
        op("BMC", vec![Object::Name(b"Tx".to_vec())]),
        op("q", vec![]),
        op(
            "re",
            vec![1.into(), 1.into(), (w - 2.).into(), (h - 2.).into()],
        ),
        op("W", vec![]),
        op("n", vec![]),
        op("BT", vec![]),
    ];
    operations.extend(
        content
            .operations
            .into_iter()
            .filter(|o| matches!(o.operator.as_str(), "Tf" | "g" | "rg" | "k")),
    );
    operations.extend([
        op("Td", vec![x.into(), y.into()]),
        op("Tj", vec![Object::string_literal(value)]),
        op("ET", vec![]),
        op("Q", vec![]),
        op("EMC", vec![]),
    ]);
    Ok(Some(Stream::new(
        dictionary! {"Type"=>"XObject","Subtype"=>"Form","FormType"=>1,"BBox"=>vec![0.into(),0.into(),w.into(),h.into()],"Resources"=>dr.clone()},
        Content { operations }.encode()?,
    )))
}
