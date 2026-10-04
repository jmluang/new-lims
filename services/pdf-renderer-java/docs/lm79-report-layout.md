# Integrated LM79 report layout

Backend commit `a986fe3` calls `POST /api/pdf/lm79-report`. This integration keeps
that entry point and `Lm79ReportPayload`, while using the reviewed lighting report
cover, typography, header/footer and drawing primitives. The standalone
`/api/pdf/lighting-report` contract remains available and is not substituted for
the richer backend payload.

## Presentation metadata

Each `sections` item has `title`, `headers`, `rows` and an optional `layout`:

| Layout | Rendering |
| --- | --- |
| `sample`, `information` | Combined basic information, 12 pt left-aligned labels/values |
| `signatures` | Left-aligned signer names and a blank date line |
| `standards` | Compact sequence column and left-aligned standard descriptions |
| `equipment`, `table` | Native table with repeated column headers; equipment identifies the reference equipment chapter |
| `conditions`, `setup`, `electrical`, `photometric`, `color`, `distribution`, `parameters` | Paired four-column parameter tables with 9 pt centered text |
| `uncertainty` | Separate page group for input components and computed results |
| `spectrum` | Measurement metadata above the actual spectrum graph |

Missing `layout` defaults to `table` for older callers. Presentation classification
is emitted by `Lm79Payload`, rather than inferred from translated section titles.
Form validation hints such as `[optional]` or bracketed bounds are omitted from
printed labels; measurement units and values remain. Long CRI values span the
remaining width of their row. Value-column context, including the uncertainty
unit header, is retained when it carries measurement meaning.

The main sequence is cover, contents, basic information/signers, standards and
equipment, conditions/settings, photos, electrical/photometric/color results,
uncertainty, spectrum, luminous intensity distribution results and
original PDF appendices. Actual pages determine contents and footer totals.
Short parameter sections stay together when they fit a page; large sections have
repeated continuation headings. All source rows and scalar values are preserved.
Raw spectral input is represented by the graph, and candela input remains a
calculation source for the supplied photometric results. Raw measurement matrices
are not expanded into hundreds of main-report pages.

## Reference headings and removed declarations

Body headings follow the actual customer DOCX: `测试设置`, `测试条件`, `电参数`,
`色度测试`. Per the latest customer review, the image page has no title; image
positions and table borders remain unchanged. The information table starts directly with its first
row; there is no added `基础资料` heading or continuation heading. Backend group
names are internal classifications, not permission to print new chapter titles.
Additional standards, equipment, photometry, uncertainty and spectrum values
remain in their tables/graph without invented body headings. Existing generic
`table`/`parameters` callers retain their explicitly supplied titles.

The English contents uses the reference chapter names and numbering. Settings
maps to the reference's 2.7 rather than an invented 2.2 `Test Settings` entry.
Uncertainty and spectrum values are retained within the report results without
new 4.4/4.5 contents entries. Actual pages determine every listed page number.

Photometric rows are partitioned for presentation: total flux, efficacy and LOR
stay in 4.2; the existing intensity, beam angle, zonal flux and shielding angle
values go in chapter 5. Field values and calculations are unchanged.

The customer explicitly requested deletion of the declaration page. Its seven
texts originated in backend commit `a986fe3`; they were not present in the DOCX.
The payload no longer emits them and the renderer no longer supports declaration
paragraphs. The `Announcement` contents entry is also removed. No replacement
wording, disclaimer page or empty declaration page is inserted.

## Document identity and dates

`documentInfo` optionally supplies `fileNumber`, `version`, `website` and `email`.
Laravel forwards `pdf_service.report_layout`, configured by `PDF_REPORT_FILE_NUMBER`,
`PDF_REPORT_VERSION`, `PDF_REPORT_WEBSITE` and `PDF_REPORT_EMAIL`. Contact fields
are blank by default. Header defaults use the reference form identity
`FO-22-03-2402` / `V1.0`; laboratory name/address come from the report.

The cover reference standard comes from the actual standards section. Long
standard summaries refer to that section, whose full rows remain in the body.
The renderer never inserts a fixed testing standard into unrelated reports.

The backend leaves signer names and the actual signing date blank. Inspector,
reviewer and issuer identities belong to the later signing workflow. `issuedDate`
is printed as the report issue date in the cover
and basic information, without being presented as an actual signing timestamp.

Original appendices are physically merged after the report body. Their dimensions,
rotation, resources and visible content remain unchanged. The body totals and
contents include appended pages, but the report header/footer is not overlaid on
original instrument evidence. Existing unsigned/unencrypted requirements remain.

## Validation and sample reproduction

The initial minimal regression failed with a 30 pt title versus the reviewed
36 pt title. It now passes and also checks the 9 pt page label. The PHP payload test
checks the current form projection, scalar coverage, section layouts, standards
and spectral transport without persisting a report. The Java fixture is captured
from that PHP builder and verifies every supplied row label/value, native images,
actual standards, continuation pages and imported appendix resources.

Run from the repository root, with the same configured fonts as the reviewed preview:

```sh
APP_ENV=testing APP_CONFIG_CACHE=/private/tmp/lm79-testing-config-unused.php \
DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' \
LM79_PAYLOAD_FIXTURE="$PWD/output/pdf/lm79-backend-payload.json" \
/usr/local/bin/php backend/vendor/bin/phpunit -c backend/phpunit.xml --filter=Lm79PayloadTest

LIGHTING_REPORT_FONT_PATH='/System/Library/Fonts/Supplemental/Arial Unicode.ttf' \
LIGHTING_REPORT_HEADING_FONT_PATH='/System/Library/Fonts/Supplemental/Arial Bold.ttf' \
/usr/local/bin/mvn -f services/pdf-renderer-java/pom.xml \
  -Dtest=Lm79ReportRendererTest,LightingReportRendererTest \
  -Dlm79.integrated.preview="$PWD/output/pdf/lm79-report-template-integrated.pdf" test
```

`src/test/resources/lm79-report/backend-payload.json` is the committed contract
fixture. The user-facing preview adds the reference photos for layout inspection
and omits the internal appendix evidence marker used by the unit test. It is a
layout specimen, not evidence of a live report generation or deployment.
