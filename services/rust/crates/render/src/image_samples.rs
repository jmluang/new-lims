//! ImageIO / PDFBox lossless-image sample semantics, separate from the shared
//! decoder's RGB API used by signing. No report page is rasterized here.
use anyhow::Result;
use image::{DynamicImage, ImageDecoder};
use lopdf::{Document, ObjectId, Stream, dictionary};
use std::io::Cursor;

struct Samples {
    width: u32,
    height: u32,
    components: i64,
    bits: i64,
    colors: Vec<u8>,
    alpha: Option<Vec<u8>>,
    profile: Option<Vec<u8>>,
}

pub(crate) fn embed_lossless(doc: &mut Document, bytes: &[u8]) -> Result<(u32, u32, ObjectId)> {
    let s = decode_samples(bytes)?;
    let mut dict = dictionary! {
        "Type"=>"XObject", "Subtype"=>"Image", "Width"=>s.width as i64,
        "Height"=>s.height as i64, "BitsPerComponent"=>s.bits,
        "ColorSpace"=>if s.components == 1 { "DeviceGray" } else { "DeviceRGB" }
    };
    if let Some(space) = s
        .profile
        .and_then(|profile| super::embed_icc(doc, profile, s.components))
    {
        dict.set("ColorSpace", space);
    }
    if let Some(alpha) = s.alpha {
        let mask = doc.add_object(Stream::new(
            dictionary! {
                "Type"=>"XObject", "Subtype"=>"Image", "Width"=>s.width as i64,
                "Height"=>s.height as i64, "ColorSpace"=>"DeviceGray", "BitsPerComponent"=>s.bits
            },
            alpha,
        ));
        dict.set("SMask", mask);
    }
    Ok((
        s.width,
        s.height,
        doc.add_object(Stream::new(dict, s.colors)),
    ))
}

fn decode_samples(bytes: &[u8]) -> Result<Samples> {
    let mut decoder = image::ImageReader::new(Cursor::new(bytes))
        .with_guessed_format()?
        .into_decoder()?;
    let color_type = decoder.color_type();
    let profile = decoder.icc_profile()?;
    let im = lims_images::decode(bytes)?;
    let mut s = Samples {
        width: im.width(),
        height: im.height(),
        components: 3,
        bits: 8,
        colors: Vec::new(),
        alpha: None,
        profile,
    };
    match im {
        // TYPE_USHORT_GRAY is not eligible for PDFBox's PredictorEncoder.
        // Its fallback uses BufferedImage.getRGB: linear 16-bit gray -> sRGB8.
        DynamicImage::ImageLuma16(im) => {
            s.profile = None;
            for pixel in im.pixels() {
                let value = gray16_to_srgb8(pixel.0[0]);
                s.colors.extend_from_slice(&[value; 3]);
            }
        }
        // Custom gray+alpha component images keep their raw linear samples.
        // DeviceGray alone would interpret these as already display-encoded.
        DynamicImage::ImageLumaA8(im) => {
            s.components = 1;
            s.profile = Some(s.profile.unwrap_or_else(linear_gray_profile));
            let mut alpha = Vec::with_capacity(im.len() / 2);
            for pixel in im.pixels() {
                s.colors.push(pixel.0[0]);
                alpha.push(pixel.0[1]);
            }
            s.alpha = Some(alpha);
        }
        DynamicImage::ImageLumaA16(im) => {
            s.components = 1;
            s.bits = 16;
            s.profile = Some(s.profile.unwrap_or_else(linear_gray_profile));
            let mut alpha = Vec::with_capacity(im.len());
            for pixel in im.pixels() {
                s.colors.extend_from_slice(&pixel.0[0].to_be_bytes());
                alpha.extend_from_slice(&pixel.0[1].to_be_bytes());
            }
            s.alpha = Some(alpha);
        }
        DynamicImage::ImageRgb16(im) => {
            s.bits = 16;
            for value in im.as_raw() {
                s.colors.extend_from_slice(&value.to_be_bytes());
            }
        }
        DynamicImage::ImageRgba16(im) => {
            s.bits = 16;
            let mut alpha = Vec::with_capacity(im.len() / 2);
            for pixel in im.pixels() {
                for value in &pixel.0[..3] {
                    s.colors.extend_from_slice(&value.to_be_bytes());
                }
                alpha.extend_from_slice(&pixel.0[3].to_be_bytes());
            }
            s.alpha = Some(alpha);
        }
        im => {
            // The shared decoder already performs ImageIO's L8 getRGB transfer.
            // LosslessFactory's gray fast path then writes DeviceGray, with no
            // source ICC: rebinding the input profile would transform twice.
            let gray = color_type == image::ColorType::L8;
            if gray {
                s.components = 1;
                s.profile = None;
            }
            let rgba = im.to_rgba8();
            let mut alpha = Vec::with_capacity((s.width as usize) * (s.height as usize));
            let mut transparent = false;
            for pixel in rgba.pixels() {
                if gray {
                    s.colors.push(pixel.0[0]);
                } else {
                    s.colors.extend_from_slice(&pixel.0[..3]);
                }
                alpha.push(pixel.0[3]);
                transparent |= pixel.0[3] != 255;
            }
            if transparent {
                s.alpha = Some(alpha);
            }
        }
    }
    Ok(s)
}

