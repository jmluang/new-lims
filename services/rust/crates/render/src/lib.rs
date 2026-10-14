//! Native PDF renderers ported from the pinned Java service.
//! Searchable vector PDF output; no Java runtime, external PDF renderer, or raster pages.
mod appearance;
mod appendix;
mod assets;
mod contract;
pub mod cover;
pub mod media;
mod standard14;
use assets::load_http_asset;
pub use media::{
    MediaConfig, PreparedMetadata, PreparedPdf, prepare_unsigned_pdf, prepare_unsigned_pdf_to_file,
};
mod entrust;
mod image_samples;
mod java_unicode_c;
mod lighting;
mod pdf_load;
mod raw_json;
mod schema;
mod shaping;
use anyhow::{Context, Result, bail, ensure};
use base64::Engine;
use lopdf::{Dictionary, Document, Object, ObjectId, Stream, StringFormat, dictionary};
use serde::Deserialize;
use std::{
    cell::RefCell,
    collections::{BTreeMap, HashMap},
    fs,
    io::{self},
    path::Path,
    sync::{Arc, LazyLock, Mutex, OnceLock},
};
const WIDTH: f32 = 595.27563;
const HEIGHT: f32 = 841.8898;
const TOP: f32 = 94.68;
const BOTTOM: f32 = 706.;
const LEFT: f32 = 56.7;
const BLACK: [u8; 3] = [0, 0, 0];
const BLUE: [u8; 3] = [0, 112, 192];
const GRAY: [u8; 3] = [217, 217, 217];
#[derive(Clone, Deserialize)]
#[serde(rename_all = "camelCase")]
struct Payload {
    report_number: Option<String>,
    lab_name: Option<String>,
    lab_address: Option<String>,
    product_name: Option<String>,
    model: Option<String>,
    applicant: Option<String>,
    received_date: Option<String>,
    issued_date: Option<String>,
    sections: Option<Vec<Section>>,
    photos: Option<Vec<Option<String>>>,
    spectrum: Option<Vec<Vec<f64>>>,
    appendices: Option<Vec<Option<Appendix>>>,
    document_info: Option<Info>,
}
#[derive(Clone, Deserialize)]
struct Section {
    title: String,
    headers: Vec<Option<String>>,
    rows: Vec<Vec<Option<String>>>,
    layout: Option<String>,
}
#[derive(Clone, Deserialize)]
struct Appendix {
    title: Option<String>,
    pdf: Option<String>,
}
#[derive(Clone, Deserialize, Default)]
#[serde(rename_all = "camelCase")]
struct Info {
    file_number: Option<String>,
    version: Option<String>,
    website: Option<String>,
    email: Option<String>,
}
fn safe(s: &Option<String>) -> &str {
    s.as_deref().unwrap_or("")
}
fn java_ws(c: char) -> bool {
    matches!(c,'\u{0009}'..='\u{000d}'|'\u{001c}'..='\u{0020}'|'\u{1680}'|'\u{2000}'..='\u{2006}'|'\u{2008}'..='\u{200a}'|'\u{2028}'|'\u{2029}'|'\u{205f}'|'\u{3000}')
}
fn blank(s: &str) -> bool {
    s.chars().all(java_ws)
}
fn val(s: &Option<String>) -> String {
    if blank(safe(s)) {
        "—".into()
    } else {
        safe(s).into()
    }
}
impl Section {
    fn kind(&self) -> &str {
        if blank(safe(&self.layout)) {
            "table"
        } else {
            safe(&self.layout)
        }
    }
    fn title_ref(&self) -> Option<&str> {
        match self.kind() {
            "conditions" => Some("测试条件"),
            "setup" => Some("测试设置"),
            "electrical" => Some("电参数"),
            "color" => Some("色度测试"),
            "parameters" | "table" => Some(&self.title),
            _ => None,
        }
    }
}
fn num(n: f32) -> String {
    let s = format!("{n:.5}");
    let s = s.trim_end_matches('0').trim_end_matches('.');
    if s == "-0" { "0".into() } else { s.into() }
}
fn arr(a: &[f32]) -> Object {
    Object::Array(a.iter().map(|v| Object::Real(*v)).collect())
}
fn utf16(s: &str) -> Object {
    let mut b = vec![0xfe, 0xff];
    for c in s.encode_utf16() {
        b.extend(c.to_be_bytes());
    }
    Object::String(b, StringFormat::Hexadecimal)
}
struct FontData {
    bytes: Vec<u8>,
    glyphs: HashMap<char, (u16, f32)>,
    advances: Vec<f32>,
    substitutions: shaping::Rules,
    ligature_unicode: HashMap<u16, char>,
    descent: f32,
    ascent: f32,
    cap: f32,
    bbox: [f32; 4],
    name: String,
    italic: f32,
}
impl FontData {
    fn load(path: &Path) -> Result<Self> {
        Self::from_bytes(fs::read(path)?)
    }
    fn from_bytes(bytes: Vec<u8>) -> Result<Self> {
        let face =
            ttf_parser::Face::parse(&bytes, 0).map_err(|e| anyhow::anyhow!("Font parse: {e:?}"))?;
        ensure!(
            face.tables().glyf.is_some(),
            "Renderer requires a TrueType glyf font"
        );
        let scale = 1000. / face.units_per_em() as f32;
        let mut glyphs = HashMap::new();
        if let Some(cmap) = face.tables().cmap {
            for sub in cmap.subtables {
                if sub.is_unicode() {
                    sub.codepoints(|cp| {
                        if let Some(c) = char::from_u32(cp)
                            && let Some(g) = face.glyph_index(c)
                        {
                            let w = (face.glyph_hor_advance(g).unwrap_or(0) as f32 * scale).round();
                            glyphs.insert(c, (g.0, w));
                        }
                    });
                }
            }
        }
        let advances = (0..face.number_of_glyphs())
            .map(|g| {
                (face.glyph_hor_advance(ttf_parser::GlyphId(g)).unwrap_or(0) as f32 * scale).round()
            })
            .collect();
        let substitutions = shaping::rules(&face);
        let ligature_unicode = (0xFB00..=0xFB06)
            .chain([0x2E3A, 0x2E3B])
            .filter_map(char::from_u32)
            .filter_map(|c| face.glyph_index(c).map(|gid| (gid.0, c)))
            .collect();
        let b = face.global_bounding_box();
        let descent = face.tables().hhea.descender as f32 * scale;
        let ascent = face.tables().hhea.ascender as f32 * scale;
        let cap = face.capital_height().unwrap_or(face.tables().hhea.ascender) as f32 * scale;
        let name = face
            .names()
            .into_iter()
            .find(|n| n.name_id == 6)
            .and_then(|n| n.to_string())
            .unwrap_or("LimsFont".into());
        let italic = face.italic_angle();
        Ok(Self {
            glyphs,
            advances,
            substitutions,
            ligature_unicode,
            descent,
            ascent,
            cap,
            bbox: [
                b.x_min as f32 * scale,
                b.y_min as f32 * scale,
                b.x_max as f32 * scale,
                b.y_max as f32 * scale,
            ],
            name,
            italic,
            bytes,
        })
    }
}
static DEFAULT_FONT: OnceLock<std::result::Result<Arc<FontData>, String>> = OnceLock::new();
static FONT_CACHE: LazyLock<Mutex<HashMap<std::path::PathBuf, Arc<FontData>>>> =
    LazyLock::new(|| Mutex::new(HashMap::new()));
