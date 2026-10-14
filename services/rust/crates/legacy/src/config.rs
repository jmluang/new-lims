use crate::{Error, Result};
use std::path::PathBuf;

/// Configuration is captured once and can be supplied explicitly by tests.
/// Credentials are intentionally not Debug or Serialize.
#[derive(Clone)]
pub struct Config {
    pub pfx_path: PathBuf,
    pub pfx_password: Option<String>,
    pub front_size_mm: f32,
    pub front_left_mm: f32,
    pub front_up_mm: f32,
    pub page3_left_mm: f32,
    pub page3_up_mm: f32,
    pub function_left_mm: f32,
    pub function_top_mm: f32,
    pub perforation_height_mm: f32,
    pub signature_reserved_size: usize,
    pub temporary_directory: Option<PathBuf>,
}
impl Default for Config {
    fn default() -> Self {
        Self {
            pfx_path: "/keys/signer.pfx".into(),
            pfx_password: None,
            front_size_mm: 35.,
            front_left_mm: 40.,
            front_up_mm: 10.,
            page3_left_mm: 40.,
            page3_up_mm: 10.,
            function_left_mm: 7.,
            function_top_mm: 40.,
            perforation_height_mm: 35.,
            signature_reserved_size: 512 * 1024,
            temporary_directory: None,
        }
    }
}
impl Config {
    pub fn from_env() -> Result<Self> {
        let get = |key: &str| std::env::var(key).ok().filter(|s| !s.trim().is_empty());
        let float = |key: &str, fallback: f32| {
            get(key)
                .and_then(|v| v.trim().parse().ok())
                .unwrap_or(fallback)
        };
        // Startup policy mirrors SigningPolicy, and never enables a TSA.
        if get("PDF_SIGNING_POLICY_HASH_ALGORITHM")
            .is_some_and(|v| !v.eq_ignore_ascii_case("SHA256"))
        {
            return Err(Error::policy("PDF_SIGNING_POLICY_HASH_FORBIDDEN"));
        }
        if get("PDF_SIGNING_POLICY_TSA_ENABLED").is_some_and(|v| v.eq_ignore_ascii_case("true")) {
            return Err(Error::policy("PDF_SIGNING_POLICY_TSA_FORBIDDEN"));
        }
        let left = float("FRONT_SEAL_OFFSET_LEFT_MM", 40.);
        let up = float("FRONT_SEAL_OFFSET_UP_MM", 10.);
        let pfx_path = get("DEFAULT_PFX_PATH")
            .map(PathBuf::from)
            .unwrap_or_else(|| {
                if std::path::Path::new("/keys/signer.pfx").exists() {
                    "/keys/signer.pfx".into()
                } else if std::path::Path::new("keys/signer.pfx").exists() {
                    "keys/signer.pfx".into()
                } else {
                    "/keys/signer.pfx".into()
                }
            });
        let config = Self {
            pfx_path,
            pfx_password: get("DEFAULT_PFX_PASS"),
            front_size_mm: float("FRONT_SEAL_SIZE_MM", 35.),
            front_left_mm: left,
            front_up_mm: up,
            page3_left_mm: float("FRONT_SEAL_PAGE3_OFFSET_LEFT_MM", left),
            page3_up_mm: float("FRONT_SEAL_PAGE3_OFFSET_UP_MM", up),
            function_left_mm: float("FUNCTION_STAMP_LEFT_MARGIN_MM", 7.),
            function_top_mm: float("FUNCTION_STAMP_TOP_MARGIN_MM", 40.),
            perforation_height_mm: float("PERFORATION_STAMP_HEIGHT_MM", 35.),
            signature_reserved_size: get("SIGNATURE_RESERVED_SIZE")
                .and_then(|v| v.parse::<usize>().ok())
                .unwrap_or(512 * 1024),
            temporary_directory: get("PDF_TEMP_DIR").map(PathBuf::from),
        };
        config.validate()?;
        Ok(config)
    }
    pub fn validate(&self) -> Result<()> {
        if [
            self.front_size_mm,
            self.front_left_mm,
            self.front_up_mm,
            self.page3_left_mm,
            self.page3_up_mm,
            self.function_left_mm,
            self.function_top_mm,
            self.perforation_height_mm,
        ]
        .iter()
        .any(|v| !v.is_finite())
        {
            return Err(Error::input("PDF_INVALID_STAMP_GEOMETRY"));
        }
        if self.front_size_mm <= 0.
            || self.perforation_height_mm <= 0.
            || self.signature_reserved_size == 0
            || self.signature_reserved_size > 64 * 1024 * 1024
        {
            return Err(Error::input("PDF_INVALID_STAMP_CONFIGURATION"));
        }
        Ok(())
    }
}
