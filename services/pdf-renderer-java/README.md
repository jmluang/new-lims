# PDF Signer Java Service

The execution ledger must use a dedicated least-privilege database account.
Start from `deploy/mysql/pdf_execution_ledger_grants.sql.example`; never reuse
the Laravel application account or a database owner account.

This service is an internal signing boundary. Except for `GET /api/pdf/health`,
every endpoint requires the `PDF-HMAC-V1` request contract. Do not expose it to
the public network or call it directly from a browser.

Direct local runs bind to `127.0.0.1` by default. Docker Compose binds Spring
to the container interface while publishing the host port only on `127.0.0.1`;
do not change the host mapping to a public interface.

Endpoints
- POST `/api/pdf/process` (multipart/form-data)
  - parts
    - `pdf` (application/pdf) - required
    - `perforation_image` (image/png|jpeg) - optional
    - `signature_appearance_image` (image/png|jpeg) - optional
    - `mode`: stamp | sign | stamp_and_sign
    - `signature_contact` / `signature_location` / `signature_reason`

Notes
- SHA-256, the PKCS#12 identity and all signing policy are server-controlled.
- Caller-provided `signing_key_id`, `hash_algo`, `tsa_enabled` and `tsa_url`
  are rejected. RFC 3161 remains disabled until a real timestamp token is
  embedded and independently verified.
- `DEFAULT_PFX_PASS` has no fallback and is mandatory.
- Every authenticated request uses the fixed ten-line `PDF-HMAC-V1` canonical
  string. RFC 8785-compatible restricted JCS binds request metadata and a
  canonical part manifest; JSON bodies are represented as the `body` part and
  multipart boundaries are never signed.
- Redis claims `pdf-hmac:{version}:{key-id}:{nonce}` for exactly 300 seconds
  after the header/MAC check and before body parsing. The Compose service uses
  durable AOF storage and the signer fails closed when Redis is unavailable.
- Body receipt is limited to 120 seconds. Multipart part names are endpoint
  allowlisted, unique, complete, and verified centrally before any controller,
  PDF writer, execution claim, or private-key path runs.
- Function-stamp top offset can be configured with env `FUNCTION_STAMP_TOP_MARGIN_MM` (default 25, in millimeters).
- Function-stamp left margin can be configured with env `FUNCTION_STAMP_LEFT_MARGIN_MM` (default ~7, in millimeters).
- First-page seal offsets can be configured with env `FRONT_SEAL_OFFSET_LEFT_MM` (default 40) and `FRONT_SEAL_OFFSET_UP_MM` (default 10).
- Memory control: `PDFBOX_MEMORY_MODE=temp` (default) uses disk-backed buffers; set `PDFBOX_MEMORY_MODE=mixed` with `PDFBOX_MAX_MAIN_MEMORY_MB` to limit heap usage; you can also cap JVM heap via `JAVA_TOOL_OPTIONS=-Xmx512m`.

Build & Run
```bash
# Build jar
mvn -q -e -B -DskipTests package
# Run
java -jar target/pdf-signer-*.jar

# Use Docker Compose (builds image and runs the service)
docker compose up --build
```

Docker / Local Keys
```bash
docker build -t pdf-signer:0.1.0 .
# Docker: bind only to loopback and mount local keys read-only.
docker run --rm -p 127.0.0.1:8080:8081 \
  -v $(pwd)/keys:/keys:ro \
  -e DEFAULT_PFX_PASS='<strong-random-pfx-password>' \
  -e DEFAULT_PFX_PATH=/keys/signer.pfx \
  -e PDF_SERVICE_HMAC_ACTIVE_KEY_ID=primary \
  -e PDF_SERVICE_HMAC_KEYS='primary:<base64-32-byte-secret>' \
  -e PDF_SERVICE_REDIS_HOST=host.docker.internal \
  -e FUNCTION_STAMP_TOP_MARGIN_MM=50 \
  -e FUNCTION_STAMP_LEFT_MARGIN_MM=8 \
  -e FRONT_SEAL_OFFSET_LEFT_MM=42 \
  -e FRONT_SEAL_OFFSET_UP_MM=5 \
  pdf-signer:0.1.0

# Local dev (no Docker): the service auto-detects PFX in ./keys
# Either run `java -jar ...` or through your IDE, and ensure exists:
#   - PFX:  ./keys/signer.pfx  (or set DEFAULT_PFX_PATH)
```
Key Resolution
- PFX only. The service does not use PEM.
- Precedence:
  - If `DEFAULT_PFX_PATH` is set, use it with `DEFAULT_PFX_PASS`.
  - Otherwise auto-detect: `/keys/signer.pfx` (Docker) or `keys/signer.pfx` (local).
  - Fallback default path: `/keys/signer.pfx`.

