# Native lighting report integration handoff

The renderer in this change reproduces the five-part report reviewed in this
conversation: cover, contents, general information and signatures, parameter
tables, and sample photos. The user has approved this version for a local commit;
customer confirmation and deployment are separate steps.

## Entry points

- HTTP: `POST /api/pdf/lighting-report`, JSON request, PDF response.
- Renderer: `com.luang.pdfsigner.service.LightingReportRenderer`.
- DTO: `com.luang.pdfsigner.dto.LightingReportPayload`.
- Request example: `src/test/resources/lighting-report/sample.json`.
- Detailed rendering and font instructions: `docs/lighting-report.md`.

The HTTP entry point uses the existing `PDF-HMAC-V1` filter, bounded JSON body
handling and the shared `PdfWorkLimiter`. Use the existing authenticated Laravel
PDF client transport. A future `renderLightingReport(array $payload)` helper can
forward to `renderPdfBytes('api/pdf/lighting-report', $payload)`; that helper and
the business mapping are not part of this change.

A separate `/api/pdf/lm79-report` endpoint and `Lm79ReportPayload` are being worked
on elsewhere in this checkout. They are different contracts. Do not send an LM79
payload directly to this endpoint. Choose the intended report layout explicitly
and map its data to `LightingReportPayload` if using this renderer.

## Backend integration update

The backend now calls `/api/pdf/lm79-report` and its richer report DTO. That path
shares the reviewed visual primitives and fonts, while keeping its own section,
spectrum and appendix contract. See [Integrated LM79 layout](lm79-report-layout.md)
for the current metadata, pagination and field-coverage rules. Do not remap the
full report into the older five-part DTO and lose its additional fields.

## Data mapping

All values are supplied by the caller. The Java renderer does not parse uploaded
GOS/HAAS files, query business records or insert sample values into empty fields.
Payload section/property names use camelCase.

| Section | Source and responsibility |
| --- | --- |
| `header` | Company name, document number/version and footer contact data |
| `cover` | Report number, cover receipt date and issue date |
| `form` | FORM application/manufacturer/product/laboratory/test-condition display values, including units |
| `testSetup` | C/gamma intervals and ranges, distance, accuracy and point counts |
| `electrical` | GOS input voltage, input current, input power, power factor and frequency |
| `colorimetry` | HAAS CCT, Duv, Ra, R9, xy, uv, TM-30 Rf and Rg |
| `signatures` | Signer names/date and optional `testedImage`, `reviewedImage`, `approvedImage` |
| `photos` | Zero or more sample images, two per output page |

Image fields are Base64 PNG/JPEG bytes, not paths or URLs. Supply the desired final
visible image; scan backgrounds and image effects are not modified by the renderer.
Missing required sections or invalid images return 422. Sample signature and photo
assets live only in test resources.

The supplied example has different receipt dates on the cover and information
page. It preserves the reference example; it does not establish a business rule.
The integrating session must choose the real receipt-date source explicitly.
The parameter presentation adds spacing before W/K/V and omits the `AC ` prefix
from the parameter-page rated voltage, matching the reviewed reference display.

## Confirmed layout decisions

- Third page labels and values are left-aligned on consistent column starts.
  Tested/reviewed/approved names and the signing date are also left-aligned.
- Parameter tables use the same width, consistent section spacing, and horizontal
  and vertical cell centering. No source-annotation text is printed.
- Cover information: 11 pt; reference-standard value: 12 pt; title: 36 pt;
  institution: 15 pt. General information/signing: 12 pt. Parameters: 9 pt.
- The complete `第 X 页 共 X 页` label uses one regular font and 9 pt size.
- Long sections continue onto additional pages. Contents and total page counts
  use actual generated pages. Empty photo lists omit the photo section.
- Rendering is native PDFBox text/vector/image drawing. There is no runtime
  DOCX, LibreOffice, Python or reference-PDF dependency.

## Fonts and deployment

The approved local preview uses:

```sh
LIGHTING_REPORT_FONT_PATH='/System/Library/Fonts/Supplemental/Arial Unicode.ttf'
LIGHTING_REPORT_HEADING_FONT_PATH='/System/Library/Fonts/Supplemental/Arial Bold.ttf'
```

Deploy equivalent configured font files and pin them to keep the appearance.
Without regular-font configuration, the renderer uses the existing bundled LIMS
Song font, which has different appearance and metrics. Operating-system fonts are
not committed. All fonts used by generated reports are embedded in the PDF.

## Verification and reproduction

The cover-size regression first failed with expected 11 pt versus actual 12 pt,
then passed after correction. All 9 renderer tests pass. Previous checks also
covered concurrency admission, HMAC behavior and existing HTTP/rendering contracts.
The Maven package build passes. The final preview has 5 A4 pages and was inspected
visually; the cover-size correction left pages 2 through 5 pixel-identical.

Run from the repository root:

```sh
LIGHTING_REPORT_FONT_PATH='/System/Library/Fonts/Supplemental/Arial Unicode.ttf' \
LIGHTING_REPORT_HEADING_FONT_PATH='/System/Library/Fonts/Supplemental/Arial Bold.ttf' \
/usr/local/bin/mvn -f services/pdf-renderer-java/pom.xml \
  -Dtest=LightingReportRendererTest,PdfConcurrencyFilterTest \
  -Dlighting.report.preview="$PWD/output/pdf/report-template-java.pdf" test
```

Local artifacts, intentionally ignored by Git:

- `output/pdf/report-template-java.pdf`: native Java preview after the size correction.
- `output/pdf/comparison/report-template-reference.pdf`: retained converter preview.
- `output/pdf/comparison/reference-source/`: retained DOCX and Python provenance.

The retained reference PDF SHA-256 is
`32bb25c109da825de62981984315e1b1728fc95042a6253c5a1889b4299dc1f2`.
Regenerate the Java preview from committed test fixtures in another checkout.
No service has been deployed or restarted by this change.
