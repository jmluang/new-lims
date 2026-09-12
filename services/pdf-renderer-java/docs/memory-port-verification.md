# PDF memory port verification

## Scope and provenance

- Source: `zs-lims`, commit `7f5679070c1cf64203d4163ac6e9b4e793ffa89a`, `perf(pdf): bound memory usage in PDF processing`.
- Target baseline: `new-lims`, commit `58ab7aef5143f586c5fd9b3cc8a0d54814448e41`.
- Target service: `services/pdf-renderer-java`.
- Local verification: 2026-09-12, macOS, Java 23.0.2, Maven compilation with Java 17 release target, PDFBox 3.0.5.

The source changes were ported onto the target implementation. The target's PKCS#12 provider, HMAC protocol, policy enforcement, multi-sample pagination, font, renderers, handwritten signature workflow, timestamp attributes and execution storage were retained. No PDFBox upgrade or signature-order change was made.

## Resource ownership

`PdfFiles` owns each operation's input and PDF revisions. Every save allocates a distinct output. Replaced revisions are deleted only after the corresponding `PDDocument` closes; the remaining files are removed when the result closes. PDFBox receives a real `StreamCacheCreateFunction`, defaulting to a temporary-file cache. Incremental saves and external signatures use 64 KiB buffered output.

`SignerService.processToFile()` reads and closes the uploaded stream once. Its signed-input guard, cover extraction and processing share the saved input. HMAC multipart digest verification retains its separate streaming authentication read. The compatibility `process()` methods still return byte arrays when explicitly called; the legacy HTTP controller uses the file result directly.

The legacy JSON response still contains `success`, `pdf_base64` and the same six `cover_fields`. Jackson encodes the file incrementally. The PHP client still receives and decodes the complete JSON response. Contract and entrust-order endpoints render to files and retain their PDF content type and attachment names. Operation cleanup encloses synchronous response writing, including client disconnects.

Static image caches were removed. Same-length images and perforation slices no longer share entries across operations.

## Concurrency and security ordering

One Spring `PdfWorkLimiter` is shared by the filter, execution signing and deadline recovery. Default capacity is one. Admission uses `tryAcquire()` without a waiting queue.

| Path | Admission boundary | Busy behavior |
| --- | --- | --- |
| Legacy `process`, `extract-cover`, `contract`, `entrust-order` | After HMAC authentication filter, before multipart parsing; held through response writing | HTTP 503, `PDF_BUSY`, `Retry-After: 1` |
| Handwritten `inspect`, `prepare`, `finalize-unsigned`, `verify` | Same filter and permit | Same HTTP 503 response |
| `sign-existing-field` | After authenticated multipart verification, repository claim and execution transition; before loading PDF/appearance bytes | Existing ledger failure flow records `failed_before_private_key` / `PDF_BUSY`; no private key call |
| Execution status / result recovery | Only when a post-key expired execution actually requires recovery and verification | HTTP 503 while busy; no reclassification, deletion or promotion |
| Health, ordinary status, existing completed results, retirement-evidence inspection | No PDF processing permit | Existing behavior |

HMAC has explicit filter order before PDF admission. Its non-multipart body authentication still buffers at most 24 MiB per request before admission; the Tomcat thread cap bounds simultaneous request handling. This port does not make JSON authentication streaming. Its receipt timestamp, clock-skew check, nonce reservation and 120-second body receive deadline are unchanged. A busy authenticated request consumes its nonce; a later transport attempt needs fresh HMAC headers. There is no limiter wait that can consume the receive window, registration deadline or execution lease.

Signing bypasses the filter deliberately: PHP treats a signing POST transport failure as uncertain and polls the ledger. Recording a known pre-key capacity failure gives that existing flow explicit evidence. The frozen policy still decides whether `PDF_BUSY` can retry, its backoff and maximum attempts. This port does not modify existing policy rows or implicitly authorize retries. A policy without `PDF_BUSY` in its retryable error-code set treats capacity refusal as a terminal pre-key failure.

Duplicate completed executions return their existing record without reading upload bytes or acquiring a permit. Deadline recovery uses the same permit as live PDF processing; ordinary polling remains available during a long PDF request.

## New signature byte-array assessment

The handwritten workflow still has byte-array interfaces in `IncrementalSigningService`, `PdfSignatureVerifier`, revision permission validation and execution persistence/recovery. These include prefix comparisons, CMS verification input and historical revision copies. Converting that entire evidence/permission pipeline to file ranges is separate from the source port and was not done here.

