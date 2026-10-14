//! First-page, text-layer-only cover extraction.
//!
//! Field matching mirrors Java `PdfCoverExtractor`. The PDF walker handles common
//! horizontal text, inherited resources, transformed Form XObjects, simple-font
//! encodings and embedded ToUnicode CMaps. It deliberately performs no OCR and
//! does not guess Unicode from Identity-H glyph identifiers. This is not a claim
//! of universal PDF/PDFBox layout or encoding parity.
use anyhow::{Context, Result, bail, ensure};
use lopdf::{Dictionary, Document, Encoding, LoadOptions, Object, ObjectId, content::Content};
use regex::Regex;
use serde_json::{Value, json};
use std::collections::{BTreeMap, BTreeSet, HashSet};
use std::sync::LazyLock;
use unicode_normalization::UnicodeNormalization;

const FIELDS: &[(&str, &[&str])] = &[
    (
        "report_number",
        &[
            "报告编号",
            "Report No",
            "Report No.",
            "Report Number",
            "Reference No",
            "Reference No.",
            "Reference Number",
        ],
    ),
    (
        "product_name",
        &[
            "产品名称",
            "Product",
            "Product Name",
            "Test item description",
        ],
    ),
    (
        "model_specification",
        &[
            "型号规格",
            "规格型号",
            "型号",
            "Model",
            "Model Specification",
            "Model No",
            "Model No.",
            "Model/Type reference",
        ],
    ),
    (
        "entrust_company",
        &[
            "委托单位",
            "申请人",
            "Applicant",
            "Entrust Company",
            "Applicant's name",
        ],
    ),
    (
        "test_items",
        &[
            "检测项目",
            "Test Items",
            "Test Item",
            "Test specification",
            "Test Specification",
            "Standard",
        ],
    ),
    (
        "report_date",
        &[
            "报告日期",
            "签发日期",
            "Report Date",
            "Date of Test",
            "Date of issue",
        ],
    ),
];
const BOUNDARIES: &[&str] = &[
    "参考标准",
    "接收日期",
    "地址",
    "制造商名称",
    "测试人员",
    "审核人员",
    "检测机构",
    "文件编号",
    "文件版本",
    "Reference Standard",
    "Received Date",
    "Address",
    "Manufacturer",
    "Manufacturer's name",
    "Tested by",
    "Approved by",
];
struct Label {
    alias: &'static str,
    pattern: Regex,
}
static LABELS: LazyLock<Vec<Label>> = LazyLock::new(|| {
    FIELDS
        .iter()
        .flat_map(|(_, a)| a.iter())
        .chain(BOUNDARIES.iter())
        .map(|&alias| {
            let escaped = alias
                .split_whitespace()
                .map(regex::escape)
                .collect::<Vec<_>>()
                .join(r"[\x09-\x0d\x20]*");
            Label {
                alias,
                pattern: Regex::new(&format!(r"(?i)(?:^|[\x09-\x0d\x20]+){escaped}[\x09-\x0d\x20.·•…‧°．_—-]*[:：][\x09-\x0d\x20]*"))
                    .unwrap(),
            }
        })
        .collect()
});
static HEADING: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^([\p{L}][\p{L}\p{N}\x09-\x0d\x20/'’()._-]*)[:：][\x09-\x0d\x20]*").unwrap()
});
static REPORT: LazyLock<Regex> =
    LazyLock::new(|| Regex::new(r"^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$").unwrap());
