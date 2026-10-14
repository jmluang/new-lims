use crate::{Config, Error, Result};
use lopdf::{Document, Object, ObjectId};
pub fn mm(value: f32) -> f32 {
    (value as f64 * 2.83465) as f32
}
pub(crate) fn array(doc: &Document, obj: &Object) -> Result<Vec<Object>> {
    Ok(doc
        .dereference(obj)
        .map_err(Error::pdf)?
        .1
        .as_array()
        .map_err(Error::pdf)?
        .clone())
}
pub(crate) fn media_box(doc: &Document, mut id: ObjectId) -> Result<[f32; 4]> {
    let mut seen = std::collections::BTreeSet::new();
    while seen.insert(id) {
        let dict = doc.get_dictionary(id).map_err(Error::pdf)?;
        if let Ok(obj) = dict.get(b"MediaBox") {
            let a = array(doc, obj)?;
            if a.len() != 4 {
                return Err(Error::input("PDF_INVALID_MEDIA_BOX"));
            }
            let b = [
                a[0].as_float().map_err(Error::pdf)?,
                a[1].as_float().map_err(Error::pdf)?,
                a[2].as_float().map_err(Error::pdf)?,
                a[3].as_float().map_err(Error::pdf)?,
            ];
            if b.iter().any(|v| !v.is_finite()) || b[2] <= b[0] || b[3] <= b[1] {
                return Err(Error::input("PDF_INVALID_MEDIA_BOX"));
            }
            return Ok(b);
        }
        id = dict
            .get(b"Parent")
            .and_then(Object::as_reference)
            .map_err(Error::pdf)?;
    }
    Err(Error::input("PDF_INVALID_PAGE_TREE"))
}
pub fn front_rect(width: f32, height: f32, page: usize, cfg: &Config) -> [f32; 4] {
    let size = mm(cfg.front_size_mm);
    let margin = mm(10.);
    let (left, up) = if page == 2 {
        (cfg.page3_left_mm, cfg.page3_up_mm)
    } else {
        (cfg.front_left_mm, cfg.front_up_mm)
    };
    // Deliberately match Java min/max rather than clamp (small pages may be smaller than the seal).
    let x = (width - size - 2. * margin - mm(left))
        .min(width - size - margin)
        .max(margin);
    let y = (height * 0.17 + mm(up))
        .min(height - size - margin)
        .max(margin);
    [x, y, size, size]
}
/// Java Math.round on positive f32 intermediates, not integer division.
pub fn slice_range(
    width: u32,
    index: usize,
    count: usize,
    minimum_one: bool,
) -> Result<(u32, u32)> {
    if count == 0 || index >= count {
        return Err(Error::input("PDF_INVALID_SLICE_RANGE"));
    }
    let start = (width as f32 * index as f32 / count as f32 + 0.5).floor() as u32;
    let end = (width as f32 * (index + 1) as f32 / count as f32 + 0.5).floor() as u32;
    let size = if minimum_one {
        (end - start).max(1)
    } else {
        end - start
    };
    if start.checked_add(size).is_none_or(|end| end > width) {
        return Err(Error::input("PDF_STAMP_SLICE_OUT_OF_BOUNDS"));
    }
    Ok((start, size))
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn java_default_front_position() {
        let r = front_rect(612., 792., 0, &Config::default());
        assert!((r[0] - 342.70825).abs() < 0.0001);
        assert!((r[1] - 162.9865).abs() < 0.0001);
    }
    #[test]
    fn very_small_page_uses_java_min_max() {
        assert_eq!(front_rect(10., 10., 0, &Config::default())[0], mm(10.));
    }
    #[test]
    fn java_rounding_and_narrow_image_boundaries() {
        assert_eq!(slice_range(7, 0, 3, true).unwrap(), (0, 2));
        assert_eq!(slice_range(7, 1, 3, true).unwrap(), (2, 3));
        assert_eq!(slice_range(7, 2, 3, true).unwrap(), (5, 2));
        assert!(slice_range(1, 2, 3, true).is_err());
        assert_eq!(slice_range(1, 0, 10, false).unwrap(), (0, 0));
    }
}