fn cached_font(path: &Path) -> Result<Arc<FontData>> {
    if let Some(font) = FONT_CACHE
        .lock()
        .map_err(|_| anyhow::anyhow!("Font cache poisoned"))?
        .get(path)
        .cloned()
    {
        return Ok(font);
    }
    let font = Arc::new(FontData::load(path)?);
    Ok(FONT_CACHE
        .lock()
        .map_err(|_| anyhow::anyhow!("Font cache poisoned"))?
        .entry(path.to_owned())
        .or_insert(font)
        .clone())
}
fn default_font() -> Result<Arc<FontData>> {
    DEFAULT_FONT
        .get_or_init(|| {
            FontData::from_bytes(include_bytes!("../assets/LimsSongSC-Regular.ttf").to_vec())
                .map(Arc::new)
                .map_err(|e| e.to_string())
        })
        .clone()
        .map_err(anyhow::Error::msg)
}
struct Font {
    data: Arc<FontData>,
    used: RefCell<BTreeMap<(String, u16), u16>>,
}
impl Font {
    fn new(data: Arc<FontData>) -> Self {
        Self {
            data,
            used: RefCell::new(BTreeMap::new()),
        }
    }
    fn measure(&self, s: &str, size: f32) -> Result<f32> {
        let mut w = 0f32;
        for c in s.chars() {
            w += self
                .data
                .glyphs
                .get(&c)
                .with_context(|| format!("No glyph for U+{:04X} in {}", c as u32, self.data.name))?
                .1;
        }
        Ok(w / 1000. * size)
    }
    fn hex(&self, s: &str) -> Result<String> {
        use std::fmt::Write;
        let mut out = String::new();
        let mut used = self.used.borrow_mut();
        let glyphs = s
            .chars()
            .map(|c| {
                self.data
                    .glyphs
                    .get(&c)
                    .map(|v| (c.to_string(), v.0))
                    .with_context(|| format!("No glyph for U+{:04X}", c as u32))
            })
            .collect::<Result<Vec<_>>>()?;
        for (mut text, gid) in shaping::shape(glyphs, &self.data.substitutions) {
            // PDFBox maps standard Latin ligatures to the font cmap character.
            // Also mirror the two proven U+2014 ccmp presentation forms exactly.
            // This is not a general Unicode normalization or en-dash alias rule.
            if text.chars().count() > 1
                && let Some(c) = self.data.ligature_unicode.get(&gid)
            {
                use unicode_normalization::UnicodeNormalization;
                if text.nfkc().eq(c.to_string().nfkc())
                    || (*c == '\u{2E3A}' && text == "——")
                    || (*c == '\u{2E3B}' && text == "———")
                {
                    text = c.to_string();
                }
            }
            let key = (text, gid);
            let next = used.len() + 1;
            ensure!(next < 65536, "Too many distinct font clusters");
            let id = *used.entry(key).or_insert(next as u16);
            write!(out, "{id:04X}")?;
        }
        Ok(out)
    }
    fn embed(&self, doc: &mut Document) -> Result<ObjectId> {
        let used = self.used.borrow();
        let mut remap = subsetter::GlyphRemapper::new();
        for (_, gid) in used.keys() {
            remap.remap(*gid);
        }
        let bytes = subsetter::subset(&self.data.bytes, 0, &remap)
            .map_err(|e| anyhow::anyhow!("Font subset: {e:?}"))?;
        let n = bytes.len();
        let file = doc.add_object(Stream::new(dictionary! {"Length1"=>n as i64}, bytes));
        let name = format!("LMRUST+{}", self.data.name);
        let fd=doc.add_object(dictionary!{"Type"=>"FontDescriptor","FontName"=>Object::Name(name.as_bytes().to_vec()),"Flags"=>4,"FontBBox"=>arr(&self.data.bbox),"ItalicAngle"=>self.data.italic,"Ascent"=>self.data.ascent,"Descent"=>self.data.descent,"CapHeight"=>self.data.cap,"StemV"=>80,"FontFile2"=>file});
        let mut mappings = vec![0u8; (used.len() + 1) * 2];
        let mut ordered: Vec<_> = used.iter().collect();
        ordered.sort_by_key(|(_, cid)| **cid);
        let mut widths = Vec::new();
        let mut cmap = String::from(
            "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n/CMapName /LimsToUnicode def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n",
        );
        for chunk in ordered.chunks(100) {
            cmap.push_str(&format!("{} beginbfchar\n", chunk.len()));
            for ((text, source_gid), cid) in chunk {
                let gid = remap.get(*source_gid).unwrap();
                mappings[**cid as usize * 2..**cid as usize * 2 + 2]
                    .copy_from_slice(&gid.to_be_bytes());
                widths.push(Object::Integer(**cid as i64));
                widths.push(Object::Array(vec![Object::Real(
                    self.data.advances[*source_gid as usize],
                )]));
                let mut out = String::new();
                for cp in text.encode_utf16() {
                    out.push_str(&format!("{cp:04X}"));
                }
                cmap.push_str(&format!("<{cid:04X}> <{out}>\n"));
            }
            cmap.push_str("endbfchar\n");
        }
        cmap.push_str("endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n");
        let unicode = doc.add_object(Stream::new(Dictionary::new(), cmap.into_bytes()));
        let map = doc.add_object(Stream::new(Dictionary::new(), mappings));
        let cid=doc.add_object(dictionary!{"Type"=>"Font","Subtype"=>"CIDFontType2","BaseFont"=>Object::Name(name.as_bytes().to_vec()),"CIDSystemInfo"=>dictionary!{"Registry"=>Object::string_literal("Adobe"),"Ordering"=>Object::string_literal("Identity"),"Supplement"=>0},"FontDescriptor"=>fd,"DW"=>1000,"W"=>Object::Array(widths),"CIDToGIDMap"=>map});
        Ok(doc.add_object(dictionary!{"Type"=>"Font","Subtype"=>"Type0","BaseFont"=>Object::Name(name.as_bytes().to_vec()),"Encoding"=>"Identity-H","DescendantFonts"=>vec![Object::Reference(cid)],"ToUnicode"=>unicode}))
    }
}
#[derive(Default)]
struct Page {
    content: String,
    images: Vec<(String, ObjectId)>,
}
struct Renderer {
    doc: Document,
    pages: Vec<Page>,
    font: Font,
    heading: Option<Font>,
    anchors: Vec<Anchor>,
    page: Option<usize>,
    top: f32,
}
#[derive(Clone)]
struct Anchor {
    kind: String,
    title: String,
    page: usize,
}
#[derive(Clone)]
struct Entry {
    title: String,
    page: usize,
    level: u8,
}
impl Renderer {
    fn new(font: Arc<FontData>, heading: Option<Arc<FontData>>) -> Self {
        Self {
            doc: Document::with_version("1.7"),
            pages: Vec::new(),
            font: Font::new(font),
            heading: heading.map(Font::new),
            anchors: vec![],
            page: None,
            top: TOP,
        }
    }
    fn new_page(&mut self) {
        self.pages.push(Page::default());
        self.page = Some(self.pages.len() - 1);
    }
    fn op(&mut self, s: String) {
        self.pages[self.page.expect("Canvas is required")]
            .content
            .push_str(&s);
    }
    fn next(&mut self) {
        self.page = None;
        self.top = TOP;
    }
    fn ensure(&mut self, h: f32) -> Result<()> {
        ensure!(h <= BOTTOM - TOP, "Report cell exceeds page capacity");
        if self.page.is_some() && self.top + h > BOTTOM {
            self.next();
        }
        if self.page.is_none() {
            self.new_page();
        }
        Ok(())
    }
    // Matches the source canvas primitives; grouping coordinates would obscure the port.
    #[allow(clippy::too_many_arguments)]
    fn text_raw(
        &mut self,
        s: &str,
        size: f32,
        x: f32,
        top: f32,
        color: [u8; 3],
        heading: bool,
        spacing: Option<f32>,
    ) -> Result<()> {
        let f = if heading {
            self.heading.as_ref().unwrap_or(&self.font)
        } else {
            &self.font
        };
        let hex = f.hex(s)?;
        let y = HEIGHT - top - size * (1. + f.data.descent / 1000.);
        let fname = if heading && self.heading.is_some() {
            "F2"
        } else {
            "F1"
        };
        let stroke = if heading && self.heading.is_none() {
            "0 0 0 RG 0.2 w 2 Tr\n"
        } else {
            ""
        };
        let tc = spacing
            .map(|v| format!("{} Tc\n", num(v)))
            .unwrap_or_default();
        self.op(format!(
            "q\n{} {} {} rg\n{stroke}BT\n/{fname} {} Tf\n{tc}{} {} Td\n<{hex}> Tj\nET\nQ\n",
            num(color[0] as f32 / 255.),
            num(color[1] as f32 / 255.),
            num(color[2] as f32 / 255.),
            num(size),
            num(x),
            num(y)
        ));
        Ok(())
    }
    fn text(&mut self, s: &str, size: f32, x: f32, top: f32) -> Result<()> {
        self.text_raw(s, size, x, top, BLACK, false, None)
    }
    fn center(&mut self, s: &str, size: f32, x: f32, top: f32) -> Result<()> {
        let x = x - self.font.measure(s, size)? / 2.;
        self.text(s, size, x, top)
    }
    fn colored(&mut self, s: &str, size: f32, x: f32, top: f32) -> Result<()> {
        let x = x - self.font.measure(s, size)? / 2.;
        self.text_raw(s, size, x, top, BLUE, false, None)
    }
    fn heading(&mut self, s: &str, size: f32, x: f32, top: f32, centered: bool) -> Result<()> {
        let f = self.heading.as_ref().unwrap_or(&self.font);
        let x = if centered {
            x - f.measure(s, size)? / 2.
        } else {
            x
        };
        self.text_raw(s, size, x, top, BLACK, true, None)
    }
    fn wrap(&self, s: &str, size: f32, width: f32) -> Result<Vec<String>> {
        let mut result = vec![];
        let normalized = s.replace("\r\n", "\n").replace(
            [
                '\r', '\u{000b}', '\u{000c}', '\u{0085}', '\u{2028}', '\u{2029}',
            ],
            "\n",
        );
        for paragraph in normalized.split('\n') {
            let mut current = String::new();
            for c in paragraph.chars() {
                let mut test = current.clone();
                test.push(c);
                if !current.is_empty() && self.font.measure(&test, size)? > width {
                    let space = current.rfind(' ');
                    let hyphen = current.rfind('-');
                    if hyphen.is_some_and(|h| h > space.unwrap_or(0) && h > 0) {
                        let i = hyphen.unwrap() + 1;
                        result.push(current[..i].trim_matches(java_ws).into());
                        current = current[i..].into();
                    } else if space.is_some_and(|s| s > 0) {
                        let i = space.unwrap();
                        result.push(current[..i].trim_matches(java_ws).into());
                        current = current[i + 1..].into();
                    } else {
                        result.push(std::mem::take(&mut current));
                    }
                }
                current.push(c);
            }
            result.push(current.trim_matches(java_ws).into());
        }
        Ok(result)
    }
    // Source canvas primitive: coordinates, leading, and alignment are independent.
    #[allow(clippy::too_many_arguments)]
    fn lines(
        &mut self,
        lines: &[String],
        size: f32,
        x: f32,
        mut top: f32,
        leading: f32,
        centered: bool,
        width: f32,
    ) -> Result<()> {
        for line in lines {
            if centered {
                self.center(line, size, x + width / 2., top)?;
            } else {
                self.text(line, size, x, top)?;
            }
            top += leading;
        }
        Ok(())
    }
    fn rule(&mut self, left: f32, right: f32, top: f32, stroke: f32, color: [u8; 3]) {
        self.op(format!(
            "q {} {} {} RG {} w {} {} m {} {} l S Q\n",
            num(color[0] as f32 / 255.),
            num(color[1] as f32 / 255.),
            num(color[2] as f32 / 255.),
            num(stroke),
            num(left),
            num(HEIGHT - top),
            num(right),
            num(HEIGHT - top)
        ));
    }
    fn dotted(&mut self, left: f32, right: f32, top: f32) {
        if right <= left {
            return;
        }
        self.op("q [1 2] 0 d\n".into());
        self.rule(left, right, top, 0.8, BLACK);
        self.op("Q\n".into());
    }
    fn rect(&mut self, x: f32, top: f32, w: f32, h: f32, stroke: f32) {
        self.op(format!(
            "q 0 0 0 RG {} w {} {} {} {} re S Q\n",
            num(stroke),
            num(x),
            num(HEIGHT - top - h),
            num(w),
            num(h)
        ));
    }
    fn image(&mut self, bytes: &[u8], x: f32, top: f32, w: f32, h: f32) -> Result<()> {
        self.image_inner(bytes, x, top, w, h)
            .map_err(|_| anyhow::anyhow!("Report images must contain valid PNG or JPEG bytes"))
    }
    fn image_inner(&mut self, bytes: &[u8], x: f32, top: f32, w: f32, h: f32) -> Result<()> {
        let format = image::guess_format(bytes)?;
        ensure!(
            matches!(
                format,
                image::ImageFormat::Png
                    | image::ImageFormat::Jpeg
                    | image::ImageFormat::Gif
                    | image::ImageFormat::Bmp
                    | image::ImageFormat::Tiff
            ),
            "Report images must contain valid PNG or JPEG bytes"
        );
        let (iw, ih, id) = if format == image::ImageFormat::Jpeg {
            use image::ImageDecoder;
            let decoder = image::codecs::jpeg::JpegDecoder::new(io::Cursor::new(bytes))?;
            let (iw, ih) = decoder.dimensions();
            let components = jpeg_components(bytes)?;
            let mut dict = dictionary! {"Type"=>"XObject","Subtype"=>"Image","Width"=>iw as i64,"Height"=>ih as i64,"ColorSpace"=>match components{1=>"DeviceGray",3=>"DeviceRGB",4=>"DeviceCMYK",_=>bail!("Unsupported JPEG component count")},"BitsPerComponent"=>8,"Filter"=>"DCTDecode"};
            if components == 4 {
                dict.set("Decode", arr(&[1., 0., 1., 0., 1., 0., 1., 0.]));
            }
            // JPEGFactory embeds original DCT bytes with a device color space;
            // unlike LosslessFactory it does not bind the JPEG's ICC profile.
            let mut stream = Stream::new(dict, bytes.to_vec());
            stream.allows_compression = false;
            (iw, ih, self.doc.add_object(stream))
        } else {
            image_samples::embed_lossless(&mut self.doc, bytes)?
        };
        let name = format!("I{}", self.pages[self.page.unwrap()].images.len() + 1);
        self.pages[self.page.unwrap()]
            .images
            .push((name.clone(), id));
        let scale = (w / iw as f32).min(h / ih as f32);
        let (dw, dh) = (iw as f32 * scale, ih as f32 * scale);
        self.op(format!(
            "q {} 0 0 {} {} {} cm /{name} Do Q\n",
            num(dw),
            num(dh),
            num(x + (w - dw) / 2.),
            num(HEIGHT - top - (h + dh) / 2.)
        ));
        Ok(())
    }
    fn anchor(&mut self, kind: &str, title: &str) {
        self.anchors.push(Anchor {
            kind: kind.into(),
            title: title.into(),
            page: self.pages.len(),
        });
    }
}
#[derive(Clone)]
struct GridRow {
    cells: Vec<Option<String>>,
    widths: Vec<f32>,
    height: f32,
}
impl Renderer {
    fn body_heading(&mut self, s: &str, size: f32) -> Result<()> {
        self.ensure(42.)?;
        self.center(s, size, WIDTH / 2., self.top)?;
        self.top += 15.27;
        Ok(())
    }
    fn information(&mut self, row: &[Option<String>]) -> Result<()> {
        self.ensure(20.)?;
        let label = self.wrap(&val(&row[0]), 12., 122.)?;
        let result = self.wrap(&val(&row[1]), 12., 300.)?;
        let h = 18.5f32.max(label.len().max(result.len()) as f32 * 15. + 3.);
        if self.top + h > BOTTOM {
            self.next();
        }
        self.ensure(h)?;
        let y = self.top + (h - (label.len() - 1) as f32 * 15. - 12.) / 2.;
        self.lines(&label, 12., 65.75, y, 15., false, 0.)?;
        self.text(":", 12., 195.05, self.top + (h - 12.) / 2.)?;
        self.lines(
            &result,
            12.,
            228.15,
            self.top + (h - (result.len() - 1) as f32 * 15. - 12.) / 2.,
            15.,
            false,
            0.,
        )?;
        self.top += h;
        self.rule(59.7, 535.7, self.top, 0.5, GRAY);
        Ok(())
    }
    fn signatures(&mut self, s: &Section) -> Result<()> {
        if s.rows.is_empty() {
            return Ok(());
        }
        self.ensure(20.)?;
        let mut names = vec![];
        let mut h = 48.;
        for row in &s.rows {
            let lines = self.wrap(&val(&row[1]), 12., 306.)?;
            h += 28f32.max(lines.len() as f32 * 15. + 10.);
            names.push(lines);
        }
        if self.top + h > BOTTOM {
            self.next();
        }
        self.ensure(h)?;
        self.top += 22.;
        for (i, row) in s.rows.iter().enumerate() {
            self.text(&val(&row[0]), 12., 65.75, self.top)?;
            self.text("........................", 12., 103., self.top)?;
            self.lines(&names[i], 12., 228.15, self.top, 15., false, 0.)?;
            self.top += 28f32.max(names[i].len() as f32 * 15. + 10.);
        }
        self.text("日期", 12., 65.75, self.top)?;
        self.text("...............................", 12., 94., self.top)?;
        self.top += 30.;
        Ok(())
    }
    fn row_height(&mut self, row: &[Option<String>], widths: &[f32]) -> Result<f32> {
        self.ensure(0.)?;
        let mut lines = 1;
        for (i, cell) in row.iter().enumerate() {
            lines = lines.max(self.wrap(safe(cell), 9., widths[i] - 8.)?.len());
        }
        Ok(24.5f32.max(lines as f32 * 12. + 8.))
    }
    fn grid(
        &mut self,
        row: &[Option<String>],
        widths: &[f32],
        h: f32,
        standard: bool,
        centered: bool,
    ) -> Result<()> {
        let mut x = LEFT;
        for (i, cell) in row.iter().enumerate() {
            self.rect(x, self.top, widths[i], h, 0.5);
            let lines = self.wrap(safe(cell), 9., widths[i] - 8.)?;
            let y = self.top + (h - (lines.len() - 1) as f32 * 12. - 9.) / 2. + 1.5;
            let center = centered || (standard && i == 0);
            self.lines(
                &lines,
                9.,
                if center { x } else { x + 4. },
                y,
                12.,
                center,
                widths[i],
            )?;
            x += widths[i];
        }
        self.top += h;
        Ok(())
    }
    fn parameter_rows(&mut self, s: &Section) -> Result<Vec<GridRow>> {
        let mut rows = vec![];
        let mut i = 0;
        while i < s.rows.len() {
            let first = &s.rows[i];
            let wide = self.wrap(&val(&first[1]), 9., 112.5)?.len() > 2;
            let mut cells = first.clone();
            if !wide {
                if i + 1 < s.rows.len() && self.wrap(&val(&s.rows[i + 1][1]), 9., 112.5)?.len() <= 2
                {
                    i += 1;
                    cells.extend(s.rows[i].clone());
                } else {
                    cells.push(Some("".into()));
                    cells.push(Some("".into()));
                }
            }
            let widths = if wide {
                vec![120.5, 361.5]
            } else {
                vec![120.5; 4]
            };
            let height = self.row_height(&cells, &widths)?;
            rows.push(GridRow {
                cells,
                widths,
                height,
            });
            i += 1;
        }
        Ok(rows)
    }
    fn parameter_heading(&mut self, s: &Section, continued: bool, register: bool) -> Result<()> {
        ensure!(
            s.title_ref().is_some() || self.page.is_some(),
            "Java reference cannot continue a headingless parameter section"
        );
        if let Some(title) = s.title_ref() {
            self.body_heading(
                &format!("{title}{}", if continued { "（续）" } else { "" }),
                9.,
            )?;
        }
        if register {
            self.anchor(s.kind(), &s.title);
        }
        if safe(&s.headers[1]) != "结果 / 信息" {
            self.center(safe(&s.headers[1]), 9., WIDTH / 2., self.top)?;
            self.top += 14.;
        }
        Ok(())
    }
    fn parameters(&mut self, s: &Section) -> Result<()> {
        if s.rows.is_empty() {
            return Ok(());
        }
        self.ensure(0.)?;
        let rows = self.parameter_rows(s)?;
        let header_h = if s.title_ref().is_some() { 15.27 } else { 0. }
            + if safe(&s.headers[1]) != "结果 / 信息" {
                14.
            } else {
                0.
            };
        let mut h = header_h + 15.23;
        for row in &rows {
            h += row.height;
        }
        if h <= BOTTOM - TOP && self.top + h > BOTTOM {
            self.next();
        }
        self.ensure(42.)?;
        self.parameter_heading(s, false, true)?;
        for row in rows {
            ensure!(
                row.height <= BOTTOM - TOP - header_h,
                "Parameter exceeds page capacity"
            );
            if self.top + row.height > BOTTOM {
                self.next();
                self.parameter_heading(s, true, false)?;
                ensure!(
                    self.page.is_some(),
                    "Java reference cannot continue a headingless parameter section"
                );
            }
            self.grid(&row.cells, &row.widths, row.height, false, true)?;
        }
        self.top += 15.23;
        Ok(())
    }
    fn table(&mut self, s: &Section, standard: bool) -> Result<()> {
        if s.rows.is_empty() {
            return Ok(());
        }
        let widths = if standard {
            vec![36., 446.]
        } else if s.headers.len() == 5 {
            vec![133., 76., 112., 91., 70.]
        } else {
            vec![482. / s.headers.len() as f32; s.headers.len()]
        };
        self.ensure(70.)?;
        if let Some(title) = s.title_ref() {
            self.body_heading(title, 12.)?;
        }
        self.anchor(s.kind(), &s.title);
        let h = self.row_height(&s.headers, &widths)?;
        self.grid(&s.headers, &widths, h, false, true)?;
        for row in &s.rows {
            let h = self.row_height(row, &widths)?;
            if self.top + h > BOTTOM {
                self.next();
                if let Some(title) = s.title_ref() {
                    self.body_heading(&format!("{title}（续）"), 12.)?;
                }
                let hh = self.row_height(&s.headers, &widths)?;
                self.grid(&s.headers, &widths, hh, false, true)?;
            }
            self.ensure(h)?;
            self.grid(row, &widths, h, standard, !standard)?;
        }
        self.top += 18.;
        Ok(())
    }
    fn photos(&mut self, images: &[Option<String>]) -> Result<()> {
        self.next();
        for start in (0..images.len()).step_by(2) {
            if start != 0 {
                self.next();
            }
            self.ensure(42.)?;
            if start == 0 {
                self.anchor("photos", "Photos of Sample");
            }
            let count = 2.min(images.len() - start);
            self.rect(54.7, 114., 486., count as f32 * 283.95, 0.5);
            if count == 2 {
                self.rule(54.7, 540.7, 397.95, 0.5, BLACK);
            }
            for slot in 0..count {
                let bytes = base64::engine::general_purpose::STANDARD
                    .decode(safe(&images[start + slot]))
                    .context("Invalid sample photo base64")?;
                ensure!(!bytes.is_empty(), "Empty sample photo");
                self.image(&bytes, 109.7, 114. + slot as f32 * 283.95, 376.45, 283.45)?;
            }
        }
        Ok(())
    }
    fn spectrum(&mut self, input: &[Vec<f64>], meta: &[&Section]) -> Result<()> {
        ensure!(
            (2..=10000).contains(&input.len()),
            "Invalid spectrum point count"
        );
        let mut points = input.to_vec();
        for p in &points {
            ensure!(
                p.len() == 2 && p[0].is_finite() && p[1].is_finite() && p[0] > 0. && p[1] >= 0.,
                "Invalid spectrum data"
            );
        }
        points.sort_by(|a, b| a[0].partial_cmp(&b[0]).unwrap());
        let (low, high) = (points[0][0], points.last().unwrap()[0]);
        let max = points.iter().map(|p| p[1]).fold(0., f64::max);
        ensure!(high > low && max > 0., "Invalid spectrum range");
        self.next();
        self.ensure(42.)?;
        self.anchor("spectrum", "光谱功率分布（SPD）");
        for s in meta {
            for row in self.parameter_rows(s)? {
                self.ensure(row.height)?;
                self.grid(&row.cells, &row.widths, row.height, false, true)?;
            }
        }
        if self.top + 375. > BOTTOM {
            self.next();
            self.ensure(42.)?;
        }
        self.text("相对光谱功率（最大值归一化为 1）", 9., LEFT, self.top + 15.)?;
        self.op("q\n".into());
        let (x0, w, h) = (86., 440., 280.);
        let plot_top = 200f32.max(self.top + 50.);
        for tick in 0..=5 {
            let y = plot_top + h - h * tick as f32 / 5.;
            self.rule(x0, x0 + w, y, 0.5, GRAY);
            self.text(&format!("{:.1}", tick as f64 / 5.), 9., 58., y - 4.)?;
            self.center(
                &java_round0(low + (high - low) * tick as f64 / 5.),
                9.,
                x0 + w * tick as f32 / 5.,
                plot_top + h + 8.,
            )?;
        }
        self.op(format!(
            "{} {} {} RG 1 w\n",
            num(26. / 255.),
            num(57. / 255.),
            num(107. / 255.)
        ));
        for (i, p) in points.iter().enumerate() {
            let x = x0 + ((p[0] - low) / (high - low) * w as f64) as f32;
            let y = HEIGHT - plot_top - h + (p[1] / max * h as f64) as f32;
            self.op(format!(
                "{} {} {}\n",
                num(x),
                num(y),
                if i == 0 { "m" } else { "l" }
            ));
        }
        self.op("S Q\n".into());
        self.center("波长 (nm)", 10., x0 + w / 2., plot_top + h + 30.)?;
        self.top = plot_top + h + 48.;
        Ok(())
    }
    fn cover(&mut self, p: &Payload, standards: &[&Section]) -> Result<()> {
        let mut values = vec![];
        for s in standards {
            for row in &s.rows {
                if !blank(safe(&row[1])) {
                    values.push(safe(&row[1]));
                }
            }
        }
        let mut reference = if values.is_empty() {
            "—".into()
        } else {
            values.join("; ")
        };
        if self.font.measure(&reference, 12.)? > 263. * 3. {
            reference = "详见引用标准章节".into();
        }
        let fields = [
            ("报告编号：", safe(&p.report_number)),
            ("申请人：", safe(&p.applicant)),
            ("参考标准：", &reference),
            ("产品名称：", safe(&p.product_name)),
            ("型号：", safe(&p.model)),
            ("接收日期：", safe(&p.received_date)),
            ("签发日期：", safe(&p.issued_date)),
        ];
        self.new_page();
        let text = "检测报告";
        let width = self.font.measure(text, 36.)? + 14.113 * (text.chars().count() - 1) as f32;
        self.text_raw(
            text,
            36.,
            WIDTH / 2. - width / 2.,
            236.02,
            BLACK,
            false,
            Some(14.113),
        )?;
        let tops = [319.77, 345.87, 365.38, 399.57, 425.67, 451.77, 477.87];
        let mut extra = 0.;
        let mut top = 319.77;
        for (i, (label, value)) in fields.iter().enumerate() {
            let size = if i == 2 { 12. } else { 11. };
            let mut lines = self.wrap(value, size, 263.)?;
            if *label == "参考标准：" && value.starts_with("Commission test  ") {
                lines = vec!["Commission test".into()];
                lines.extend(self.wrap(&value["Commission test  ".len()..], 12., 263.)?);
            }
            top = tops[i] + extra;
            let label_top = if i == 2 { top + 7.99 } else { top };
            self.text(label, 11., 132.75, label_top)?;
            let value_top = if i == 2 && lines.len() == 1 {
                label_top - 0.5
            } else {
                top
            };
            self.lines(&lines, size, 211.25, value_top, 13.8, false, 263.)?;
            let expected = if i == 2 { 2 } else { 1 };
            extra += lines.len().saturating_sub(expected) as f32 * 13.8;
            top += lines.len() as f32 * 13.8;
        }
        let company_top = 598.7f32.max(top + 48.);
        ensure!(
            company_top + 28. <= BOTTOM,
            "Cover values exceed the report cover capacity"
        );
        self.center(safe(&p.lab_name), 15., WIDTH / 2., company_top)?;
        Ok(())
    }
    fn contents(&mut self, entries: &[Entry], pages: usize) -> Result<()> {
        for page in 0..pages {
            self.page = Some(1 + page);
            self.heading(
                if page == 0 {
                    "CONTENTS"
                } else {
                    "CONTENTS (continued)"
                },
                16.,
                WIDTH / 2.,
                93.53,
                true,
            )?;
            self.heading("Cover page", 12., 83.4, 165.38, false)?;
            self.heading("Contents", 12., 83.4, 187.03, false)?;
            let mut top = 220.68;
            for e in entries.iter().skip(page * 22).take(22) {
                let left = if e.level == 0 { 68.25 } else { 83.4 };
                ensure!(
                    self.wrap(&e.title, 12., 400.)?.len() == 1,
                    "Contents heading exceeds line capacity"
                );
                self.text(&e.title, 12., left, top)?;
                let pn = e.page.to_string();
                self.text(&pn, 12., 539. - self.font.measure(&pn, 12.)?, top)?;
                self.dotted(
                    left + self.font.measure(&e.title, 12.)? + 3.,
                    531.,
                    top + 10.,
                );
                top += 21.8;
            }
        }
        Ok(())
    }
    fn footer(&mut self, p: &Payload, page: usize, total: usize) -> Result<()> {
        let info = p.document_info.clone().unwrap_or_default();
        let file = if blank(safe(&info.file_number)) {
            "FO-22-03-2402"
        } else {
            safe(&info.file_number)
        };
        let version = if blank(safe(&info.version)) {
            "V1.0"
        } else {
            safe(&info.version)
        };
        self.colored(safe(&p.lab_name), 18., WIDTH / 2., 43.6)?;
        self.text(&format!("文件编号:{file}"), 9., 435., 43.6)?;
        self.text(&format!("文件版本:{version}"), 9., 435., 53.95)?;
        self.rule(34., 561.25, 71.75, 1.125, [26, 57, 107]);
        self.colored(safe(&p.lab_name), 12., 246.85, 739.69)?;
        self.center(&format!("第 {page} 页  共 {total} 页"), 9., 490., 740.78)?;
        let contacts = !blank(safe(&info.website)) || !blank(safe(&info.email));
        let lines = self.wrap(safe(&p.lab_address), 9., if contacts { 172. } else { 464. })?;
        ensure!(lines.len() <= 4, "Footer address exceeds page capacity");
        self.lines(&lines, 9., 70.2, 752.45, 11., false, 0.)?;
        if !blank(safe(&info.website)) {
            self.text(&format!("URL：{}", safe(&info.website)), 9., 251.65, 752.45)?;
        }
        if !blank(safe(&info.email)) {
            self.text(&format!("E-mail：{}", safe(&info.email)), 9., 408.2, 752.45)?;
        }
        Ok(())
    }
}
// Java Formatter uses decimal half-up for positive zero-decimal spectrum labels.
fn java_round0(n: f64) -> String {
    format!("{:.0}", (n + 0.5).floor())
}
fn entries(anchors: &[Anchor]) -> Vec<Entry> {
    let mut out = vec![];
    fn chapter(out: &mut Vec<Entry>, a: &[Anchor], title: &str, kinds: &[&str]) {
        if let Some(a) = a.iter().find(|a| kinds.contains(&a.kind.as_str())) {
            out.push(Entry {
                title: title.into(),
                page: a.page,
                level: 0,
            });
        }
    }
    fn add(
        out: &mut Vec<Entry>,
        anchors: &[Anchor],
        kind: &str,
        title: Option<&str>,
        level: u8,
        first: bool,
    ) {
        for a in anchors.iter().filter(|a| a.kind == kind) {
            out.push(Entry {
                title: title.unwrap_or(&a.title).into(),
                page: a.page,
                level,
            });
            if first {
                return;
            }
        }
    }
    chapter(
        &mut out,
        anchors,
        "1   General information",
        &["sample", "standards", "equipment", "table"],
    );
    add(
        &mut out,
        anchors,
        "sample",
        Some("1.1   Product Information"),
        1,
        true,
    );
    add(
        &mut out,
        anchors,
        "standards",
        Some("1.2   Standards or methods"),
        1,
        false,
    );
    add(
        &mut out,
        anchors,
        "equipment",
        Some("1.3   Test Equipment"),
        1,
        false,
    );
    add(&mut out, anchors, "table", None, 1, false);
    chapter(
        &mut out,
        anchors,
        "2   Test conducted and method",
        &["conditions", "setup"],
    );
    add(
        &mut out,
        anchors,
        "conditions",
        Some("2.1   Ambient Condition"),
        1,
        false,
    );
    add(
        &mut out,
        anchors,
        "setup",
        Some("2.7   Luminous Intensity Distribution Measurement Method"),
        1,
        false,
    );
    add(
        &mut out,
        anchors,
        "photos",
        Some("3   Photos of Sample"),
        0,
        true,
    );
    chapter(
        &mut out,
        anchors,
        "4   Summary of Test Result",
        &[
            "electrical",
            "photometric",
            "color",
            "parameters",
            "uncertainty",
            "spectrum",
        ],
    );
    add(
        &mut out,
        anchors,
        "electrical",
        Some("4.1   Electrical Property Parameters"),
        1,
        false,
    );
    add(
        &mut out,
        anchors,
        "photometric",
        Some("4.2   Photometric parameters"),
        1,
        false,
    );
    add(
        &mut out,
        anchors,
        "color",
        Some("4.3   Color Parameters"),
        1,
        false,
    );
    add(&mut out, anchors, "parameters", None, 1, false);
    add(
        &mut out,
        anchors,
        "distribution",
        Some("5   Luminous Intensity Distribution test data"),
        0,
        true,
    );
    add(&mut out, anchors, "appendix", None, 0, false);
    out
}
fn validate(p: &Payload) -> Result<()> {
    ensure!(
        !blank(safe(&p.report_number)) && !blank(safe(&p.lab_name)) && p.sections.is_some(),
        "Report number, laboratory and sections are required"
    );
    for s in p.sections.as_ref().unwrap() {
        ensure!(
            !blank(&s.title) && !s.headers.is_empty() && s.headers.len() <= 6,
            "Invalid report table"
        );
        ensure!(
            [
                "sample",
                "information",
                "parameters",
                "setup",
                "uncertainty",
                "spectrum",
                "standards",
                "table",
                "signatures",
                "equipment",
                "conditions",
                "electrical",
                "photometric",
                "color",
                "distribution"
            ]
            .contains(&s.kind()),
            "Unknown report section layout"
        );
        ensure!(
            ["table", "equipment"].contains(&s.kind()) || s.headers.len() == 2,
            "Invalid section column count"
        );
        for row in &s.rows {
            ensure!(row.len() == s.headers.len(), "Invalid report table row");
        }
    }
    Ok(())
}
fn sections<'a>(p: &'a Payload, kind: &str) -> Vec<&'a Section> {
    p.sections
        .as_ref()
        .unwrap()
        .iter()
        .filter(|s| s.kind() == kind && !s.rows.is_empty())
        .collect()
}
fn load_appendix(a: &Option<Appendix>) -> Result<(String, Document)> {
    let a = a.as_ref().context("Empty report appendix")?;
    let bytes = base64::engine::general_purpose::STANDARD.decode(safe(&a.pdf))?;
    ensure!(!bytes.is_empty(), "Empty report appendix");
    let doc = Document::load_mem(&bytes).context("Invalid report appendix PDF")?;
    ensure!(
        !doc.is_encrypted() && doc.encryption_state.is_none() && !doc.get_pages().is_empty(),
        "Appendices must be non-encrypted, unsigned PDFs with pages"
    );
    for o in doc.objects.values() {
        let d = match o {
            Object::Dictionary(d) => Some(d),
            Object::Stream(s) => Some(&s.dict),
            _ => None,
        };
        if let Some(d) = d {
            let sig = d
                .get(b"Type")
                .and_then(Object::as_name)
                .is_ok_and(|n| n == b"Sig")
                || d.get(b"FT")
                    .and_then(Object::as_name)
                    .is_ok_and(|n| n == b"Sig")
                    && d.has(b"V");
            ensure!(
                !sig,
                "Appendices must be non-encrypted, unsigned PDFs with pages"
            );
        }
    }
    Ok((val(&a.title), doc))
}
fn inherit(doc: &Document, id: ObjectId, key: &[u8]) -> Result<Option<Object>> {
    let mut id = id;
    for _ in 0..128 {
        let d = doc.get_dictionary(id)?;
        if let Ok(o) = d.get(key) {
            return Ok(Some(o.clone()));
        }
        if let Ok(parent) = d.get(b"Parent") {
            id = parent.as_reference()?;
        } else {
            return Ok(None);
        }
    }
    bail!("Appendix page tree exceeds depth limit")
}
impl Renderer {
    fn finish(self, p: &Payload, appendices: Vec<Document>, output: &Path) -> Result<usize> {
        self.finish_generic(
            &format!("LM-79 {}", safe(&p.report_number)),
            None,
            "New LIMS PDF Renderer",
            appendices,
            output,
        )
    }
    fn finish_generic(
        mut self,
        title: &str,
        author: Option<&str>,
        creator: &str,
        mut appendices: Vec<Document>,
        output: &Path,
    ) -> Result<usize> {
        let count = self.pages.len()
            + appendices
                .iter()
                .map(|d| d.get_pages().len())
                .sum::<usize>();
        let pages_id = self.doc.new_object_id();
        let font = self.font.embed(&mut self.doc)?;
        let heading = if let Some(f) = &self.heading {
            Some(f.embed(&mut self.doc)?)
        } else {
            None
        };
        let mut page_ids = vec![];
        for page in self.pages {
            let mut fonts = dictionary! {"F1"=>font};
            if let Some(id) = heading {
                fonts.set("F2", id);
            }
            let mut resources = dictionary! {"Font"=>fonts};
            if !page.images.is_empty() {
                let mut images = Dictionary::new();
                for (name, id) in page.images {
                    images.set(name, id);
                }
                resources.set("XObject", images);
            }
            let content = self
                .doc
                .add_object(Stream::new(Dictionary::new(), page.content.into_bytes()));
            let id=self.doc.add_object(dictionary!{"Type"=>"Page","Parent"=>pages_id,"MediaBox"=>arr(&[0.,0.,WIDTH,HEIGHT]),"Resources"=>resources,"Contents"=>content});
            page_ids.push(Object::Reference(id));
        }
        let mut appendix_catalog = appendix::CatalogMerge::default();
        for appendix in &mut appendices {
            appendix.renumber_objects_with(self.doc.max_id + 1);
            appendix_catalog.capture(appendix)?;
            for (_, id) in appendix.get_pages() {
                let mut d = appendix.get_dictionary(id)?.clone();
                for key in [b"MediaBox".as_slice(), b"CropBox", b"Resources", b"Rotate"] {
                    if !d.has(key)
                        && let Some(value) = inherit(appendix, id, key)?
                    {
                        d.set(key, value);
                    }
                }
                d.set("Parent", pages_id);
                appendix.objects.insert(id, Object::Dictionary(d));
                page_ids.push(Object::Reference(id));
            }
            self.doc.max_id = appendix.max_id;
            self.doc
                .objects
                .extend(std::mem::take(&mut appendix.objects));
        }
        self.doc.objects.insert(
            pages_id,
            Object::Dictionary(
                dictionary! {"Type"=>"Pages","Kids"=>page_ids,"Count"=>count as i64},
            ),
        );
        let mut catalog = dictionary! {"Type"=>"Catalog","Pages"=>pages_id};
        appendix_catalog.attach(&mut self.doc, &mut catalog)?;
        let root = self.doc.add_object(catalog);
        self.doc.trailer.set("Root", root);
        let mut meta = Dictionary::new();
        if !title.is_empty() {
            meta.set("Title", utf16(title));
        }
        if !creator.is_empty() {
            meta.set("Creator", utf16(creator));
        }
        if let Some(a) = author {
            meta.set("Author", utf16(a));
        }
        let info = self.doc.add_object(meta);
        self.doc.trailer.set("Info", info);
        self.doc.prune_objects();
        self.doc.compress();
        if let Some(parent) = output.parent() {
            fs::create_dir_all(parent)?;
        }
        self.doc.save(output)?;
        Ok(count)
    }
}
fn render(
    p: Payload,
    font: Arc<FontData>,
    heading: Option<Arc<FontData>>,
    output: &Path,
) -> Result<usize> {
    validate(&p)?;
    let information = sections(&p, "sample");
    let general = sections(&p, "information");
    let signers = sections(&p, "signatures");
    let standards = sections(&p, "standards");
    let mut tables = sections(&p, "equipment");
    tables.extend(sections(&p, "table"));
    let conditions = sections(&p, "conditions");
    let setup = sections(&p, "setup");
    let mut parameters = vec![];
    for kind in ["electrical", "photometric", "color", "parameters"] {
        parameters.extend(sections(&p, kind));
    }
    let uncertainty = sections(&p, "uncertainty");
    let spectrum_info = sections(&p, "spectrum");
    let distribution = sections(&p, "distribution");
    let photos = p.photos.as_deref().unwrap_or(&[]);
    let spectrum = p.spectrum.as_deref().unwrap_or(&[]);
    let entry_count = usize::from(
        !information.is_empty()
            || !general.is_empty()
            || !standards.is_empty()
            || !tables.is_empty(),
    ) + usize::from(!information.is_empty() || !general.is_empty())
        + standards.len()
        + tables.len()
        + usize::from(!conditions.is_empty() || !setup.is_empty())
        + conditions.len()
        + setup.len()
        + usize::from(
            !parameters.is_empty()
                || !uncertainty.is_empty()
                || !spectrum_info.is_empty()
                || !spectrum.is_empty(),
        )
        + parameters.len()
        + usize::from(!distribution.is_empty())
        + usize::from(!photos.is_empty())
        + p.appendices.as_ref().map_or(0, Vec::len);
    let toc_pages = 1.max(entry_count.div_ceil(22));
    let mut r = Renderer::new(font, heading);
    r.cover(&p, &standards)?;
    for _ in 0..toc_pages {
        r.new_page();
    }
    r.next();
    if !information.is_empty() || !general.is_empty() {
        r.ensure(20.)?;
        r.anchor("sample", "Product Information");
        for s in information.iter().chain(general.iter()) {
            for row in &s.rows {
                r.information(row)?;
            }
        }
    }
    for s in signers {
        r.signatures(s)?;
    }
    if !standards.is_empty() || !tables.is_empty() {
        r.next();
    }
    for s in standards {
        r.table(s, true)?;
    }
    for s in tables {
        r.table(s, false)?;
    }
    if !conditions.is_empty() || !setup.is_empty() {
        r.next();
    }
    for s in conditions.iter().chain(setup.iter()) {
        r.parameters(s)?;
    }
    if !photos.is_empty() {
        r.photos(photos)?;
    }
    if !parameters.is_empty() {
        r.next();
    }
    for s in parameters {
        r.parameters(s)?;
    }
    if !uncertainty.is_empty() {
        r.next();
    }
    for s in uncertainty {
        r.parameters(s)?;
    }
    if !spectrum.is_empty() {
        r.spectrum(spectrum, &spectrum_info)?;
    } else if !spectrum_info.is_empty() {
        r.next();
        for s in spectrum_info {
            r.parameters(s)?;
        }
    }
    for s in distribution {
        r.parameters(s)?;
    }
    let body_pages = r.pages.len();
    let mut appendix_docs = vec![];
    let mut total = body_pages;
    for a in p.appendices.as_deref().unwrap_or(&[]) {
        let (title, doc) = load_appendix(a)?;
        r.anchors.push(Anchor {
            kind: "appendix".into(),
            title,
            page: total + 1,
        });
        total += doc.get_pages().len();
        appendix_docs.push(doc);
    }
    r.contents(&entries(&r.anchors), toc_pages)?;
    for i in 0..body_pages {
        r.page = Some(i);
        r.footer(&p, i + 1, total)?;
    }
    r.finish(&p, appendix_docs, output)
}