fn gray16_to_srgb8(value: u16) -> u8 {
    // Preserve JDK ColorModel's float32 operations around Math.pow(float64).
    let linear = f32::from(value) / 65535.0;
    let srgb = if linear <= 0.0031308 {
        linear * 12.92
    } else {
        1.055 * f64::from(linear).powf(1.0 / 2.4) as f32 - 0.055
    };
    (srgb * 255.0).round() as u8
}

fn linear_gray_profile() -> Vec<u8> {
    // Authored ICC v2.3 display profile: GRAY -> XYZ, D50, gamma 1.0.
    // These standard numeric colorimetry fields reproduce Java CS_GRAY without
    // copying its vendor profile metadata or adding a Java/runtime dependency.
    let mut profile = vec![0u8; 156];
    profile[8..12].copy_from_slice(&[2, 0x30, 0, 0]);
    profile[12..24].copy_from_slice(b"mntrGRAYXYZ ");
    profile[24..36].copy_from_slice(&[7, 208, 0, 1, 0, 1, 0, 0, 0, 0, 0, 0]);
    profile[36..40].copy_from_slice(b"acsp");
    let d50 = [
        0x00, 0x00, 0xf6, 0xd6, 0x00, 0x01, 0x00, 0x00, 0x00, 0x00, 0xd3, 0x2d,
    ];
    profile[68..80].copy_from_slice(&d50);
    profile[128..132].copy_from_slice(&2u32.to_be_bytes());
    profile[132..136].copy_from_slice(b"wtpt");
    profile[136..140].copy_from_slice(&156u32.to_be_bytes());
    profile[140..144].copy_from_slice(&20u32.to_be_bytes());
    profile[144..148].copy_from_slice(b"kTRC");
    profile[148..152].copy_from_slice(&176u32.to_be_bytes());
    profile[152..156].copy_from_slice(&14u32.to_be_bytes());
    profile.extend_from_slice(b"XYZ \0\0\0\0");
    profile.extend_from_slice(&d50);
    profile.extend_from_slice(b"curv\0\0\0\0\0\0\0\x01\x01\0\0\0");
    let length = profile.len() as u32;
    profile[..4].copy_from_slice(&length.to_be_bytes());
    profile
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn gray16_matches_all_65536_java_getrgb_values() {
        let expected = include_bytes!("../tests/fixtures/image-formats/java-gray16-rgb8.bin");
        for value in 0..=u16::MAX {
            assert_eq!(
                gray16_to_srgb8(value),
                expected[value as usize],
                "sample {value}"
            );
        }
    }
    #[test]
    fn lossless_depth_color_and_alpha_follow_java_branches() {
        for (bytes, bits, components, profile, alpha) in [
            (
                &include_bytes!("../tests/fixtures/image-formats/png-gray16.png")[..],
                8,
                3,
                false,
                false,
            ),
            (
                &include_bytes!("../tests/fixtures/image-formats/png-gray-alpha8.png")[..],
                8,
                1,
                true,
                true,
            ),
            (
                &include_bytes!("../tests/fixtures/image-formats/png-gray-alpha16.png")[..],
                16,
                1,
                true,
                true,
            ),
            (
                &include_bytes!("../tests/fixtures/image-formats/png-rgb16.png")[..],
                16,
                3,
                false,
                false,
            ),
            (
                &include_bytes!("../tests/fixtures/image-formats/png-rgba16.png")[..],
                16,
                3,
                false,
                true,
            ),
            (
                &include_bytes!("../tests/fixtures/image-formats/png-gray-icc.png")[..],
                8,
                1,
                false,
                false,
            ),
            (
                &include_bytes!("../tests/fixtures/image-formats/png-adobergb.png")[..],
                8,
                3,
                true,
                false,
            ),
        ] {
            let s = decode_samples(bytes).unwrap();
            assert_eq!(
                (s.bits, s.components, s.profile.is_some(), s.alpha.is_some()),
                (bits, components, profile, alpha)
            );
            assert_eq!(
                s.colors.len(),
                (s.width * s.height) as usize * components as usize * bits as usize / 8
            );
            if let Some(a) = s.alpha {
                assert_eq!(a.len(), (s.width * s.height) as usize * bits as usize / 8);
            }
        }
    }
    #[test]
    fn invalid_image_rejects() {
        assert!(decode_samples(b"not an image").is_err());
    }
}
