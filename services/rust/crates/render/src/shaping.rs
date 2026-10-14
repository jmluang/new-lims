//! FontBox 3 Latin feature order. Measurements remain unshaped, like PDFont.
//! Standard Latin presentation-form mappings match Java; ordinary character
//! identity remains distinct even when font glyphs are shared.
use std::collections::BTreeMap;
use ttf_parser::{
    Face, GlyphId, Tag,
    gsub::{SingleSubstitution, SubstitutionSubtable},
};
pub(super) type Rules = Vec<Vec<(Vec<u16>, u16)>>;
pub(super) fn rules(face: &Face<'_>) -> Rules {
    let Some(gsub) = face.tables().gsub else {
        return vec![];
    };
    let Some(script) = gsub.scripts.find(Tag::from_bytes(b"latn")) else {
        return vec![];
    };
    let Some(language) = script.default_language else {
        return vec![];
    };
    let mut result = vec![];
    for tag in [b"ccmp", b"liga", b"clig"] {
        let mut replacements = BTreeMap::new();
        for index in language.feature_indices {
            let Some(feature) = gsub.features.get(index) else {
                continue;
            };
            if feature.tag != Tag::from_bytes(tag) {
                continue;
            }
            for index in feature.lookup_indices {
                let Some(lookup) = gsub.lookups.get(index) else {
                    continue;
                };
                for sub in lookup.subtables.into_iter::<SubstitutionSubtable>() {
                    for gid in 0..face.number_of_glyphs() {
                        let Some(index) = sub.coverage().get(GlyphId(gid)) else {
                            continue;
                        };
                        match sub {
                            SubstitutionSubtable::Single(s) => {
                                let replacement = match s {
                                    SingleSubstitution::Format1 { delta, .. } => {
                                        Some(gid.wrapping_add(delta as u16))
                                    }
                                    SingleSubstitution::Format2 { substitutes, .. } => {
                                        substitutes.get(index).map(|g| g.0)
                                    }
                                };
                                if let Some(r) = replacement {
                                    replacements.insert(vec![gid], r);
                                }
                            }
                            SubstitutionSubtable::Ligature(s) => {
                                if let Some(set) = s.ligature_sets.get(index) {
                                    for ligature in set {
                                        let mut sequence = vec![gid];
                                        sequence
                                            .extend(ligature.components.into_iter().map(|g| g.0));
                                        replacements.insert(sequence, ligature.glyph.0);
                                    }
                                }
                            }
                            _ => {} // FontBox's single-replacement model does not apply these here.
                        }
                    }
                }
            }
        }
        fn key(s: &[u16]) -> String {
            let mut k = String::from("_");
            for g in s {
                k.push_str(&format!("{g}_"));
            }
            k
        }
        let mut replacements: Vec<_> = replacements.into_iter().collect();
        replacements.sort_by(|(a, _), (b, _)| {
            let a = key(a);
            let b = key(b);
            b.len().cmp(&a.len()).then(b.cmp(&a))
        });
        if !replacements.is_empty() {
            result.push(replacements)
        }
    }
    result
}
pub(super) fn shape(mut glyphs: Vec<(String, u16)>, features: &Rules) -> Vec<(String, u16)> {
    for feature in features {
        let mut out = Vec::with_capacity(glyphs.len());
        let mut i = 0;
        while i < glyphs.len() {
            if let Some((sequence, gid)) = feature.iter().find(|(s, _)| {
                i + s.len() <= glyphs.len() && s.iter().zip(&glyphs[i..]).all(|(a, b)| *a == b.1)
            }) {
                let source = glyphs[i..i + sequence.len()]
                    .iter()
                    .map(|(s, _)| s.as_str())
                    .collect::<String>();
                out.push((source, *gid));
                i += sequence.len();
            } else {
                out.push(glyphs[i].clone());
                i += 1;
            }
        }
        glyphs = out;
    }
    glyphs
}

#[cfg(test)]
mod tests {
    #[test]
    fn latin_ligature_uses_native_glyph_and_java_unicode_mapping() {
        let font = crate::Font::new(std::sync::Arc::new(
            crate::FontData::from_bytes(
                include_bytes!("../assets/LimsSongSC-Regular.ttf").to_vec(),
            )
            .unwrap(),
        ));
        let codes = font.hex("fi").unwrap();
        assert_eq!(codes.len(), 4);
        let used = font.used.borrow();
        let ((text, gid), _) = used.iter().next().unwrap();
        assert_eq!(text, "ﬁ");
        assert_ne!(*gid, font.data.glyphs[&'f'].0);
        drop(used);
        let mut doc = lopdf::Document::with_version("1.7");
        font.embed(&mut doc).unwrap();
        assert!(doc.objects.values().any(|o|matches!(o,lopdf::Object::Stream(s) if String::from_utf8_lossy(&s.content).contains("<FB01>"))));
    }
    #[test]
    fn java_ccmp_dash_sequences_are_finite_mappings_not_general_normalization() {
        for (source, expected) in [
            ("——", "⸺"),
            ("———", "⸻"),
            ("—", "—"),
            ("–", "–"),
            ("‒", "‒"),
        ] {
            let font = crate::Font::new(crate::default_font().unwrap());
            assert_eq!(font.hex(source).unwrap().len(), 4);
            assert_eq!(font.used.borrow().keys().next().unwrap().0, expected);
        }
    }
}