fn normalized_alias(value: &str) -> String {
    value
        .to_lowercase()
        .chars()
        .filter(|c| {
            !crate::java_ws(*c)
                && !matches!(
                    c,
                    '.' | '·' | '•' | '…' | '‧' | '°' | ':' | '：' | '-' | '—' | '_' | '．'
                )
        })
        .collect()
}
fn sanitized(value: &str) -> Option<String> {
    let value = value.trim_matches(|c: char| c <= '\u{20}');
    (!value.is_empty()).then(|| value.to_owned())
}
fn bare_label(line: &str, aliases: &[&str]) -> bool {
    let line = normalized_alias(line);
    aliases.iter().any(|a| line == normalized_alias(a))
}
fn boundary(line: &str) -> bool {
    let normalized = normalized_alias(line);
    LABELS
        .iter()
        .any(|l| normalized == normalized_alias(l.alias))
        || HEADING.is_match(line)
}
fn next_line(lines: &[&str], index: usize) -> Option<String> {
    for &line in &lines[index + 1..] {
        if boundary(line) {
            break;
        }
        if let Some(value) = sanitized(line)
            && !value
                .chars()
                .all(|c| c.is_ascii_whitespace() || matches!(c, '.' | ':' | '：' | '-'))
        {
            return Some(value);
        }
    }
    None
}
fn inline_values(line: &str, aliases: &[&str]) -> Vec<String> {
    let mut values = BTreeMap::new();
    for label in LABELS.iter().filter(|l| aliases.contains(&l.alias)) {
        for found in label.pattern.find_iter(line) {
            let nested = LABELS.iter().any(|outer| {
                outer
                    .pattern
                    .find_iter(line)
                    .any(|m| m.start() < found.start() && m.end() >= found.end())
            });
            let unknown_heading = found.start() > 0
                && HEADING.captures(line).is_some_and(|h| {
                    found.end() <= h.get(0).unwrap().end()
                        && normalized_alias(&h[1]) != normalized_alias(label.alias)
                });
            if nested || unknown_heading {
                continue;
            }
            let remaining = &line[found.end()..];
            let end = LABELS
                .iter()
                .filter_map(|b| b.pattern.find(remaining).map(|m| m.start()))
                .min()
                .unwrap_or(remaining.len());
            if let Some(value) = sanitized(&remaining[..end]) {
                values.entry(found.start()).or_insert(value);
            }
        }
    }
    values.into_values().collect()
}
fn report_number(value: &str) -> bool {
    REPORT.is_match(value)
        && value.bytes().any(|c| c.is_ascii_alphabetic())
        && value.bytes().any(|c| c.is_ascii_digit())
}
/// Apply the six Java cover-field rules to Unicode text, returning the seven-key
/// snake_case wire object (missing fields are JSON null).
pub fn extract_from_text(text: &str) -> Value {
    let normalized = text
        .nfkc()
        .collect::<String>()
        .replace(['\u{00a0}', '\u{3000}'], " ");
    let lines = normalized
        .split(['\r', '\n'])
        .map(|s| s.trim_matches(|c: char| c <= '\u{20}'))
        .filter(|s| !s.is_empty())
        .collect::<Vec<_>>();
    let mut out = serde_json::Map::new();
    let mut filled = 0;
    for &(field, aliases) in FIELDS {
        let value = if field == "report_number" {
            let mut candidates = BTreeSet::new();
            for (i, line) in lines.iter().enumerate() {
                for value in inline_values(line, aliases) {
                    if report_number(&value) {
                        candidates.insert(value);
                    }
                }
                if bare_label(line, aliases)
                    && let Some(value) = next_line(&lines, i)
                    && report_number(&value)
                {
                    candidates.insert(value);
                }
            }
            if candidates.len() == 1 {
                candidates.into_iter().next()
            } else {
                None
            }
        } else {
            lines.iter().enumerate().find_map(|(i, line)| {
                inline_values(line, aliases).into_iter().next().or_else(|| {
                    bare_label(line, aliases)
                        .then(|| next_line(&lines, i))
                        .flatten()
                })
            })
        };
        if value.is_some() {
            filled += 1;
        }
        out.insert(
            field.to_owned(),
            value.map(Value::String).unwrap_or(Value::Null),
        );
    }
    out.insert(
        "extraction_status".to_owned(),
        json!(match filled {
            0 => "failed",
            6 => "success",
            _ => "partial",
        }),
    );
    Value::Object(out)
}

const MAX_INPUT: usize = 64 * 1024 * 1024;
const MAX_STREAM: usize = 16 * 1024 * 1024;
const MAX_CMAP: usize = 2 * 1024 * 1024;
const MAX_GLYPHS: usize = 250_000;
const MAX_OPERATIONS: usize = 500_000;