impl Renderer {
    fn image_at(&mut self, bytes: &[u8], x: f32, y: f32, w: f32, h: f32) -> Result<()> {
        let idx = self.page.context("Canvas is required")?;
        let before = self.pages[idx].content.len();
        self.image(bytes, 0., 0., 1., 1.)?;
        self.pages[idx].content.truncate(before);
        let name = self.pages[idx].images.last().unwrap().0.clone();
        self.op(format!(
            "q {} 0 0 {} {} {} cm /{name} Do Q\n",
            num(w),
            num(h),
            num(x),
            num(y)
        ));
        Ok(())
    }
}

/// Render a native searchable contract PDF from the Java DTO JSON contract.
pub fn render_contract(
    payload: &serde_json::Value,
) -> std::result::Result<Vec<u8>, lims_core::ServiceError> {
    render_api("contract", payload)
}
pub fn render_entrust_order(
    payload: &serde_json::Value,
) -> std::result::Result<Vec<u8>, lims_core::ServiceError> {
    render_api("entrust", payload)
}
pub fn render_lighting_report(
    payload: &serde_json::Value,
) -> std::result::Result<Vec<u8>, lims_core::ServiceError> {
    render_api("lighting", payload)
}
pub fn render_lm79_report(
    payload: &serde_json::Value,
) -> std::result::Result<Vec<u8>, lims_core::ServiceError> {
    render_api("lm79", payload)
}
pub fn extract_cover(
    pdf: &[u8],
) -> std::result::Result<serde_json::Value, lims_core::ServiceError> {
    cover::extract_cover(pdf).map_err(|e| service_error(500, "COVER_EXTRACTION_FAILED", e))
}
fn service_error(status: u16, code: &str, e: impl std::fmt::Display) -> lims_core::ServiceError {
    lims_core::ServiceError {
        status,
        code: code.into(),
        message: Some(e.to_string()),
    }
}
fn configured_fonts(kind: &str) -> Result<(Arc<FontData>, Option<Arc<FontData>>)> {
    let lighting = matches!(kind, "lighting" | "lm79");
    let explicit = if lighting {
        std::env::var("LIGHTING_REPORT_FONT_PATH")
            .ok()
            .filter(|s| !blank(s))
    } else {
        None
    };
    let font = if let Some(path) = explicit {
        cached_font(Path::new(&path))?
    } else {
        let paths = [
            std::env::var("PDF_FONT_PATH").ok(),
            Some("/fonts/LimsSongSC-Regular.ttf".into()),
            Some("/fonts/NotoSansSC-VariableFont_wght.ttf".into()),
        ];
        let mut loaded = None;
        for path in paths.into_iter().flatten().filter(|s| !blank(s)) {
            if let Ok(font) = cached_font(Path::new(&path)) {
                loaded = Some(font);
                break;
            }
        }
        match loaded {
            Some(font) => font,
            None => default_font()?,
        }
    };
    let heading = if lighting {
        std::env::var("LIGHTING_REPORT_HEADING_FONT_PATH")
            .ok()
            .filter(|s| !blank(s))
            .map(|p| cached_font(Path::new(&p)))
            .transpose()?
    } else {
        None
    };
    Ok((font, heading))
}
fn render_api(
    kind: &str,
    payload: &serde_json::Value,
) -> std::result::Result<Vec<u8>, lims_core::ServiceError> {
    let mut payload = payload.clone();
    schema::validate_http(kind, &mut payload)
        .map_err(|e| service_error(400, "PDF_INVALID_JSON", e))?;
    if kind == "lm79" {
        prepare_lm79_json(&mut payload).map_err(|e| service_error(422, "PDF_RENDER_FAILED", e))?;
    }
    let (font, heading) =
        configured_fonts(kind).map_err(|e| service_error(500, "PDF_FONT_ERROR", e))?;
    let output =
        tempfile::NamedTempFile::new().map_err(|e| service_error(500, "PDF_TEMPFILE_ERROR", e))?;
    let result = match kind {
        "contract" => contract::render(payload, font, heading, output.path()),
        "entrust" => entrust::render(payload, font, heading, output.path()),
        "lighting" => lighting::render(payload, font, heading, output.path()),
        "lm79" => {
            let p = serde_json::from_value(payload)
                .map_err(|e| service_error(400, "PDF_INVALID_JSON", e))?;
            render(p, font, heading, output.path())
        }
        _ => unreachable!(),
    };
    result.map_err(|e| {
        service_error(
            if matches!(kind, "lighting" | "lm79") {
                422
            } else {
                500
            },
            "PDF_RENDER_FAILED",
            e,
        )
    })?;
    fs::read(output.path()).map_err(|e| service_error(500, "PDF_OUTPUT_ERROR", e))
}

