use crate::{pdf::invalid, Result};
use image::RgbaImage;

/// Keep modern signing's required-image failure policy while sharing the
/// ImageIO-compatible PNG/JPEG/BMP/GIF/TIFF/WBMP pixel decoder with legacy.
pub(crate) fn decode(bytes: &[u8]) -> Result<RgbaImage> {
    lims_images::decode(bytes)
        .map(|image| image.to_rgba8())
        .map_err(|_| invalid("The appearance is not a supported image"))
}