/// Extract only the first page's existing text layer. Scanned/blank pages return
/// `failed` with six nulls. Invalid PDFs and exceeded safety bounds return Err.
pub fn extract_cover(pdf: &[u8]) -> Result<Value> {
    ensure!(
        pdf.len() <= MAX_INPUT,
        "cover PDF exceeds 64 MiB input limit"
    );
    let doc =
        crate::pdf_load::load_legacy(pdf, LoadOptions::with_max_decompressed_size(MAX_STREAM))
            .context("load cover PDF")?;
    extract_from_document(&doc)
}
pub(crate) fn extract_from_document(doc: &Document) -> Result<Value> {
    let Some((_, &page)) = doc.get_pages().first_key_value() else {
        return Ok(extract_from_text(""));
    };
    let empty = Dictionary::new();
    let resources = inherited(doc, page, b"Resources")?
        .map(Object::as_dict)
        .transpose()?
        .unwrap_or(&empty);
    // PDFBox sortByPosition uses text-direction adjusted coordinates. Page
    // /Rotate changes display orientation, not the text's reading direction.
    let mut walker = Walker {
        doc,
        glyphs: Vec::new(),
        remaining_bytes: MAX_STREAM * 2,
        remaining_ops: MAX_OPERATIONS,
        active_forms: HashSet::new(),
    };
    let mut bytes = Vec::new();
    // Decode every page content stream strictly, without lopdf's raw-byte fallback
    // on decompression errors, and cap the aggregate size as well as each stream.
    for id in doc.get_page_contents(page) {
        let stream = doc.get_object(id)?.as_stream()?;
        let part = stream.get_plain_content_with_limit(MAX_STREAM.saturating_sub(bytes.len()))?;
        bytes.extend_from_slice(&part);
        bytes.push(b'\n');
        ensure!(
            bytes.len() <= MAX_STREAM,
            "cover page content limit exceeded"
        );
    }
    walker.walk(&bytes, resources, State::default(), 0)?;
    Ok(extract_from_text(&positioned_text(walker.glyphs)))
}
fn deref<'a>(doc: &'a Document, object: &'a Object) -> Result<&'a Object> {
    Ok(match object {
        Object::Reference(id) => doc.get_object(*id)?,
        _ => object,
    })
}
fn inherited<'a>(doc: &'a Document, mut id: ObjectId, key: &[u8]) -> Result<Option<&'a Object>> {
    let mut seen = HashSet::new();
    for _ in 0..64 {
        ensure!(seen.insert(id), "cyclic page inheritance");
        let dict = doc.get_dictionary(id)?;
        if let Ok(value) = dict.get(key) {
            return Ok(Some(deref(doc, value)?));
        }
        match dict.get(b"Parent").and_then(Object::as_reference) {
            Ok(parent) => id = parent,
            Err(_) => return Ok(None),
        }
    }
    bail!("page inheritance depth exceeded")
}
#[derive(Clone, Copy, Debug)]
struct Matrix([f64; 6]);
impl Default for Matrix {
    fn default() -> Self {
        Self([1., 0., 0., 1., 0., 0.])
    }
}
impl Matrix {
    fn point(self, x: f64, y: f64) -> (f64, f64) {
        let [a, b, c, d, e, f] = self.0;
        (a * x + c * y + e, b * x + d * y + f)
    }
    /// Compose `self` after `rhs`, using column-vector affine coordinates.
    fn then(self, rhs: Self) -> Self {
        let [a, b, c, d, e, f] = self.0;
        let [g, h, i, j, k, l] = rhs.0;
        Self([
            a * g + c * h,
            b * g + d * h,
            a * i + c * j,
            b * i + d * j,
            a * k + c * l + e,
            b * k + d * l + f,
        ])
    }
    fn translate(&mut self, x: f64, y: f64) {
        *self = self.then(Self([1., 0., 0., 1., x, y]));
    }
}
#[derive(Clone)]
struct State {
    ctm: Matrix,
    text: Matrix,
    line: Matrix,
    font: Vec<u8>,
    size: f64,
    leading: f64,
    char_space: f64,
    word_space: f64,
    hscale: f64,
    rise: f64,
}
impl Default for State {
    fn default() -> Self {
        Self {
            ctm: Matrix::default(),
            text: Matrix::default(),
            line: Matrix::default(),
            font: Vec::new(),
            size: 12.,
            leading: 0.,
            char_space: 0.,
            word_space: 0.,
            hscale: 1.,
            rise: 0.,
        }
    }
}
impl State {
    fn next_line(&mut self) {
        self.line.translate(0., -self.leading);
        self.text = self.line;
    }
}
struct Positioned {
    direction: u16,
    x: f64,
    y: f64,
    end_x: f64,
    height: f64,
    space: f64,
    text: String,
    order: usize,
}
struct Walker<'a> {
    doc: &'a Document,
    glyphs: Vec<Positioned>,
    remaining_bytes: usize,
    remaining_ops: usize,
    active_forms: HashSet<ObjectId>,
}
fn number(object: &Object) -> Result<f64> {
    let n = match object {
        Object::Integer(i) => *i as f64,
        Object::Real(f) => *f as f64,
        _ => bail!("expected numeric PDF text operand"),
    };
    ensure!(n.is_finite() && n.abs() < 1e12, "invalid PDF coordinate");
    Ok(n)
}
fn operand(args: &[Object], n: usize) -> Result<f64> {
    number(args.get(n).context("missing PDF text operand")?)
}
fn matrix(args: &[Object]) -> Result<Matrix> {
    Ok(Matrix([
        operand(args, 0)?,
        operand(args, 1)?,
        operand(args, 2)?,
        operand(args, 3)?,
        operand(args, 4)?,
        operand(args, 5)?,
    ]))
}
impl<'a> Walker<'a> {
    fn display(&self, p: (f64, f64), direction: u16) -> (f64, f64) {
        // Page-size constants in XDirAdj/YDirAdj cancel within each direction
        // group; these axes preserve the same row order and word gaps.
        match direction {
            90 => (p.1, p.0),
            180 => (-p.0, p.1),
            270 => (-p.1, -p.0),
            _ => (p.0, -p.1),
        }
    }
    fn walk(
        &mut self,
        bytes: &[u8],
        resources: &'a Dictionary,
        mut state: State,
        depth: usize,
    ) -> Result<()> {
        ensure!(depth <= 16, "Form XObject nesting limit exceeded");
        ensure!(
            bytes.len() <= self.remaining_bytes,
            "cover decoded content budget exceeded"
        );
        self.remaining_bytes -= bytes.len();
        let content = Content::decode_strict(bytes).context("decode PDF cover content")?;
        ensure!(
            content.operations.len() <= self.remaining_ops,
            "cover operation limit exceeded"
        );
        self.remaining_ops -= content.operations.len();
        let fonts = resources
            .get(b"Font")
            .ok()
            .map(|o| deref(self.doc, o))
            .transpose()?
            .map(Object::as_dict)
            .transpose()?;
        let mut font_cache: BTreeMap<Vec<u8>, Font<'a>> = BTreeMap::new();
        let mut stack = Vec::new();
        for op in content.operations {
            let a = &op.operands;
            match op.operator.as_str() {
                "q" => {
                    ensure!(stack.len() < 128, "graphics stack limit exceeded");
                    stack.push(state.clone());
                }
                "Q" => {
                    if let Some(saved) = stack.pop() {
                        state = saved;
                    }
                }
                "cm" => state.ctm = state.ctm.then(matrix(a)?),
                "BT" => {
                    state.text = Matrix::default();
                    state.line = Matrix::default();
                }
                "Tf" => {
                    state.font = a.first().context("missing font name")?.as_name()?.to_vec();
                    state.size = operand(a, 1)?;
                }
                "Tm" => {
                    state.text = matrix(a)?;
                    state.line = state.text;
                }
                "Td" | "TD" => {
                    let x = operand(a, 0)?;
                    let y = operand(a, 1)?;
                    if op.operator == "TD" {
                        state.leading = -y;
                    }
                    state.line.translate(x, y);
                    state.text = state.line;
                }
                "TL" => state.leading = operand(a, 0)?,
                "Tc" => state.char_space = operand(a, 0)?,
                "Tw" => state.word_space = operand(a, 0)?,
                "Tz" => state.hscale = operand(a, 0)? / 100.,
                "Ts" => state.rise = operand(a, 0)?,
                "T*" => state.next_line(),
                "Tj" | "TJ" | "'" | "\"" => {
                    if op.operator == "'" || op.operator == "\"" {
                        state.next_line();
                    }
                    let index = if op.operator == "\"" {
                        state.word_space = operand(a, 0)?;
                        state.char_space = operand(a, 1)?;
                        2
                    } else {
                        0
                    };
                    if !font_cache.contains_key(&state.font)
                        && let Some(dict) = fonts.and_then(|f| f.get(&state.font).ok())
                    {
                        let font = deref(self.doc, dict)?.as_dict()?;
                        font_cache.insert(state.font.clone(), Font::load(self.doc, font)?);
                    }
                    if let Some(font) = font_cache.get(&state.font) {
                        self.show(
                            a.get(index).context("missing text-show operand")?,
                            &mut state,
                            font,
                        )?;
                    }
                }
                "Do" => {
                    let name = a.first().context("missing XObject name")?.as_name()?;
                    if let Some(objects) = resources
                        .get(b"XObject")
                        .ok()
                        .map(|o| deref(self.doc, o))
                        .transpose()?
                        .map(Object::as_dict)
                        .transpose()?
                        && let Ok(object) = objects.get(name)
                    {
                        let id = object.as_reference().ok();
                        let form = deref(self.doc, object)?.as_stream()?;
                        if form.dict.get(b"Subtype").and_then(Object::as_name).ok() == Some(b"Form")
                        {
                            if let Some(id) = id {
                                ensure!(self.active_forms.insert(id), "cyclic Form XObject");
                            }
                            let mut form_state = state.clone();
                            if let Ok(m) = form.dict.get(b"Matrix") {
                                form_state.ctm =
                                    state.ctm.then(matrix(deref(self.doc, m)?.as_array()?)?);
                            }
                            let form_resources = form
                                .dict
                                .get(b"Resources")
                                .ok()
                                .map(|o| deref(self.doc, o))
                                .transpose()?
                                .map(Object::as_dict)
                                .transpose()?
                                .unwrap_or(resources);
                            let data = form.get_plain_content_with_limit(
                                MAX_STREAM.min(self.remaining_bytes),
                            )?;
                            self.walk(&data, form_resources, form_state, depth + 1)?;
                            if let Some(id) = id {
                                self.active_forms.remove(&id);
                            }
                        }
                    }
                }
                _ => {}
            }
        }
        Ok(())
    }
    fn show(&mut self, object: &Object, state: &mut State, font: &Font<'_>) -> Result<()> {
        match object {
            Object::Array(array) => {
                for item in array {
                    match item {
                        Object::String(..) => self.show(item, state, font)?,
                        Object::Integer(_) | Object::Real(_) => state
                            .text
                            .translate(-number(item)? / 1000. * state.size * state.hscale, 0.),
                        _ => bail!("invalid TJ array entry"),
                    }
                }
            }
            Object::String(bytes, _) => {
                for (code, text) in font.decode(bytes)? {
                    ensure!(self.glyphs.len() < MAX_GLYPHS, "cover glyph limit exceeded");
                    let glyph_width = font.width(code, &text) / 1000. * state.size * state.hscale;
                    let advance = glyph_width
                        + (state.char_space
                            + if code == 32 && !font.composite {
                                state.word_space
                            } else {
                                0.
                            })
                            * state.hscale;
                    let combined = state.ctm.then(state.text);
                    let direction = text_direction(combined, state.size, state.hscale);
                    let (x, y) = self.display(combined.point(0., state.rise), direction);
                    let (end_x, _) =
                        self.display(combined.point(glyph_width, state.rise), direction);
                    let scale = combined.0[0].hypot(combined.0[1]);
                    let height = state.size.abs() * combined.0[2].hypot(combined.0[3]);
                    ensure!(
                        [x, y, end_x, height, advance]
                            .into_iter()
                            .all(f64::is_finite),
                        "non-finite transformed PDF text"
                    );
                    if !text.is_empty() {
                        self.glyphs.push(Positioned {
                            direction,
                            x,
                            y,
                            end_x,
                            height: height.max(1.),
                            space: (font.width(32, " ") / 1000.
                                * state.size
                                * state.hscale
                                * scale)
                                .abs()
                                .max(1.),
                            text,
                            order: self.glyphs.len(),
                        });
                    }
                    state.text.translate(advance, 0.);
                }
            }
            _ => bail!("invalid text-show operand"),
        }
        Ok(())
    }
}
// PDFBox TextPosition.getDir() sign/dominance tests (not atan/angle rounding).
fn text_direction(matrix: Matrix, size: f64, hscale: f64) -> u16 {
    let [a, b, c, d, _, _] = matrix.0;
    let (a, b, c, d) = (a * size * hscale, b * size * hscale, c * size, d * size);
    if d > 0. && b.abs() < a && c.abs() < d && a > 0. {
        0
    } else if d < 0. && b.abs() < a.abs() && c.abs() < d.abs() && a < 0. {
        180
    } else if d.abs() < c.abs() && b > 0. && c < 0. && a.abs() < b {
        90
    } else if d.abs() < c && b < 0. && c > 0. && a.abs() < b.abs() {
        270
    } else {
        0
    }
}
fn positioned_text(mut glyphs: Vec<Positioned>) -> String {
    glyphs.sort_by(|a, b| {
        a.direction
            .cmp(&b.direction)
            .then(a.y.total_cmp(&b.y))
            .then(a.x.total_cmp(&b.x))
            .then(a.order.cmp(&b.order))
    });
    let mut rows: Vec<Vec<Positioned>> = Vec::new();
    for glyph in glyphs {
        let same = rows.last().and_then(|r| r.first()).is_some_and(|r| {
            r.direction == glyph.direction
                && (r.y - glyph.y).abs() <= (r.height.min(glyph.height) * 0.3).clamp(1., 4.)
        });
        if same {
            rows.last_mut().unwrap().push(glyph);
        } else {
            rows.push(vec![glyph]);
        }
    }
    let mut output = String::new();
    for mut row in rows {
        row.sort_by(|a, b| a.x.total_cmp(&b.x).then(a.order.cmp(&b.order)));
        let mut previous: Option<&Positioned> = None;
        for glyph in &row {
            if let Some(prev) = previous {
                // Ignore repeated paint of the same glyph used for faux bold.
                if glyph.text == prev.text
                    && (glyph.x - prev.x).abs() < 0.3
                    && (glyph.y - prev.y).abs() < 0.3
                {
                    continue;
                }
                if glyph.x - prev.end_x > prev.space.min(glyph.space) * 0.45
                    && !output.ends_with(char::is_whitespace)
                    && !glyph.text.starts_with(char::is_whitespace)
                {
                    output.push(' ');
                }
            }
            output.push_str(&glyph.text);
            previous = Some(glyph);
        }
        output.push('\n');
    }
    output
}

