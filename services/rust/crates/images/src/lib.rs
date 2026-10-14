//! Shared ImageIO-compatible image decoding. JPEG uses native IJG integer-accurate
//! IDCT and fancy chroma upsampling, not an approximate pure-Rust IDCT.
use image::{DynamicImage, ImageFormat, RgbImage};
#[derive(Debug)]
pub enum DecodeError {
    Unsupported,
    Invalid(String),
}
impl std::fmt::Display for DecodeError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Unsupported => f.write_str("Unsupported image format"),
            Self::Invalid(message) => write!(f, "Invalid image: {message}"),
        }
    }
}
impl std::error::Error for DecodeError {}
fn invalid(error: impl std::fmt::Display) -> DecodeError {
    DecodeError::Invalid(error.to_string())
}
/// Decodes the first image/frame. EXIF orientation is not applied, matching ImageIO.read.
/// Recognized broken images fail; unknown formats return Unsupported so callers can
/// preserve their existing Java skip-versus-error behavior.
pub fn decode(bytes: &[u8]) -> Result<DynamicImage, DecodeError> {
    if bytes.starts_with(&[0, 0]) {
        return decode_wbmp(bytes).map(DynamicImage::ImageRgba8);
    }
    let format = image::guess_format(bytes).ok();
    if format == Some(ImageFormat::Jpeg) {
        return decode_jpeg(bytes);
    }
    match image::load_from_memory(bytes) {
        Ok(DynamicImage::ImageLuma8(gray))
            if matches!(format, Some(ImageFormat::Png | ImageFormat::Tiff)) =>
        {
            // ImageIO's standard 8-bit CS_GRAY samples are linear. Converting them
            // to RGB must apply its sRGB transfer, as for grayscale JPEG above.
            let rgb = RgbImage::from_fn(gray.width(), gray.height(), |x, y| {
                let value = LINEAR_GRAY_TO_SRGB[gray.get_pixel(x, y).0[0] as usize];
                image::Rgb([value, value, value])
            });
            Ok(DynamicImage::ImageRgb8(rgb))
        }
        Ok(image) => Ok(image),
        Err(image::ImageError::Unsupported(_)) => Err(DecodeError::Unsupported),
        Err(error) => Err(invalid(error)),
    }
}
pub fn decode_jpeg(bytes: &[u8]) -> Result<DynamicImage, DecodeError> {
    // Native IJG reports some unsupported/invalid JPEG operations by unwinding.
    // Keep these failures inside the image API instead of unwinding a service worker.
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| decode_jpeg_inner(bytes)))
        .unwrap_or_else(|_| Err(invalid("Native JPEG decoder rejected the image")))
}
fn decode_jpeg_inner(bytes: &[u8]) -> Result<DynamicImage, DecodeError> {
    let mut decoder = mozjpeg::Decompress::builder()
        .from_mem(bytes)
        .map_err(invalid)?;
    decoder.dct_method(mozjpeg::DctMethod::IntegerSlow);
    decoder.do_fancy_upsampling(true);
    let gray = decoder.color_space() == mozjpeg::ColorSpace::JCS_GRAYSCALE;
    let cmyk = matches!(
        decoder.color_space(),
        mozjpeg::ColorSpace::JCS_CMYK | mozjpeg::ColorSpace::JCS_YCCK
    );
    let (width, height) = (decoder.width(), decoder.height());
    let count = width
        .checked_mul(height)
        .and_then(|v| v.checked_mul(4))
        .ok_or_else(|| invalid("JPEG dimensions overflow"))?;
    if count > 512 * 1024 * 1024 {
        return Err(invalid("JPEG decoded image exceeds 512 MiB"));
    }
    let mut decoder = decoder
        .to_colorspace(if cmyk {
            mozjpeg::ColorSpace::JCS_CMYK
        } else {
            mozjpeg::ColorSpace::JCS_RGB
        })
        .map_err(invalid)?;
    let mut pixels: Vec<u8> = decoder.read_scanlines().map_err(invalid)?;
    decoder.finish().map_err(invalid)?;
    if cmyk {
        if pixels.len() != width * height * 4 {
            return Err(invalid("Invalid CMYK JPEG pixel length"));
        }
        let mut rgb = Vec::with_capacity(width * height * 3);
        for pixel in pixels.chunks_exact(4) {
            // JDK JPEGImageReader inverts CMYK/YCCK regardless of APP14.
            let normalized = |byte: u8| f32::from(255 - byte) / 255.0;
            let black = 1.0 - normalized(pixel[3]);
            for &component in &pixel[..3] {
                let linear = black * (1.0 - normalized(component));
                // Match JDK SimpleCMYKColorSpace's float32 arithmetic followed by
                // its double-precision sRGB transfer and float32 output rounding.
                let value = if linear < 0.0031308 {
                    12.92 * linear
                } else {
                    (1.055 * f64::from(linear).powf(1.0 / 2.4) - 0.055) as f32
                };
                rgb.push((value * 255.0 + 0.5) as u8);
            }
        }
        pixels = rgb;
    }
    if gray {
        for byte in &mut pixels {
            *byte = LINEAR_GRAY_TO_SRGB[*byte as usize];
        }
    }
    RgbImage::from_raw(width as u32, height as u32, pixels)
        .map(DynamicImage::ImageRgb8)
        .ok_or_else(|| invalid("JPEG output dimensions do not match pixel data"))
}