This port applies disk stream caches to those PDFBox loads, closes the external CMS input, and shares admission across signing, verification and recovery. Upload bytes are materialized only after admission. Existing 20 MiB input, 24 MiB multipart request and frozen output/increment budgets remain in place. `PadesCmsSigner`, RFC3161 attributes, trust evaluation, private-key markers, result persistence and retirement rules retain their implementation.

A dedicated child JVM with a 512 MiB heap successfully prepared a two-page PDF with 19 MiB of padding, applied certification and approval signatures, and verified both signatures and timestamps. This validates that fixture within the existing input boundary; it is not a proof of constant-memory processing for every PDF.

## Runtime configuration

- Compose retains the 768 MiB container limit, matching swap limit (no swap), 70% maximum heap ratio and 32 KiB signature reservation.
- Initial heap becomes 64 MiB; `PDF_SIGNER_JAVA_OPTS` continues to override the complete options string. The standalone image retains its 75% maximum heap ratio.
- `PDF_MAX_CONCURRENT_JOBS` defaults to 1.
- `PDF_HTTP_MAX_THREADS` defaults to 16; `PDF_HTTP_MIN_SPARE_THREADS` defaults to 2.
- Multipart storage explicitly uses a zero-byte memory threshold.
- `PDFBOX_MEMORY_MODE` defaults to disk; `mixed` enables the bounded cache configured by `PDFBOX_MAX_MAIN_MEMORY_MB` (default 64).

## Baseline and regression evidence

A clean target baseline run executed 77 tests: 72 passed, 5 skipped, no failures/errors. Stale reports from prior local work were excluded by rerunning `mvn clean test`.

Before production edits, four new regression tests failed on the target baseline:

| Regression | Before | After |
| --- | --- | --- |
| 64 MiB PDF cover extraction in a 128 MiB child heap | OOM in `PdfCoverExtractor.toByteArray()` | Pass |
| Upload stream lifetime | Two opens, zero closes | One open, one close |
| Legacy CMS signing | Calls forbidden `readAllBytes()` | Streams input; cryptographic verification passes |
| Equal-length red/blue stamp sequence | Blue stamp reuses red pixels | Each operation keeps its own pixels |

The complete target suite after the port executed 97 tests: 92 passed, 5 existing skips, zero failures/errors. It covers HMAC, replay and receive deadlines, policy guards, signed-input rejection, handwritten certification/approval, tamper detection, RFC3161, execution fencing/recovery, retirement, and rendering. Added tests cover shared capacity, pre-key busy evidence without upload reads, duplicate completion, upload failure release, and cleanup on legacy/contract/entrust response disconnection.

The target baseline snapshot covers **46 scenarios / 195 pages**: the source matrix adapted to target code, plus target multi-sample counts 1, 3, 12 and 30. Text, 72-DPI pixel hashes, widget positions/flags and cryptographic signature results all match the baseline. The target font and multi-page entrust layout are preserved.

Snapshot: `src/test/resources/pdf-behavior-baseline/snapshot.json`.
SHA-256: `2493ee0e83a00bff4e7fd26f75e3b87731bbda6980d3a14d230365e7d0063fe0`.

The optimized perforation path on 1- and 10-page documents already produced unreadable earlier signatures when combined with front seals. The baseline snapshot records those `IOException` signature results, and the port matches them. This historical signature-order issue was not changed.

The PHP HMAC/renderer client unit suite also passed: 8 tests, 54 assertions, using mocked HTTP and an in-memory SQLite test configuration. The final 11-test memory/HTTP/appearance rerun, `mvn -DskipTests package`, YAML syntax validation and `git diff --check` passed. Docker was unavailable for Compose/runtime validation.

## Reproduction

From the service directory:

```sh
mvn clean test
mvn -Dtest=PdfBehaviorCompatibilityTest \
  -Dpdf.reference.directory="$PWD/src/test/resources/pdf-behavior-baseline" test
mvn -DskipTests package
```

Pixel comparison is an explicit same-runtime check: the checked-in snapshot was rendered on macOS Java 23.0.2 using the target's bundled font. To compare another rendering runtime, first generate its own snapshot from the target baseline using the adapted compatibility harness, then pass that directory through `pdf.reference.directory`.

## Evidence limits

These are local target results. The source repository's RSS/latency figures are not presented as target measurements. No new comparative RSS or throughput benchmark, Linux 2 GiB host test, Docker container runtime test, deployment, external TSA call or Acrobat desktop acceptance was performed. Timestamp tests use the local test authority. The source's queue-and-eventually-complete concurrent behavior was deliberately replaced by explicit capacity refusal compatible with the target's security and ledger deadlines.