struct Font<'a> {
    encoding: FontEncoding<'a>,
    widths: Vec<(u32, u32, f64)>,
    default_width: f64,
    composite: bool,
    courier: bool,
    core_metrics: Option<&'static crate::standard14::Metrics>,
}
enum FontEncoding<'a> {
    Unicode(CMap),
    Simple(Encoding<'a>),
    Unavailable,
}
impl<'a> Font<'a> {
    fn load(doc: &'a Document, dict: &'a Dictionary) -> Result<Self> {
        let composite = dict.get(b"Subtype").and_then(Object::as_name).ok() == Some(b"Type0");
        let encoding = if let Ok(object) = dict.get(b"ToUnicode") {
            let stream = deref(doc, object)?.as_stream()?;
            FontEncoding::Unicode(CMap::parse(
                &stream.get_plain_content_with_limit(MAX_CMAP)?,
            )?)
        } else if composite {
            // Only explicitly Unicode-valued predefined encodings can be decoded
            // without a ToUnicode map. Identity-H represents CIDs, not Unicode.
            match dict.get(b"Encoding").and_then(Object::as_name).ok() {
                Some(b"UniGB-UCS2-H" | b"UniGB-UTF16-H") => {
                    FontEncoding::Simple(dict.get_font_encoding_with_limit(doc, MAX_CMAP)?)
                }
                _ => FontEncoding::Unavailable,
            }
        } else {
            FontEncoding::Simple(dict.get_font_encoding_with_limit(doc, MAX_CMAP)?)
        };
        let metrics = if composite {
            dict.get(b"DescendantFonts")
                .ok()
                .map(|o| deref(doc, o))
                .transpose()?
                .map(Object::as_array)
                .transpose()?
                .and_then(|a| a.first())
                .map(|o| deref(doc, o))
                .transpose()?
                .map(Object::as_dict)
                .transpose()?
                .unwrap_or(dict)
        } else {
            dict
        };
        let courier = dict
            .get(b"BaseFont")
            .and_then(Object::as_name)
            .ok()
            .is_some_and(|n| n.windows(7).any(|s| s == b"Courier"));
        let core_metrics = dict
            .get(b"BaseFont")
            .and_then(Object::as_name)
            .ok()
            .and_then(|n| std::str::from_utf8(n).ok())
            .and_then(crate::standard14::metrics);
        let mut default_width = if composite { 1000. } else { 500. };
        let mut widths = Vec::new();
        if composite {
            if let Ok(w) = metrics.get(b"DW") {
                default_width = number(w)?;
            }
            if let Some(values) = metrics
                .get(b"W")
                .ok()
                .map(|o| deref(doc, o))
                .transpose()?
                .map(Object::as_array)
                .transpose()?
            {
                let mut i = 0;
                while i < values.len() {
                    ensure!(widths.len() < MAX_GLYPHS, "font width table limit exceeded");
                    let first = values[i].as_i64()?;
                    ensure!(
                        (0..=u32::MAX as i64).contains(&first),
                        "invalid CID width code"
                    );
                    i += 1;
                    let value = deref(doc, values.get(i).context("truncated CID widths")?)?;
                    i += 1;
                    if let Object::Array(ws) = value {
                        for (offset, w) in ws.iter().enumerate() {
                            let code = (first as u32)
                                .checked_add(offset as u32)
                                .context("CID width overflow")?;
                            widths.push((code, code, number(w)?));
                        }
                    } else {
                        let last = value.as_i64()?;
                        ensure!(
                            last >= first && last <= u32::MAX as i64,
                            "invalid CID width range"
                        );
                        let w = number(values.get(i).context("missing CID range width")?)?;
                        i += 1;
                        widths.push((first as u32, last as u32, w));
                    }
                }
            }
        } else {
            if let Some(desc) = metrics
                .get(b"FontDescriptor")
                .ok()
                .map(|o| deref(doc, o))
                .transpose()?
                .map(Object::as_dict)
                .transpose()?
                && let Ok(w) = desc.get(b"MissingWidth")
            {
                default_width = number(w)?;
            }
            let first = metrics
                .get(b"FirstChar")
                .and_then(Object::as_i64)
                .unwrap_or(0);
            if let Some(ws) = metrics
                .get(b"Widths")
                .ok()
                .map(|o| deref(doc, o))
                .transpose()?
                .map(Object::as_array)
                .transpose()?
            {
                for (i, w) in ws.iter().enumerate() {
                    let code = first + i as i64;
                    if code >= 0 && code <= u32::MAX as i64 {
                        widths.push((code as u32, code as u32, number(w)?));
                    }
                }
            }
        }
        Ok(Self {
            encoding,
            widths,
            default_width,
            composite,
            courier,
            core_metrics,
        })
    }
    fn decode(&self, bytes: &[u8]) -> Result<Vec<(u32, String)>> {
        ensure!(
            bytes.len() <= MAX_GLYPHS * 4,
            "text-show byte limit exceeded"
        );
        match &self.encoding {
            FontEncoding::Unicode(map) => map.decode(bytes),
            FontEncoding::Unavailable => Ok(Vec::new()),
            FontEncoding::Simple(encoding) if self.composite => {
                ensure!(bytes.len().is_multiple_of(2), "odd UTF-16 text byte count");
                Ok(bytes
                    .chunks_exact(2)
                    .map(|b| {
                        (
                            u16::from_be_bytes([b[0], b[1]]) as u32,
                            Document::decode_text(encoding, b).unwrap_or_default(),
                        )
                    })
                    .collect())
            }
            FontEncoding::Simple(encoding) => bytes
                .iter()
                .map(|&b| Ok((b as u32, Document::decode_text(encoding, &[b])?)))
                .collect(),
        }
    }
    fn width(&self, code: u32, text: &str) -> f64 {
        if let Some((_, _, w)) = self
            .widths
            .iter()
            .find(|(lo, hi, _)| *lo <= code && code <= *hi)
        {
            return *w;
        }
        if self.composite {
            return self.default_width;
        }
        if self.courier {
            return 600.;
        }
        if let Some(metrics) = self.core_metrics
            && text.len() == 1
            && text.is_ascii()
        {
            return metrics.ascii[text.as_bytes()[0] as usize] as f64;
        }
        // Base-14 fonts may legally omit Widths. These Helvetica-like metrics
        // are used only to infer word gaps; embedded-font widths take priority.
        match text {
            " " | "." | "," | ":" | ";" | "!" | "i" | "l" | "I" => 278.,
            "m" => 833.,
            "w" => 722.,
            "M" => 833.,
            "W" => 944.,
            _ if text.chars().next().is_some_and(|c| c.is_ascii_uppercase()) => 667.,
            _ if text
                .chars()
                .next()
                .is_some_and(|c| c.is_ascii_digit() || c.is_ascii_lowercase()) =>
            {
                556.
            }
            _ => self.default_width,
        }
    }
}