// Java's standard CS_GRAY BufferedImage.getRGB conversion uses the sRGB transfer
// function on linear gray samples. This table was checked against all 256 JDK values.
const LINEAR_GRAY_TO_SRGB: [u8; 256] = [
    0, 13, 22, 28, 34, 38, 42, 46, 50, 53, 56, 59, 61, 64, 66, 69, 71, 73, 75, 77, 79, 81, 83, 85,
    86, 88, 90, 92, 93, 95, 96, 98, 99, 101, 102, 104, 105, 106, 108, 109, 110, 112, 113, 114, 115,
    117, 118, 119, 120, 121, 122, 124, 125, 126, 127, 128, 129, 130, 131, 132, 133, 134, 135, 136,
    137, 138, 139, 140, 141, 142, 143, 144, 145, 146, 147, 148, 148, 149, 150, 151, 152, 153, 154,
    155, 155, 156, 157, 158, 159, 159, 160, 161, 162, 163, 163, 164, 165, 166, 167, 167, 168, 169,
    170, 170, 171, 172, 173, 173, 174, 175, 175, 176, 177, 178, 178, 179, 180, 180, 181, 182, 182,
    183, 184, 185, 185, 186, 187, 187, 188, 189, 189, 190, 190, 191, 192, 192, 193, 194, 194, 195,
    196, 196, 197, 197, 198, 199, 199, 200, 200, 201, 202, 202, 203, 203, 204, 205, 205, 206, 206,
    207, 208, 208, 209, 209, 210, 210, 211, 212, 212, 213, 213, 214, 214, 215, 215, 216, 216, 217,
    218, 218, 219, 219, 220, 220, 221, 221, 222, 222, 223, 223, 224, 224, 225, 226, 226, 227, 227,
    228, 228, 229, 229, 230, 230, 231, 231, 232, 232, 233, 233, 234, 234, 235, 235, 236, 236, 237,
    237, 238, 238, 238, 239, 239, 240, 240, 241, 241, 242, 242, 243, 243, 244, 244, 245, 245, 246,
    246, 246, 247, 247, 248, 248, 249, 249, 250, 250, 251, 251, 251, 252, 252, 253, 253, 254, 254,
    255, 255,
];

fn decode_wbmp(bytes: &[u8]) -> Result<image::RgbaImage, DecodeError> {
    fn uintvar(bytes: &[u8], offset: &mut usize) -> Result<u32, DecodeError> {
        let mut value = 0u32;
        for _ in 0..5 {
            let byte = *bytes
                .get(*offset)
                .ok_or_else(|| invalid("The appearance is not a supported image"))?;
            *offset += 1;
            value = value
                .checked_mul(128)
                .and_then(|value| value.checked_add(u32::from(byte & 127)))
                .ok_or_else(|| invalid("The appearance dimensions are too large"))?;
            if byte & 128 == 0 {
                return Ok(value);
            }
        }
        Err(invalid("The appearance dimensions are too large"))
    }
    let mut offset = 2;
    let width = uintvar(bytes, &mut offset)?;
    let height = uintvar(bytes, &mut offset)?;
    if width == 0 || height == 0 {
        return Err(invalid("The appearance is not a supported image"));
    }
    image::Limits::default()
        .reserve_buffer(width, height, image::ColorType::Rgba8)
        .map_err(|_| invalid("The appearance exceeds image memory limits"))?;
    let stride = (width as usize).div_ceil(8);
    let length = stride
        .checked_mul(height as usize)
        .ok_or_else(|| invalid("The appearance dimensions are too large"))?;
    let data = bytes
        .get(
            offset
                ..offset
                    .checked_add(length)
                    .ok_or_else(|| invalid("The appearance dimensions are too large"))?,
        )
        .ok_or_else(|| invalid("The appearance is not a supported image"))?;
    Ok(image::RgbaImage::from_fn(width, height, |x, y| {
        let value = if data[y as usize * stride + x as usize / 8] & (0x80 >> (x % 8)) != 0 {
            255
        } else {
            0
        };
        image::Rgba([value, value, value, 255])
    }))
}
