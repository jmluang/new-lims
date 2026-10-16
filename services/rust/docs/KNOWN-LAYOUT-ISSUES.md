# Shared Java/Rust long laboratory-name header

This is a pre-existing layout behavior shared by the Java and Rust renderer;
it is not a demonstrated Rust migration regression. The layout baseline remains
unchanged by the deployment fixes.

Both header functions center the full laboratory name at A4 width/2 in 18pt,
with top 43.6pt, while file/version metadata starts at x 435pt. Neither limits the
name width or wraps it. Both use the same LimsSong font bytes and advance-width
centering. Relevant functions are Java `LightingReportRenderer.drawHeaderFooter`
and Rust render `Canvas::footer`.

At page width 595.275635pt, name width greater than 274.724365pt reaches the metadata.
The normal test name `TEST-RUST-20261015-R01 TEST ONLY LAB` measures 369.666pt;
its right edge is 482.4708pt, overlapping metadata by 47.4708pt. The existing final
8-page test report shows the same overlap on every page. Identical-font geometry
probes cover short Latin/Chinese, below/above threshold, this exact long name, and
15/16 full-width Chinese characters. This is source/geometry evidence; no new Java
runtime render or full build was run for this investigation.

A separate product candidate could keep normal short names unchanged and shrink
only long header names to fit with an 8pt metadata gap. The corresponding centered
name width is 258.724365pt, making the example approximately 12.60pt. A minimum font
size alone cannot handle arbitrarily long names: specify an input limit or design
a two-line header with enough vertical space. Do not silently clip or truncate a
laboratory name. Apply an approved layout rule to **both** renderers and compare
short/boundary/long names across cover/body/TOC, page boxes, text boxes and raster
output. Font size and pixels change, so this requires a separately identified
layout candidate and its own regression evidence.

Generate a new report/revision for an approved product change. Do not rewrite an
already signed or published PDF to repair its header.