// ToUnicode parsing is independent of lopdf's eager reverse-map construction:
// a malicious <00000000> <ffffffff> range must never allocate 2^32 mappings.
struct CMap {
    spaces: Vec<(usize, u32, u32)>,
    ranges: Vec<Mapping>,
}
struct Mapping {
    length: usize,
    low: u32,
    high: u32,
    target: Target,
}
enum Target {
    Sequential(Vec<u8>),
    Array(Vec<Vec<u8>>),
}
#[derive(Debug)]
enum Token {
    Hex(Vec<u8>),
    Word(String),
    Open,
    Close,
}
fn cmap_tokens(data: &[u8]) -> Result<Vec<Token>> {
    let mut out = Vec::new();
    let mut i = 0;
    while i < data.len() {
        ensure!(out.len() < 500_000, "CMap token limit exceeded");
        match data[i] {
            b'%' => {
                while i < data.len() && !matches!(data[i], b'\n' | b'\r') {
                    i += 1;
                }
            }
            b'[' => {
                out.push(Token::Open);
                i += 1;
            }
            b']' => {
                out.push(Token::Close);
                i += 1;
            }
            b'<' if data.get(i + 1) == Some(&b'<') => {
                i += 2;
            }
            b'<' => {
                i += 1;
                let mut digits = Vec::new();
                while i < data.len() && data[i] != b'>' {
                    if !data[i].is_ascii_whitespace() {
                        ensure!(data[i].is_ascii_hexdigit(), "invalid CMap hex byte");
                        digits.push(data[i]);
                    }
                    i += 1;
                }
                ensure!(i < data.len(), "unterminated CMap hex string");
                i += 1;
                if digits.len() % 2 != 0 {
                    digits.push(b'0');
                }
                let hex = digits
                    .chunks_exact(2)
                    .map(|p| u8::from_str_radix(std::str::from_utf8(p).unwrap(), 16).unwrap())
                    .collect();
                out.push(Token::Hex(hex));
            }
            c if c.is_ascii_whitespace()
                || matches!(c, b'<' | b'>' | b'(' | b')' | b'{' | b'}') =>
            {
                i += 1;
            }
            _ => {
                let start = i;
                while i < data.len()
                    && !data[i].is_ascii_whitespace()
                    && !matches!(
                        data[i],
                        b'[' | b']' | b'<' | b'>' | b'(' | b')' | b'{' | b'}' | b'%'
                    )
                {
                    i += 1;
                }
                out.push(Token::Word(
                    String::from_utf8_lossy(&data[start..i]).into_owned(),
                ));
            }
        }
    }
    Ok(out)
}
fn hex_token(tokens: &[Token], i: &mut usize) -> Result<Vec<u8>> {
    let Some(Token::Hex(bytes)) = tokens.get(*i) else {
        bail!("expected CMap hex string");
    };
    *i += 1;
    Ok(bytes.clone())
}
fn code(bytes: &[u8]) -> Result<u32> {
    ensure!(
        !bytes.is_empty() && bytes.len() <= 4,
        "CMap source must be 1–4 bytes"
    );
    Ok(bytes.iter().fold(0, |n, b| n * 256 + *b as u32))
}
fn utf16(bytes: &[u8]) -> Result<String> {
    ensure!(
        !bytes.is_empty() && bytes.len().is_multiple_of(2) && bytes.len() <= 256,
        "invalid CMap Unicode destination"
    );
    let units = bytes
        .chunks_exact(2)
        .map(|b| u16::from_be_bytes([b[0], b[1]]))
        .collect::<Vec<_>>();
    Ok(String::from_utf16_lossy(&units))
}
impl CMap {
    fn parse(data: &[u8]) -> Result<Self> {
        let tokens = cmap_tokens(data)?;
        let mut spaces = Vec::new();
        let mut ranges = Vec::new();
        let mut i = 0;
        while i < tokens.len() {
            let Token::Word(word) = &tokens[i] else {
                i += 1;
                continue;
            };
            let end = match word.as_str() {
                "begincodespacerange" => "endcodespacerange",
                "beginbfchar" => "endbfchar",
                "beginbfrange" => "endbfrange",
                "usecmap" => bail!("external/usecmap ToUnicode inheritance is unsupported"),
                _ => {
                    i += 1;
                    continue;
                }
            };
            let kind = word.clone();
            i += 1;
            while !matches!(tokens.get(i),Some(Token::Word(w)) if w==end) {
                ensure!(
                    ranges.len() < 100_000 && spaces.len() < 1024,
                    "CMap mapping limit exceeded"
                );
                let low = hex_token(&tokens, &mut i)?;
                let low_code = code(&low)?;
                if kind == "beginbfchar" {
                    let value = hex_token(&tokens, &mut i)?;
                    utf16(&value)?;
                    ranges.push(Mapping {
                        length: low.len(),
                        low: low_code,
                        high: low_code,
                        target: Target::Sequential(value),
                    });
                } else {
                    let high = hex_token(&tokens, &mut i)?;
                    let high_code = code(&high)?;
                    ensure!(
                        low.len() == high.len() && low_code <= high_code,
                        "invalid CMap source range"
                    );
                    if kind == "begincodespacerange" {
                        spaces.push((low.len(), low_code, high_code));
                    } else {
                        let target = if matches!(tokens.get(i), Some(Token::Open)) {
                            i += 1;
                            let mut values = Vec::new();
                            while !matches!(tokens.get(i), Some(Token::Close)) {
                                let value = hex_token(&tokens, &mut i)?;
                                utf16(&value)?;
                                values.push(value);
                                ensure!(values.len() <= 100_000, "CMap array limit exceeded");
                            }
                            i += 1;
                            ensure!(
                                values.len() as u64 == high_code as u64 - low_code as u64 + 1,
                                "CMap range array length mismatch"
                            );
                            Target::Array(values)
                        } else {
                            let value = hex_token(&tokens, &mut i)?;
                            utf16(&value)?;
                            Target::Sequential(value)
                        };
                        ranges.push(Mapping {
                            length: low.len(),
                            low: low_code,
                            high: high_code,
                            target,
                        });
                    }
                }
            }
            i += 1;
        }
        ensure!(!ranges.is_empty(), "ToUnicode CMap has no mappings");
        if spaces.is_empty() {
            for range in &ranges {
                if !spaces.iter().any(|(len, _, _)| *len == range.length) {
                    spaces.push((
                        range.length,
                        0,
                        if range.length == 4 {
                            u32::MAX
                        } else {
                            (1u32 << (range.length * 8)) - 1
                        },
                    ));
                }
            }
        }
        spaces.sort_unstable();
        // Duplicate single-code definitions use the last definition. Reject
        // overlapping nonidentical ranges instead of an unbounded linear scan.
        let mut unique = BTreeMap::new();
        for range in ranges {
            unique.insert((range.length, range.low), range);
        }
        let ranges = unique.into_values().collect::<Vec<_>>();
        for pair in ranges.windows(2) {
            ensure!(
                pair[0].length != pair[1].length || pair[0].high < pair[1].low,
                "overlapping ToUnicode ranges are unsupported"
            );
        }
        Ok(Self { spaces, ranges })
    }
    fn decode(&self, bytes: &[u8]) -> Result<Vec<(u32, String)>> {
        let mut result = Vec::new();
        let mut offset = 0;
        while offset < bytes.len() {
            ensure!(result.len() < MAX_GLYPHS, "decoded glyph limit exceeded");
            let (length, value) = self
                .spaces
                .iter()
                .find_map(|&(length, low, high)| {
                    let slice = bytes.get(offset..offset + length)?;
                    let value = code(slice).ok()?;
                    (low <= value && value <= high).then_some((length, value))
                })
                .context("text contains invalid/truncated ToUnicode source code")?;
            let index = self
                .ranges
                .partition_point(|m| (m.length, m.low) <= (length, value));
            let mapping = index
                .checked_sub(1)
                .and_then(|i| self.ranges.get(i))
                .filter(|m| m.length == length && value <= m.high);
            let text = if let Some(map) = mapping {
                let delta = value - map.low;
                match &map.target {
                    Target::Array(values) => utf16(&values[delta as usize])?,
                    Target::Sequential(base) => {
                        let mut value = base.clone();
                        let mut carry = delta as u64;
                        for b in value.iter_mut().rev() {
                            carry += *b as u64;
                            *b = carry as u8;
                            carry >>= 8;
                        }
                        ensure!(carry == 0, "ToUnicode destination overflow");
                        utf16(&value)?
                    }
                }
            } else {
                "\u{fffd}".to_owned()
            };
            result.push((value, text));
            offset += length;
        }
        Ok(result)
    }
}
