# Rust PDF service

This service implements the existing Laravel PDF renderer and signing API. The application selects it through `PDF_SERVICE_BASE_URL`; HMAC and the durable MySQL execution ledger remain enabled.

The F2 Busy fix consumes an unread multipart upload within the existing 24 MiB bound and 120 second upload idle timeout before returning HTTP 503 with `Retry-After: 1`. It does not admit or parse rejected PDF work. Three added regressions cover the bounded body drain and a complete TCP response.

The accompanying application changes refresh resumed report metadata, show published revisions as read-only, contain preview canvases, and release PDF render tasks and workers. They are integrated on main commit `9409c2607abe2d1abc05256e3c1922e734ce2128`, preserving its signing recovery and source-selection fixes.

## Build and deployment

Build with `docker build -f deploy/Dockerfile .` from this directory. Rust 1.90 and Cargo.lock are pinned. No private keys, passwords, user reports or compiled binaries are tracked.

`deploy/compose.yaml` remains an isolated test template. Real deployment requires 2 CPUs, 2 GiB memory, zero container swap, capacity 3, the existing signing result storage and MySQL schema, aligned Laravel HMAC keys, Redis nonce storage, authorized PFX material, and the configured external RFC 3161 TSA trust policy. Consult [deployment wiring](docs/DEPLOYMENT-WIRING-SUPPLEMENT.txt) and [F2 validation results](docs/F2-RESULTS.md).

The validation source aggregate for the 91 F2 source files is `ec71288388d0d6db6f8af4910c76012472c9427933fdcebdb059955a3286623e`. Additional upstream test and documentation files are tracked separately. A tested Linux binary must be identified by its SHA-256 when packaged; the previous benchmark used a read-only binary overlay, not a deployment image.

Current Java health checks do not prove that the TSA configuration or browser signing account is ready. Verify those dependencies before signing a real report and retain the original service as rollback.

For a previously verified binary, `deploy/package-verified-binary.py` packages the exact hash into an existing Linux amd64 runtime without compilation. It explicitly sets mode0755 before Docker cp, then checks the resulting image with `test -x`, stat, sha256sum and ldd. `deploy/Dockerfile.verified-binary` provides the equivalent BuildKit recipe with COPY --chmod=0755. Perform caller resource admission first; retain the old service and verify real startup on a separate loopback port before switching. A hash match alone does not prove executable file permissions.

Deployment prerequisites, the opt-in test certificate helper, and credential-free static checks are documented in [test identity and readiness](docs/TEST-IDENTITY-AND-READINESS.md). The shared Java/Rust long-name header issue is tracked separately in [known layout issues](docs/KNOWN-LAYOUT-ISSUES.md); it is not part of the F2 migration fix.