Local usage examples
```bash
# Auto-detect ./keys/signer.pfx, but never a password.
DEFAULT_PFX_PASS='<strong-random-pfx-password>' \
PDF_SERVICE_HMAC_KEYS='primary:<base64-32-byte-secret>' \
java -jar target/pdf-signer-*.jar

# Customize function-stamp top/left margins (in mm)
FUNCTION_STAMP_TOP_MARGIN_MM=25 FUNCTION_STAMP_LEFT_MARGIN_MM=7 \
  java -jar target/pdf-signer-*.jar

# Or via JVM system property
java -DFUNCTION_STAMP_TOP_MARGIN_MM=25 -DFUNCTION_STAMP_LEFT_MARGIN_MM=7 \
  -jar target/pdf-signer-*.jar

# Customize first-page seal offsets (mm)
FRONT_SEAL_OFFSET_LEFT_MM=40 FRONT_SEAL_OFFSET_UP_MM=10 \
  java -jar target/pdf-signer-*.jar

# Or via JVM system properties
java -DFRONT_SEAL_OFFSET_LEFT_MM=40 -DFRONT_SEAL_OFFSET_UP_MM=10 \
  -jar target/pdf-signer-*.jar

# Explicit path and password
DEFAULT_PFX_PATH=./keys/custom.pfx DEFAULT_PFX_PASS=secret \
  java -jar target/pdf-signer-*.jar

# Docker with memory cap and disk-backed buffers
# (prevents OOM on small hosts, e.g. 2c2g)
docker run --rm -p 127.0.0.1:8080:8081 \
  -v $(pwd)/keys:/keys:ro \
  -e DEFAULT_PFX_PASS='<strong-random-pfx-password>' \
  -e DEFAULT_PFX_PATH=/keys/signer.pfx \
  -e PDF_SERVICE_HMAC_KEYS='primary:<base64-32-byte-secret>' \
  -e PDF_SERVICE_REDIS_HOST=host.docker.internal \
  -e FUNCTION_STAMP_TOP_MARGIN_MM=38 \
  -e FRONT_SEAL_OFFSET_LEFT_MM=45 \
  -e FRONT_SEAL_OFFSET_UP_MM=8 \
  -e PDFBOX_MEMORY_MODE=temp \
  --memory=1g --memory-swap=1g \
  pdf-signer:0.1.0
```

Hot Reload & Debugging
- Install dependencies once with `mvn dependency:go-offline` (optional but speeds up later runs).
- Start the dev server with hot reload: `mvn spring-boot:run`.
- Java changes trigger automatic restarts when your IDE or `mvn compile` updates `target/classes`.
- Static resource edits in `src/main/resources` are picked up without a restart.
- To attach a remote debugger on port 5005, run:
  ```bash
  mvn spring-boot:run \
    -Dspring-boot.run.jvmArguments="-agentlib:jdwp=transport=dt_socket,server=y,suspend=n,address=*:5005"
  ```
- Access the Docker service at `http://127.0.0.1:8080`; a direct mutating
  request without valid HMAC must return `401 PDF_HMAC_REQUIRED`.

## HMAC key rotation

1. Generate a new random 32-byte-or-longer secret and assign a new key id.
2. Add both old and new `key-id:base64-secret` entries to Java.
3. Restart Java, then switch Laravel `PDF_SERVICE_HMAC_ACTIVE_KEY_ID` to the new id.
4. Confirm the old key is no longer used, remove it from both services, and restart Java.

The Java service accepts every configured key during the overlap; Laravel signs
only with the active id. Never reuse a PDF HMAC secret for another subsystem.