fn jpeg_components(bytes: &[u8]) -> Result<u8> {
    let mut i = 2;
    while i + 4 <= bytes.len() {
        ensure!(bytes[i] == 0xff, "Invalid JPEG marker");
        while i < bytes.len() && bytes[i] == 0xff {
            i += 1;
        }
        ensure!(i < bytes.len(), "Truncated JPEG");
        let marker = bytes[i];
        i += 1;
        if marker == 0xd9 || marker == 0xda {
            break;
        }
        if marker == 0x01 || (0xd0..=0xd7).contains(&marker) {
            continue;
        }
        ensure!(i + 2 <= bytes.len(), "Truncated JPEG");
        let n = u16::from_be_bytes([bytes[i], bytes[i + 1]]) as usize;
        ensure!(n >= 2 && i + n <= bytes.len(), "Invalid JPEG segment");
        if matches!(marker,0xc0..=0xc3|0xc5..=0xc7|0xc9..=0xcb|0xcd..=0xcf) {
            ensure!(n >= 8 && bytes[i + 2] == 8, "Unsupported JPEG precision");
            return Ok(bytes[i + 7]);
        }
        i += n;
    }
    bail!("Missing JPEG frame")
}

fn prepare_lm79_json(p: &mut serde_json::Value) -> Result<()> {
    use serde_json::Value as V;
    ensure!(
        p.is_object() && p["sections"].is_array(),
        "Report number, laboratory and sections are required"
    );
    for section in p["sections"].as_array_mut().unwrap() {
        ensure!(
            section.is_object()
                && section["title"].is_string()
                && section["headers"].is_array()
                && section["rows"].is_array(),
            "Invalid report table"
        );
        ensure!(
            section["rows"].as_array().unwrap().iter().all(V::is_array),
            "Invalid report table row"
        );
    }
    if let Some(points) = p["spectrum"].as_array() {
        for point in points {
            ensure!(
                point
                    .as_array()
                    .is_some_and(|v| v.len() == 2 && v.iter().all(V::is_number)),
                "Invalid spectrum data"
            );
        }
    }
    fn bytes(v: &mut V) {
        if let Some(a) = v.as_array() {
            let data = a
                .iter()
                .map(|n| n.as_u64().unwrap() as u8)
                .collect::<Vec<_>>();
            *v = V::String(base64::engine::general_purpose::STANDARD.encode(data));
        }
    }
    if let Some(photos) = p["photos"].as_array_mut() {
        for photo in photos {
            bytes(photo)
        }
    }
    if let Some(appendices) = p["appendices"].as_array_mut() {
        for appendix in appendices {
            if let Some(o) = appendix.as_object_mut()
                && let Some(v) = o.get_mut("pdf")
            {
                bytes(v)
            }
        }
    }
    Ok(())
}

