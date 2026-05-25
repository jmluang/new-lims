# PDF Signer Java Service (Skeleton)

Endpoints
- POST `/api/pdf/process` (multipart/form-data)
  - parts
    - `pdf` (application/pdf) - required
    - `perforation_image` (image/png|jpeg) - optional
    - `signature_appearance_image` (image/png|jpeg) - optional
    - `mode`: stamp | sign | stamp_and_sign
    - `signing_key_id`: managed by server-side mapping (ID-based)
    - `signature_contact` / `signature_location` / `signature_reason`
    - `hash_algo`: SHA256 (default)
    - `tsa_enabled`: boolean
    - `tsa_url`: string

Notes
- This is a skeleton for integration & load tests.
- Implement incremental visible signature and TSA in `SignerService`.
- Replace PKCS#12 loading with your key management based on `signing_key_id`.
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
# Docker: mount your local keys into /keys (read-only)
docker run --rm -p 8080:8080 \
  -v $(pwd)/keys:/keys:ro \
  -e DEFAULT_PFX_PASS=changeit \
  -e DEFAULT_PFX_PATH=/keys/signer.pfx \
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
# Default (auto-detect ./keys/signer.pfx)
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
docker run --rm -p 8080:8080 \
  -v $(pwd)/keys:/keys:ro \
  -e DEFAULT_PFX_PASS=changeit \
  -e DEFAULT_PFX_PATH=/keys/signer.pfx \
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
- Access the service at `http://localhost:8080` after the server starts.
