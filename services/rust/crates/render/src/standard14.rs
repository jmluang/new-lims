//! Unmodified Adobe core-font AFM data distributed with PDFBox 3.0.5.
use std::{collections::HashMap, sync::LazyLock};
pub(crate) struct Metrics {
    pub cap: f32,
    pub ascii: [f32; 128],
}
static METRICS: LazyLock<HashMap<&'static str, Metrics>> = LazyLock::new(|| {
    let mut fonts = HashMap::new();
    for (name, data) in [
        (
            "Courier-Bold",
            include_str!("../assets/standard14/Courier-Bold.afm"),
        ),
        (
            "Courier-BoldOblique",
            include_str!("../assets/standard14/Courier-BoldOblique.afm"),
        ),
        (
            "Courier-Oblique",
            include_str!("../assets/standard14/Courier-Oblique.afm"),
        ),
        ("Courier", include_str!("../assets/standard14/Courier.afm")),
        (
            "Helvetica-Bold",
            include_str!("../assets/standard14/Helvetica-Bold.afm"),
        ),
        (
            "Helvetica-BoldOblique",
            include_str!("../assets/standard14/Helvetica-BoldOblique.afm"),
        ),
        (
            "Helvetica-Oblique",
            include_str!("../assets/standard14/Helvetica-Oblique.afm"),
        ),
        (
            "Helvetica",
            include_str!("../assets/standard14/Helvetica.afm"),
        ),
        ("Symbol", include_str!("../assets/standard14/Symbol.afm")),
        (
            "Times-Bold",
            include_str!("../assets/standard14/Times-Bold.afm"),
        ),
        (
            "Times-BoldItalic",
            include_str!("../assets/standard14/Times-BoldItalic.afm"),
        ),
        (
            "Times-Italic",
            include_str!("../assets/standard14/Times-Italic.afm"),
        ),
        (
            "Times-Roman",
            include_str!("../assets/standard14/Times-Roman.afm"),
        ),
        (
            "ZapfDingbats",
            include_str!("../assets/standard14/ZapfDingbats.afm"),
        ),
    ] {
        let mut m = Metrics {
            cap: 0.,
            ascii: [500.; 128],
        };
        for line in data.lines() {
            if let Some(cap) = line.strip_prefix("CapHeight ") {
                m.cap = cap.trim().parse().unwrap_or(0.);
            }
            if line.starts_with("C ") {
                let parts: Vec<_> = line.split(';').collect();
                let code = parts[0]
                    .trim_start_matches("C ")
                    .trim()
                    .parse::<usize>()
                    .unwrap_or(128);
                if code < 128
                    && let Some(width) = parts.iter().find_map(|s| s.trim().strip_prefix("WX "))
                {
                    m.ascii[code] = width.parse().unwrap_or(500.);
                }
            }
        }
        fonts.insert(name, m);
    }
    fonts
});
pub(crate) fn metrics(font: &str) -> Option<&'static Metrics> {
    METRICS.get(font)
}