fn embed_icc(doc: &mut Document, profile: Vec<u8>, components: i64) -> Option<Object> {
    let expected = match profile.get(16..20)? {
        b"GRAY" => 1,
        b"RGB " => 3,
        b"CMYK" => 4,
        _ => return None,
    };
    if components != expected || profile.get(36..40)? != b"acsp" {
        return None;
    }
    let alternate = match components {
        1 => "DeviceGray",
        3 => "DeviceRGB",
        4 => "DeviceCMYK",
        _ => return None,
    };
    let id = doc.add_object(Stream::new(
        dictionary! {"N"=>components,"Alternate"=>alternate},
        profile,
    ));
    Some(Object::Array(vec![
        Object::Name(b"ICCBased".to_vec()),
        Object::Reference(id),
    ]))
}
/// Decode only the four render DTO contracts from original HTTP JSON bytes.
/// The HTTP layer must authenticate these same bytes before calling this API.
pub fn decode_render_json(
    kind: &str,
    bytes: &[u8],
) -> std::result::Result<serde_json::Value, lims_core::ServiceError> {
    raw_json::parse(kind, bytes).map_err(|e| service_error(400, "PDF_INVALID_JSON", e))
}
pub fn render_contract_json(bytes: &[u8]) -> std::result::Result<Vec<u8>, lims_core::ServiceError> {
    render_api("contract", &decode_render_json("contract", bytes)?)
}
pub fn render_entrust_order_json(
    bytes: &[u8],
) -> std::result::Result<Vec<u8>, lims_core::ServiceError> {
    render_api("entrust", &decode_render_json("entrust", bytes)?)
}
pub fn render_lighting_report_json(
    bytes: &[u8],
) -> std::result::Result<Vec<u8>, lims_core::ServiceError> {
    render_api("lighting", &decode_render_json("lighting", bytes)?)
}
pub fn render_lm79_report_json(
    bytes: &[u8],
) -> std::result::Result<Vec<u8>, lims_core::ServiceError> {
    render_api("lm79", &decode_render_json("lm79", bytes)?)
}

