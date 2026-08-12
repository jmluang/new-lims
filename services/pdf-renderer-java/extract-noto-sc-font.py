from fontTools.merge import Merger


# Droid Sans Fallback provides the required Chinese glyphs as TrueType outlines,
# while DejaVu Sans supplies the Latin letters and numerals used by form codes.
# Merge them into one TrueType font because the renderer must measure and draw a
# mixed Chinese/Latin string with one embedded PDF font.
font = Merger().merge([
    "/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
])
font.save("/opt/fonts/LimsCjk.ttf")
