from fontTools.ttLib import TTCollection


collection = TTCollection("/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc")
# The collection's third face is Noto Sans CJK SC Regular. Save it as a normal
# TrueType font because PDFBox embeds .ttf/.otf files but not TTC collections.
collection.fonts[2].save("/opt/fonts/NotoSansCJKsc-Regular.ttf")