#[cfg(test)]
mod tests {
    use super::*;
    fn font() -> Arc<FontData> {
        Arc::new(
            FontData::load(Path::new(concat!(
                env!("CARGO_MANIFEST_DIR"),
                "/assets/LimsSongSC-Regular.ttf"
            )))
            .unwrap(),
        )
    }
    #[test]
    fn original_font_metrics_match_pdfbox_probe() {
        let f = Font::new(font());
        assert_eq!(f.data.descent, -286.);
        assert_eq!(f.data.ascent, 1151.);
        for (s, w) in [
            ("测", 1000.),
            ("A", 718.),
            (" ", 256.),
            ("–", 563.),
            ("—", 890.),
            ("±", 1000.),
            ("μ", 626.),
            ("Ω", 770.),
        ] {
            assert_eq!(f.measure(s, 1000.).unwrap(), w);
        }
    }
    #[test]
    fn source_unicode_aliases_have_distinct_cids() {
        let f = Font::new(font());
        assert_eq!(f.data.glyphs[&'–'].0, f.data.glyphs[&'‒'].0);
        assert_ne!(f.hex("–").unwrap(), f.hex("‒").unwrap());
    }
    #[test]
    fn supplementary_unicode_is_encoded_as_utf16_surrogate_pair() {
        let f = Font::new(font());
        let c = *f
            .data
            .glyphs
            .keys()
            .filter(|c| **c as u32 > 0xffff)
            .min()
            .unwrap();
        f.hex(&c.to_string()).unwrap();
        let mut d = Document::with_version("1.7");
        f.embed(&mut d).unwrap();
        let expected = c
            .encode_utf16(&mut [0; 2])
            .iter()
            .map(|x| format!("{x:04X}"))
            .collect::<String>();
        assert!(d.objects.values().any(|o|matches!(o,Object::Stream(s) if String::from_utf8_lossy(&s.content).contains(&expected))));
    }
    #[test]
    fn wrap_matches_java_line_breaks_and_strip() {
        let r = Renderer::new(font(), None);
        assert_eq!(
            r.wrap(" 测试\r\n\n测\u{2028}试 ", 12., 300.).unwrap(),
            vec!["测试", "", "测", "试"]
        );
        assert_eq!(r.wrap("测试测试", 12., 24.).unwrap(), vec!["测试", "测试"]);
        assert_eq!(
            r.wrap("\u{00a0}A\u{00a0}", 12., 300.).unwrap(),
            vec!["\u{00a0}A\u{00a0}"]
        );
    }
    #[test]
    fn java_rounding_half_up() {
        assert_eq!(java_round0(450.5), "451");
        assert_eq!(java_round0(451.5), "452");
        assert_eq!(java_round0(450.4), "450");
    }
    #[test]
    fn malformed_jpeg_fails_without_panicking() {
        assert!(jpeg_components(&[255, 216, 255, 192, 0, 10]).is_err());
    }
    #[test]
    fn excessive_page_cell_is_rejected() {
        let mut r = Renderer::new(font(), None);
        assert!(r.ensure(700.).is_err());
    }
}
