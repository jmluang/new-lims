# Lighting report rendering

`POST /api/pdf/lighting-report` renders the confirmed report layout directly with
PDFBox. The renderer accepts structured values and PNG/JPEG image bytes; it does
not read a DOCX, use LibreOffice, or load a reference PDF at runtime.

The endpoint uses the existing `PDF-HMAC-V1` authentication filter and shared PDF
concurrency limit. It streams the generated PDF from a temporary file and deletes
that file after response writing. Missing sections and invalid report images
return HTTP 422. Rendering failure never returns a partial PDF.

See [Integration handoff](lighting-report-handoff.md) for the integration boundary and reviewed layout decisions.

## Input contract

See `src/test/resources/lighting-report/sample.json` and
`dto/LightingReportPayload.java` for the complete request shape.

- `header`: company, document number, version, address, website and email.
- `cover`: report number, cover receipt date and issue date.
- `form`: application, manufacturer, product, laboratory and test-condition
  display values from FORM, including units and date formatting.
- `testSetup`: measurement geometry and sampling settings.
- `electrical`: the five GOS display values.
- `colorimetry`: the eight HAAS display values.
- `signatures`: names, date and optional Base64 `testedImage`, `reviewedImage`
  and `approvedImage` values.
- `photos`: a list of Base64 PNG/JPEG images, rendered two per page.

The sample deliberately preserves the different receipt dates in the supplied
reference. No sample defaults are inserted into production requests. GOS/HAAS
file parsing and the business selection of those values remain the caller's
responsibility. Image bytes should already have the desired visible appearance;
the renderer preserves them without scan-background adjustments.

General information and signers use consistent left-aligned columns. Parameter
cells center their text horizontally and vertically. Long information or
parameter sections continue onto additional pages, and the contents and footer
page counts follow the generated pages. Empty photo lists omit the photo section.
Values exceeding a single cell/page capacity are rejected instead of clipped.

## Fonts

`LIGHTING_REPORT_FONT_PATH` supplies the regular TrueType font. The optional
`LIGHTING_REPORT_HEADING_FONT_PATH` supplies the Latin heading font. The equivalent
JVM properties are `lighting.report.font` and `lighting.report.heading.font`.

The local comparison uses Arial Unicode MS and Arial Bold, matching the available
fonts used for the reference preview. If no regular font is configured, the
existing bundled LIMS Song font is used; its glyph metrics and appearance differ.
If no heading font is supplied, headings use the regular font with a slightly
heavier stroke. The deployed environment must pin the same fonts to reproduce a
chosen preview. Fonts are embedded in generated PDFs. Local operating-system
fonts are not added to the repository.

The cover information uses 11 pt; the reference-standard value remains 12 pt.
The main cover title is 36 pt and its institution name is 15 pt. General
information and signing text use 12 pt, parameter text uses 9 pt, and the page
label uses 9 pt. These roles are based on the measured reference PDF sizes, rather
than a blanket document-wide body size.

## Generate the Java comparison preview

Run from the repository root:

```sh
LIGHTING_REPORT_FONT_PATH='/System/Library/Fonts/Supplemental/Arial Unicode.ttf' \
LIGHTING_REPORT_HEADING_FONT_PATH='/System/Library/Fonts/Supplemental/Arial Bold.ttf' \
/usr/local/bin/mvn -f services/pdf-renderer-java/pom.xml \
  -Dtest=LightingReportRendererTest \
  -Dlighting.report.preview="$PWD/output/pdf/report-template-java.pdf" test
```

The test assembles the sample JSON with the test-only signature/photo assets and
calls the production renderer. The image fixtures are extracted from the
approved reference PDF so that converter-applied image effects are already part
of the comparison inputs. They are not bundled as production report defaults.

The earlier converter preview is retained locally at
`output/pdf/comparison/report-template-reference.pdf`, with its working DOCX and
Python scripts under `output/pdf/comparison/reference-source/`. Generated previews
and retained comparison artifacts are intentionally ignored by Git.
