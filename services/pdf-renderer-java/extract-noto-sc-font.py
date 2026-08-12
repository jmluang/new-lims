from fontTools.merge import Merger
from fontTools.ttLib import TTFont


# Droid Sans Fallback provides the required Chinese glyphs as TrueType outlines,
# while DejaVu Sans supplies the Latin letters and numerals used by form codes.
# Merge them into one TrueType font because the renderer must measure and draw a
# mixed Chinese/Latin string with one embedded PDF font.
sources = []
for index, source in enumerate([
    "/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
]):
    font = TTFont(source)
    # The optional OpenType MATH table is irrelevant to a form font and is not
    # mergeable between these two families with the packaged FontTools version.
    if "MATH" in font:
        del font["MATH"]
    cleaned = f"/tmp/lims-font-{index}.ttf"
    font.save(cleaned)
    sources.append(cleaned)

font = Merger().merge(sources)
font.save("/opt/fonts/LimsCjk.ttf")
