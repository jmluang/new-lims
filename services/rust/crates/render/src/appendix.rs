//! Native appendix catalog retention in addition to page/object copying.
use anyhow::{Result, ensure};
use lopdf::{Dictionary, Document, Object, content::Content, dictionary};
use std::collections::BTreeMap;
#[derive(Default)]
pub(crate) struct CatalogMerge {
    forms: Vec<Dictionary>,
    names: BTreeMap<Vec<u8>, BTreeMap<Vec<u8>, Object>>,
    destinations: Dictionary,
    outline_roots: Vec<Dictionary>,
    structure_roots: Vec<Dictionary>,
    parent_next: i64,
}
fn obj<'a>(d: &'a Document, o: &'a Object) -> Result<&'a Object> {
    Ok(d.dereference(o)?.1)
}
fn dict<'a>(d: &'a Document, o: &'a Object) -> Result<&'a Dictionary> {
    Ok(obj(d, o)?.as_dict()?)
}
fn array(d: &Document, o: &Object) -> Result<Vec<Object>> {
    Ok(obj(d, o)?.as_array()?.clone())
}
fn name_tree(
    d: &Document,
    node: &Object,
    out: &mut BTreeMap<Vec<u8>, Object>,
    depth: usize,
) -> Result<()> {
    ensure!(depth < 128, "Appendix name tree too deep");
    let node = dict(d, node)?;
    if let Ok(names) = node.get(b"Names") {
        let names = array(d, names)?;
        ensure!(names.len() % 2 == 0, "Invalid name tree pair");
        for pair in names.chunks_exact(2) {
            out.insert(pair[0].as_str()?.to_vec(), pair[1].clone());
        }
    }
    if let Ok(kids) = node.get(b"Kids") {
        for child in array(d, kids)? {
            name_tree(d, &child, out, depth + 1)?;
        }
    }
    Ok(())
}
fn number_tree(
    d: &Document,
    node: &Object,
    out: &mut BTreeMap<i64, Object>,
    depth: usize,
) -> Result<()> {
    ensure!(depth < 128, "Appendix number tree too deep");
    let node = dict(d, node)?;
    if let Ok(nums) = node.get(b"Nums") {
        let nums = array(d, nums)?;
        ensure!(nums.len() % 2 == 0, "Invalid number tree pair");
        for pair in nums.chunks_exact(2) {
            out.insert(pair[0].as_i64()?, pair[1].clone());
        }
    }
    if let Ok(kids) = node.get(b"Kids") {
        for child in array(d, kids)? {
            number_tree(d, &child, out, depth + 1)?;
        }
    }
    Ok(())
}
impl CatalogMerge {
    /// Call after object renumbering, before transferring objects to the destination.
    pub(crate) fn capture(&mut self, source: &mut Document) -> Result<()> {
        let catalog = source.catalog()?.clone();
        if let Ok(form) = catalog.get(b"AcroForm") {
            let mut form = dict(source, form)?.clone();
            if let Ok(fields) = form.get(b"Fields") {
                form.set("Fields", array(source, fields)?);
            }
            if let Ok(dr) = form.get(b"DR") {
                form.set("DR", dict(source, dr)?.clone());
            }
            if let Ok(co) = form.get(b"CO") {
                form.set("CO", array(source, co)?);
            }
            crate::appearance::refresh(source, &mut form)?;
            self.forms.push(form);
        }
        if let Ok(names) = catalog.get(b"Names") {
            for (key, node) in dict(source, names)?.iter() {
                name_tree(source, node, self.names.entry(key.clone()).or_default(), 0)?;
            }
        }
        if let Ok(dests) = catalog.get(b"Dests") {
            for (k, v) in dict(source, dests)?.iter() {
                self.destinations.set(k.clone(), v.clone());
            }
        }
        if let Ok(outlines) = catalog.get(b"Outlines") {
            self.outline_roots.push(dict(source, outlines)?.clone());
        }
        if let Ok(structure) = catalog.get(b"StructTreeRoot") {
            let mut root = dict(source, structure)?.clone();
            let offset = self.parent_next;
            let mut tree = BTreeMap::new();
            if let Ok(parent) = root.get(b"ParentTree") {
                number_tree(source, parent, &mut tree, 0)?;
            }
            self.parent_next = offset + tree.keys().last().map(|v| v + 1).unwrap_or(0);
            let shifted = tree
                .into_iter()
                .flat_map(|(key, value)| [Object::Integer(key + offset), value])
                .collect::<Vec<_>>();
            root.set("ParentTree", dictionary! {"Nums"=>shifted});
            for object in source.objects.values_mut() {
                let d = match object {
                    Object::Dictionary(d) => Some(d),
                    Object::Stream(s) => Some(&mut s.dict),
                    _ => None,
                };
                if let Some(d) = d {
                    for key in [b"StructParent".as_slice(), b"StructParents"] {
                        if let Ok(n) = d.get(key).and_then(Object::as_i64) {
                            d.set(key, n + offset);
                        }
                    }
                }
            }
            self.structure_roots.push(root);
        }
        Ok(())
    }
    pub(crate) fn attach(self, doc: &mut Document, catalog: &mut Dictionary) -> Result<()> {
        if !self.forms.is_empty() {
            catalog.set("AcroForm", merge_forms(doc, self.forms)?);
        }
        if !self.names.is_empty() {
            let mut all = Dictionary::new();
            for (key, names) in self.names {
                let pairs = names
                    .into_iter()
                    .flat_map(|(key, value)| [Object::string_literal(key), value])
                    .collect::<Vec<_>>();
                all.set(key, dictionary! {"Names"=>pairs});
            }
            catalog.set("Names", all);
        }
        if !self.destinations.is_empty() {
            catalog.set("Dests", self.destinations);
        }
        if !self.outline_roots.is_empty() {
            let root = doc.new_object_id();
            let mut top = Vec::new();
            let mut count = 0;
            for outline in self.outline_roots {
                count += outline
                    .get(b"Count")
                    .and_then(Object::as_i64)
                    .unwrap_or(0)
                    .abs();
                let mut current = outline.get(b"First").and_then(Object::as_reference).ok();
                let mut seen = std::collections::HashSet::new();
                while let Some(id) = current {
                    ensure!(seen.insert(id), "Cyclic appendix outline");
                    top.push(id);
                    current = doc
                        .get_dictionary(id)?
                        .get(b"Next")
                        .and_then(Object::as_reference)
                        .ok();
                }
            }
            for (i, id) in top.iter().enumerate() {
                let item = doc.get_dictionary_mut(*id)?;
                item.set("Parent", root);
                item.remove(b"Prev");
                item.remove(b"Next");
                if i > 0 {
                    item.set("Prev", top[i - 1]);
                }
                if i + 1 < top.len() {
                    item.set("Next", top[i + 1]);
                }
            }
            if let (Some(first), Some(last)) = (top.first(), top.last()) {
                doc.objects.insert(root,Object::Dictionary(dictionary!{"Type"=>"Outlines","First"=>*first,"Last"=>*last,"Count"=>count}));
                catalog.set("Outlines", root);
            }
        }
        if !self.structure_roots.is_empty() {
            let root_id = doc.new_object_id();
            let mut children = Vec::new();
            let mut parents = BTreeMap::new();
            let mut role_map = Dictionary::new();
            let mut class_map = Dictionary::new();
            let mut id_tree = BTreeMap::new();
            for root in self.structure_roots {
                if let Ok(k) = root.get(b"K") {
                    match obj(doc, k)? {
                        Object::Array(a) => children.extend(a.clone()),
                        Object::Null => (),
                        _ => children.push(k.clone()),
                    }
                }
                if let Ok(tree) = root.get(b"ParentTree") {
                    number_tree(doc, tree, &mut parents, 0)?;
                }
                if let Ok(map) = root.get(b"RoleMap") {
                    for (k, v) in dict(doc, map)?.iter() {
                        role_map.set(k.clone(), v.clone());
                    }
                }
                if let Ok(map) = root.get(b"ClassMap") {
                    for (k, v) in dict(doc, map)?.iter() {
                        class_map.set(k.clone(), v.clone());
                    }
                }
                if let Ok(tree) = root.get(b"IDTree") {
                    name_tree(doc, tree, &mut id_tree, 0)?;
                }
            }
            for child in &mut children {
                match child {
                    Object::Reference(id) => {
                        doc.get_dictionary_mut(*id)?.set("P", root_id);
                    }
                    Object::Dictionary(d) => {
                        d.set("P", root_id);
                    }
                    _ => {}
                }
            }
            let nums = parents
                .into_iter()
                .flat_map(|(k, v)| [Object::Integer(k), v])
                .collect::<Vec<_>>();
            let mut root = dictionary! {"Type"=>"StructTreeRoot","K"=>children,"ParentTree"=>dictionary!{"Nums"=>nums},"ParentTreeNextKey"=>self.parent_next};
            if !role_map.is_empty() {
                root.set("RoleMap", role_map);
            }
            if !class_map.is_empty() {
                root.set("ClassMap", class_map);
            }
            if !id_tree.is_empty() {
                root.set("IDTree",dictionary!{"Names"=>id_tree.into_iter().flat_map(|(k,v)|[Object::string_literal(k),v]).collect::<Vec<_>>()});
            }
            doc.objects.insert(root_id, Object::Dictionary(root));
            catalog.set("StructTreeRoot", root_id);
            catalog.set("MarkInfo", dictionary! {"Marked"=>true});
        }
        Ok(())
    }
}
fn rename_da(value: &Object, names: &BTreeMap<Vec<u8>, Vec<u8>>) -> Result<Object> {
    let mut content = Content::decode_strict(value.as_str()?)?;
    for op in &mut content.operations {
        if op.operator == "Tf"
            && let Some(Object::Name(name)) = op.operands.first_mut()
            && let Some(new) = names.get(name)
        {
            *name = new.clone();
        }
    }
    Ok(Object::string_literal(content.encode()?))
}
fn adjust_fields(
    doc: &mut Document,
    fields: &mut [Object],
    names: &BTreeMap<Vec<u8>, Vec<u8>>,
    default_da: Option<&Object>,
    default_q: Option<&Object>,
    depth: usize,
) -> Result<()> {
    ensure!(depth < 128, "Appendix field tree too deep");
    for field in fields {
        let id = field.as_reference().ok();
        let mut f = dict(doc, field)?.clone();
        if let Some(da) = f.get(b"DA").ok().or(default_da) {
            f.set("DA", rename_da(da, names)?);
        }
        if !f.has(b"Q")
            && let Some(q) = default_q
        {
            f.set("Q", q.clone());
        }
        if let Ok(kids) = f.get(b"Kids") {
            let mut kids = array(doc, kids)?;
            adjust_fields(doc, &mut kids, names, None, None, depth + 1)?;
            f.set("Kids", kids);
        }
        if let Some(id) = id {
            doc.objects.insert(id, Object::Dictionary(f));
        } else {
            *field = Object::Dictionary(f);
        }
    }
    Ok(())
}
fn merge_forms(doc: &mut Document, forms: Vec<Dictionary>) -> Result<Dictionary> {
    let mut merged = forms[0].clone();
    let mut fields = Vec::new();
    let mut order = Vec::new();
    let mut resources = Dictionary::new();
    let mut sig_flags = 0;
    let mut needs_appearances = false;
    for (index, form) in forms.into_iter().enumerate() {
        let mut names = BTreeMap::new();
        if let Ok(dr) = form.get(b"DR") {
            for (kind, entries) in dict(doc, dr)?.iter() {
                let mut dest = resources
                    .get(kind)
                    .ok()
                    .and_then(|v| v.as_dict().ok())
                    .cloned()
                    .unwrap_or_default();
                for (name, value) in dict(doc, entries)?.iter() {
                    let mut new = format!("AF{index}_").into_bytes();
                    new.extend_from_slice(name);
                    if kind == b"Font" {
                        names.insert(name.clone(), new.clone());
                    }
                    dest.set(new, value.clone());
                }
                resources.set(kind.clone(), dest);
            }
        }
        let mut source_fields = form
            .get(b"Fields")
            .ok()
            .map(|v| array(doc, v))
            .transpose()?
            .unwrap_or_default();
        adjust_fields(
            doc,
            &mut source_fields,
            &names,
            form.get(b"DA").ok(),
            form.get(b"Q").ok(),
            0,
        )?;
        fields.extend(source_fields);
        if let Ok(co) = form.get(b"CO") {
            order.extend(array(doc, co)?);
        }
        sig_flags |= form.get(b"SigFlags").and_then(Object::as_i64).unwrap_or(0);
        needs_appearances |= form
            .get(b"NeedAppearances")
            .and_then(Object::as_bool)
            .unwrap_or(false);
    }
    merged.set("Fields", fields);
    merged.set("DR", resources);
    merged.remove(b"DA");
    if !order.is_empty() {
        merged.set("CO", order);
    }
    if sig_flags != 0 {
        merged.set("SigFlags", sig_flags);
    }
    merged.set("NeedAppearances", needs_appearances);
    Ok(merged)
}
