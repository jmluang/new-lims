use crate::{config::Config, hmac, nonce::NonceStore};
use axum::{
    body::{to_bytes, Body},
    extract::{DefaultBodyLimit, FromRequest, Multipart},
    http::{header, Request, Response, StatusCode},
    routing::any,
    Router,
};
use bytes::Bytes;
use futures_util::StreamExt;
use http_body::{Frame, SizeHint};
use lims_core::{FilePart, MultipartData, ServiceError, ServiceResult};
use serde_json::{json, Value};
use std::{
    collections::BTreeMap,
    pin::Pin,
    sync::{
        atomic::{AtomicBool, Ordering},
        Arc,
    },
    task::{Context, Poll},
    time::{Duration, SystemTime, UNIX_EPOCH},
};
use tokio::sync::{OwnedSemaphorePermit, Semaphore};

pub struct State {
    pub config: Config,
    pub nonce: NonceStore,
    pub heavy: Arc<Semaphore>,
    pub http_requests: Arc<Semaphore>,
    pub execution: lims_execution::ExecutionService,
}
impl State {
    pub fn new(config: Config) -> ServiceResult<Self> {
        let nonce = NonceStore::new(&config)?;
        let heavy = Arc::new(Semaphore::new(config.max_concurrent_jobs));
        let http_requests = Arc::new(Semaphore::new(config.max_http_requests));
        let execution = lims_execution::ExecutionService::from_env(
            Arc::new(crate::signer_adapter::EnvironmentSigner),
            heavy.clone(),
        );
        Ok(Self {
            config,
            nonce,
            heavy,
            http_requests,
            execution,
        })
    }
}
pub fn router(state: Arc<State>) -> Router {
    Router::new()
        .fallback(any(move |request| dispatch(state.clone(), request)))
        .layer(DefaultBodyLimit::max(hmac::BODY_LIMIT))
}
fn expected_method(path: &str) -> Option<&'static str> {
    if path == "/api/pdf/health" || path == "/internal/pdf/diagnostics/dependencies" {
        return Some("GET");
    }
    if heavy_path(path) || path == "/internal/pdf/signatures/sign-existing-field" {
        return Some("POST");
    }
    if let Some(suffix) = path.strip_prefix("/internal/pdf/signatures/executions/") {
        let (id, action) = suffix.split_once('/').unwrap_or((suffix, ""));
        if !id.is_empty() {
            return match action {
                "" | "result" => Some("GET"),
                "retirement-evidence/inspect" => Some("POST"),
                _ => None,
            };
        }
    }
    None
}
fn heavy_path(path: &str) -> bool {
    matches!(
        path,
        "/api/pdf/process"
            | "/api/pdf/extract-cover"
            | "/api/pdf/contract"
            | "/api/pdf/entrust-order"
            | "/api/pdf/lighting-report"
            | "/api/pdf/lm79-report"
            | "/internal/pdf/signatures/inspect"
            | "/internal/pdf/signatures/prepare"
            | "/internal/pdf/signatures/finalize-unsigned"
            | "/internal/pdf/signatures/verify"
    )
}
#[derive(Clone)]
struct HttpRequestPermit(Arc<OwnedSemaphorePermit>);
async fn dispatch(state: Arc<State>, mut req: Request<Body>) -> Response<Body> {
    let permit = match state.http_requests.clone().acquire_owned().await {
        Ok(permit) => Arc::new(permit),
        Err(_) => return error_response(ServiceError::unavailable("PDF_SHUTTING_DOWN")),
    };
    req.extensions_mut()
        .insert(HttpRequestPermit(permit.clone()));
    let path = req.uri().path().to_owned();
    let multipart = hmac::header(req.headers(), "content-type")
        .is_some_and(|v| v.to_ascii_lowercase().starts_with("multipart/form-data"));
    let hmac_enabled = state.config.hmac_enabled;
    let response = match handle(state, req).await {
        Ok(r) => r,
        Err(e) => route_error(&path, multipart, hmac_enabled, e),
    };
    hold_guard(response, permit)
}
async fn handle(state: Arc<State>, req: Request<Body>) -> ServiceResult<Response<Body>> {
    let receipt = tokio::time::Instant::now();
    let http_request_guard = req
        .extensions()
        .get::<HttpRequestPermit>()
        .map(|guard| guard.0.clone());
    let path = req.uri().path().to_owned();
    let method = req.method().clone();
    if method == "GET" && path == "/api/pdf/health" {
        return health(&state).await;
    }
    let headers = req.headers().clone();
    let header_size = headers
        .iter()
        .map(|(k, v)| k.as_str().len() + v.as_bytes().len() + 4)
        .sum::<usize>();
    if header_size > 16 * 1024 {
        return Err(ServiceError::new(431, "PDF_REQUEST_HEADERS_TOO_LARGE"));
    }
    let auth = if state.config.hmac_enabled {
        let now = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map_err(|_| ServiceError::internal("PDF_CLOCK_INVALID"))?
            .as_secs();
        let a = hmac::verify_headers(&state.config, &method, req.uri(), &headers, now)?;
        state.nonce.reserve(&a).await?;
        Some(a)
    } else {
        None
    };
    let content_type = hmac::header(&headers, "content-type")
        .unwrap_or("")
        .to_owned();
    let multipart = content_type
        .to_ascii_lowercase()
        .starts_with("multipart/form-data");
    let mut permit = None;
    // Match Java: multipart admission occurs after header authentication and nonce
    // reservation, before multipart parsing. JSON body HMAC verification is first.
    if multipart && method == "POST" && heavy_path(&path) {
        permit = Some(match admit(&state) {
            Ok(permit) => permit,
            Err(busy) => {
                // Tomcat swallows an unparsed upload after a filter rejects it.
                // Read and discard with the same 24MiB bound so closing an
                // unread HTTP/1 body cannot hide PDF_BUSY behind a broken pipe.
                discard_busy_upload(req.into_body()).await;
                return Err(busy);
            }
        });
    }
    // Tomcat's upload timeout is an idle timeout, not a total-time cancellation.
    // The Java HMAC layer checks total receipt duration only after all bytes arrive.
    let (request_parts, original_body) = req.into_parts();
    let (body, idle_expired) = upload_idle_timeout(original_body);
    let req = Request::from_parts(request_parts, body);
    let received: ServiceResult<(Option<MultipartData>, Bytes)> = async {
        if multipart {
            Ok((Some(read_multipart(req).await?), Bytes::new()))
        } else {
            let bytes = to_bytes(req.into_body(), hmac::BODY_LIMIT)
                .await
                .map_err(|_| ServiceError::new(413, "PDF_REQUEST_TOO_LARGE"))?;
            Ok((None, bytes))
        }
    }
    .await;
    if idle_expired.load(Ordering::Relaxed) {
        return Err(if multipart {
            ServiceError::new(408, "PDF_HMAC_BODY_RECEIVE_TIMEOUT")
        } else {
            ServiceError::new(400, "PDF_UPLOAD_IDLE_TIMEOUT")
        });
    }
    let (parts, body) = received?;
    if auth.is_some() && receipt.elapsed() > Duration::from_secs(hmac::BODY_DEADLINE_SECONDS) {
        return Err(ServiceError::new(408, "PDF_HMAC_BODY_RECEIVE_TIMEOUT"));
    }
    if let Some(auth) = &auth {
        let manifest = if let Some(parts) = &parts {
            hmac::multipart_manifest(parts, &path)?
        } else {
            hmac::body_manifest(&body, &content_type)?
        };
        hmac::verify_manifest(&manifest, &auth.manifest)?;
    }
    // Spring MVC supplies HEAD for GET mappings and an implicit OPTIONS
    // handler. They still pass through HMAC using their actual request method;
    // only the exact GET health request bypasses that filter.
    if method == "OPTIONS" {
        if let Some(allowed) = expected_method(&path) {
            let allow = if allowed == "GET" {
                "GET,HEAD,OPTIONS"
            } else {
                "POST,OPTIONS"
            };
            return Response::builder()
                .status(200)
                .header(header::ALLOW, allow)
                .header("accept-patch", "")
                .header(header::CONTENT_LENGTH, "0")
                .body(Body::empty())
                .map_err(|_| ServiceError::internal("PDF_RESPONSE_FAILED"));
        }
    }
    let method = if method == "HEAD" && expected_method(&path) == Some("GET") {
        axum::http::Method::GET
    } else {
        method
    };
    if expected_method(&path).is_some_and(|allowed| method.as_str() != allowed) {
        return Err(ServiceError::new(405, "PDF_METHOD_NOT_ALLOWED"));
    }
    if method == "GET" && path == "/api/pdf/health" {
        return health(&state).await;
    }
    if !multipart && method == "POST" && heavy_path(&path) {
        permit = Some(admit(&state)?);
    }
    if method == "GET" && path == "/internal/pdf/diagnostics/dependencies" {
        return json_response(
            200,
            json!({"nonce_store_ready":state.nonce.readiness().await}),
        );
    }
    // The body and all HMAC metadata are verified before any business operation.
    let header_map = headers
        .iter()
        .filter_map(|(k, v)| {
            v.to_str()
                .ok()
                .map(|s| (k.as_str().to_string(), s.to_string()))
        })
        .collect::<BTreeMap<_, _>>();
    if path.starts_with("/internal/pdf/signatures/executions/")
        || path == "/internal/pdf/signatures/sign-existing-field"
    {
        if method == "POST"
            && path.ends_with("/retirement-evidence/inspect")
            && !content_type
                .split(';')
                .next()
                .unwrap_or("")
                .trim()
                .eq_ignore_ascii_case("application/json")
        {
            return Err(ServiceError::new(415, "PDF_UNSUPPORTED_MEDIA_TYPE"));
        }
        // The durable operation may outlive its transport. Keep the active
        // request slot until that work completes, even if the client disconnects.
        return tokio::spawn(async move {
            let _http_request_guard = http_request_guard;
            execution_dispatch(&state, &method, &path, parts, body, &header_map).await
        })
        .await
        .map_err(|_| ServiceError::internal("PDF_WORKER_FAILED"))?;
    }
    let (result, permit) = tokio::task::spawn_blocking(move || {
        // A disconnected/timed-out caller cannot release admission while a CPU job
        // continues: the actual blocking job owns the permit.
        let _http_request_guard = http_request_guard;
        let result = business(
            &method,
            &path,
            &content_type,
            parts,
            body,
            headers.get(header::RANGE),
        );
        (result, permit)
    })
    .await
    .map_err(|_| ServiceError::internal("PDF_WORKER_FAILED"))?;
    let response = result?;
    Ok(hold_permit(response, permit))
}
fn upload_idle_timeout(body: Body) -> (Body, Arc<AtomicBool>) {
    let expired = Arc::new(AtomicBool::new(false));
    let state = (body.into_data_stream(), expired.clone());
    let stream = futures_util::stream::try_unfold(state, |(mut input, expired)| async move {
        let next = tokio::time::timeout(
            Duration::from_secs(hmac::BODY_DEADLINE_SECONDS),
            input.next(),
        )
        .await;
        match next {
            Ok(Some(bytes)) => Ok::<_, std::io::Error>(Some((
                bytes.map_err(std::io::Error::other)?,
                (input, expired),
            ))),
            Ok(None) => Ok(None),
            Err(_) => {
                expired.store(true, Ordering::Relaxed);
                Err(std::io::Error::new(
                    std::io::ErrorKind::TimedOut,
                    "PDF upload idle timeout",
                ))
            }
        }
    });
    (Body::from_stream(stream), expired)
}
async fn discard_busy_upload(body: Body) {
    let (body, _) = upload_idle_timeout(body);
    let mut stream = body.into_data_stream();
    let mut received = 0usize;
    while let Some(Ok(chunk)) = stream.next().await {
        received = received.saturating_add(chunk.len());
        if received > hmac::BODY_LIMIT {
            break;
        }
    }
}
fn admit(state: &State) -> ServiceResult<OwnedSemaphorePermit> {
    state
        .heavy
        .clone()
        .try_acquire_owned()
        .map_err(|_| ServiceError::unavailable("PDF_BUSY"))
}
async fn read_multipart(req: Request<Body>) -> ServiceResult<MultipartData> {
    let mut multipart = Multipart::from_request(req, &())
        .await
        .map_err(|_| ServiceError::bad_request("PDF_MULTIPART_REQUIRED"))?;
    let mut data = MultipartData::default();
    while let Some(field) = multipart.next_field().await.map_err(multipart_error)? {
        let name = field
            .name()
            .ok_or_else(|| ServiceError::new(401, "PDF_HMAC_BODY_MISMATCH"))?
            .to_string();
        if data.fields.contains_key(&name) || data.files.contains_key(&name) {
            return Err(ServiceError::new(401, "PDF_HMAC_BODY_MISMATCH"));
        }
        let filename = field.file_name().map(str::to_owned);
        let content_type = field
            .content_type()
            .unwrap_or("application/octet-stream")
            .to_string();
        let bytes = field.bytes().await.map_err(multipart_error)?;
        if filename.is_some() {
            if bytes.len() > hmac::FILE_LIMIT {
                return Err(ServiceError::new(413, "PDF_REQUEST_TOO_LARGE"));
            }
            data.files.insert(
                name.clone(),
                FilePart {
                    name,
                    filename,
                    content_type,
                    bytes: bytes.to_vec(),
                },
            );
        } else {
            let value = String::from_utf8(bytes.to_vec())
                .map_err(|_| ServiceError::new(401, "PDF_HMAC_BODY_MISMATCH"))?;
            data.fields.insert(name, value);
        }
    }
    Ok(data)
}
fn multipart_error(error: axum::extract::multipart::MultipartError) -> ServiceError {
    if error.status() == StatusCode::PAYLOAD_TOO_LARGE {
        ServiceError::new(413, "PDF_REQUEST_TOO_LARGE")
    } else {
        ServiceError::bad_request("PDF_MULTIPART_REQUIRED")
    }
}
fn business(
    method: &axum::http::Method,
    path: &str,
    content_type: &str,
    parts: Option<MultipartData>,
    body: Bytes,
    range: Option<&axum::http::HeaderValue>,
) -> ServiceResult<Response<Body>> {
    if method != "POST" {
        return Err(ServiceError::new(
            if heavy_path(path) { 405 } else { 404 },
            "PDF_ROUTE_NOT_FOUND",
        ));
    }
    let is_json = content_type
        .split(';')
        .next()
        .unwrap_or("")
        .trim()
        .eq_ignore_ascii_case("application/json");
    match path {
        "/api/pdf/contract"
        | "/api/pdf/entrust-order"
        | "/api/pdf/lighting-report"
        | "/api/pdf/lm79-report" => {
            if !is_json {
                return Err(ServiceError::new(415, "PDF_UNSUPPORTED_MEDIA_TYPE"));
            }
            // Spring @RequestBody(required=true) treats literal JSON null as
            // a missing body before invoking any renderer library method.
            if body.trim_ascii() == b"null" {
                return Err(ServiceError::bad_request("PDF_INVALID_JSON"));
            }
            let (output, filename, inline) = match path {
                "/api/pdf/contract" => (
                    lims_render::render_contract_json(&body)?,
                    "contract.pdf",
                    false,
                ),
                "/api/pdf/entrust-order" => (
                    lims_render::render_entrust_order_json(&body)?,
                    "entrust-order.pdf",
                    false,
                ),
                "/api/pdf/lighting-report" => (
                    lims_render::render_lighting_report_json(&body)?,
                    "lighting-report.pdf",
                    false,
                ),
                _ => (
                    lims_render::render_lm79_report_json(&body)?,
                    "lm79-report.pdf",
                    true,
                ),
            };
            pdf_response(output, filename, inline, false)
        }
        "/api/pdf/process" => {
            let data = required_parts(parts)?;
            // Missing controller arguments are 400; uncaught legacy processing
            // exceptions are 500 in the existing Spring service.
            data.file("pdf")?;
            data.field("mode")?;
            let result = lims_legacy::process_to_file(&data)?;
            let file = std::fs::File::open(&result.pdf_file)
                .map_err(|_| ServiceError::internal("PDF_OUTPUT_READ_FAILED"))?;
            crate::streaming::legacy_json(file, result.cover_fields.clone(), result)
        }
        "/api/pdf/extract-cover" => {
            let data = required_parts(parts)?;
            let cover = lims_render::extract_cover(data.file("pdf")?)?;
            json_response(
                200,
                json!({"success":true,"message":"Cover extracted","data":cover}),
            )
        }
        "/internal/pdf/signatures/inspect" => {
            let data = required_parts(parts)?;
            let v = lims_signatures::inspect(data.file("pdf")?).map_err(signature_exception)?;
            serialized_response(200, v)
        }
        "/internal/pdf/signatures/prepare" => {
            let data = required_parts(parts)?;
            let plan: Vec<lims_signatures::FieldPlan> =
                serde_json::from_str(data.field("field_plan")?)
                    .map_err(|_| ServiceError::internal("PDF_INVALID_JSON"))?;
            let pdf = lims_signatures::prepare_fields(data.file("pdf")?, &plan)
                .map_err(signature_exception)?;
            crate::pdf_range::response(pdf, range)
        }
        "/internal/pdf/signatures/finalize-unsigned" => {
            let data = required_parts(parts)?;
            let pdf =
                lims_signatures::finalize_unsigned(data.file("pdf")?).map_err(|e| match &e {
                    lims_signatures::SignatureError::InvalidInput(s)
                        if s == "PDF_SOURCE_NOT_UNSIGNED" =>
                    {
                        ServiceError::unprocessable("PDF_SOURCE_NOT_UNSIGNED")
                    }
                    _ => signature_exception(e),
                })?;
            crate::pdf_range::response(pdf, range)
        }
        "/internal/pdf/signatures/verify" => verify_dispatch(required_parts(parts)?),
        _ => Err(ServiceError::new(404, "PDF_ROUTE_NOT_FOUND")),
    }
}
fn required_parts(parts: Option<MultipartData>) -> ServiceResult<MultipartData> {
    parts.ok_or_else(|| ServiceError::new(415, "PDF_UNSUPPORTED_MEDIA_TYPE"))
}
pub fn serialized_response(
    status: u16,
    value: impl serde::Serialize,
) -> ServiceResult<Response<Body>> {
    json_response(
        status,
        serde_json::to_value(value)
            .map_err(|_| ServiceError::internal("PDF_RESPONSE_SERIALIZATION_FAILED"))?,
    )
}
pub fn json_response(status: u16, value: Value) -> ServiceResult<Response<Body>> {
    let bytes = serde_json::to_vec(&value)
        .map_err(|_| ServiceError::internal("PDF_RESPONSE_SERIALIZATION_FAILED"))?;
    Response::builder()
        .status(status)
        .header(header::CONTENT_TYPE, "application/json")
        .header(header::CONTENT_LENGTH, bytes.len())
        .body(Body::from(bytes))
        .map_err(|_| ServiceError::internal("PDF_RESPONSE_FAILED"))
}
pub fn pdf_response(
    bytes: Vec<u8>,
    filename: &str,
    inline: bool,
    hash_header: bool,
) -> ServiceResult<Response<Body>> {
    let mut r = Response::builder()
        .status(200)
        .header(header::CONTENT_TYPE, "application/pdf")
        .header(
            header::CONTENT_DISPOSITION,
            format!(
                "{}; filename={filename}",
                if inline { "inline" } else { "attachment" }
            ),
        )
        .header(header::CONTENT_LENGTH, bytes.len());
    if hash_header {
        r = r.header("x-pdf-sha256", lims_core::sha256(&bytes));
    }
    r.body(Body::from(bytes))
        .map_err(|_| ServiceError::internal("PDF_RESPONSE_FAILED"))
}
fn signature_exception(error: lims_signatures::SignatureError) -> ServiceError {
    match error {
        lims_signatures::SignatureError::Unsupported(message) => ServiceError::missing(message),
        _ => ServiceError::internal("PDF_SIGNATURE_EXCEPTION"),
    }
}
fn route_error(
    path: &str,
    multipart: bool,
    hmac_enabled: bool,
    error: ServiceError,
) -> Response<Body> {
    if error.code == "PDF_UPLOAD_IDLE_TIMEOUT" {
        // Exact observed Tomcat 10.1.26 container response on an idle JSON body.
        let body = include_bytes!("../../../fixtures/tomcat-10.1.26-upload-idle-400.html");
        return Response::builder()
            .status(400)
            .header(header::CONTENT_TYPE, "text/html;charset=utf-8")
            .header(header::CONTENT_LANGUAGE, "en")
            .header(header::CONTENT_LENGTH, body.len())
            .header(header::CONNECTION, "close")
            .body(Body::from(body.as_slice()))
            .unwrap();
    }
    if hmac_enabled && multipart && error.status == 408 {
        return Response::builder()
            .status(408)
            .header(header::CONNECTION, "close")
            .body(Body::empty())
            .unwrap();
    }
    // Spring's default error controller omits exception reasons. Preserve its
    // observable envelope; security filter and legacy policy errors write JSON directly.
    let filter_error = error.code.starts_with("PDF_HMAC_")
        && !(multipart
            && matches!(
                error.code.as_str(),
                "PDF_HMAC_BODY_MISMATCH" | "PDF_HMAC_BODY_RECEIVE_TIMEOUT"
            ));
    if filter_error
        || error.code == "PDF_BUSY"
        || (!multipart && error.code == "PDF_REQUEST_TOO_LARGE")
        || error.status == 501
        || (path == "/api/pdf/process" && error.status == 422)
    {
        return error_response(error);
    }
    if matches!(path, "/api/pdf/lighting-report" | "/api/pdf/lm79-report") && error.status == 422 {
        return json_response(422, json!({"error":error.message.unwrap_or(error.code)})).unwrap();
    }
    if path == "/api/pdf/extract-cover" && error.status == 500 {
        return json_response(500,json!({"success":false,"message":"Cover extraction failed","error":error.message.unwrap_or(error.code)})).unwrap();
    }
    if hmac_enabled && multipart && error.status != 408 {
        // Verified against the production-only Java classpath and real Redis:
        // Spring ERROR dispatch re-enters its multipart interceptor and becomes
        // 401 with no body or Content-Type. Emulate only the observed response,
        // without re-authenticating or consuming the nonce a second time.
        tracing::warn!(original_status = error.status, error_code = %error.code, route = path,
            "Java multipart ERROR-dispatch compatibility response");
        return Response::builder()
            .status(401)
            .body(Body::empty())
            .expect("empty response");
    }
    let status = StatusCode::from_u16(error.status).unwrap_or(StatusCode::INTERNAL_SERVER_ERROR);
    let mut response=json_response(status.as_u16(),json!({"timestamp":chrono::Utc::now().format("%Y-%m-%dT%H:%M:%S%.3f+00:00").to_string(),"status":status.as_u16(),"error":status.canonical_reason().unwrap_or("Unknown"),"path":path})).unwrap();
    if status == StatusCode::METHOD_NOT_ALLOWED {
        if let Some(method) = expected_method(path) {
            response
                .headers_mut()
                .insert(header::ALLOW, method.parse().unwrap());
        }
    }
    response
}
pub fn error_response(error: ServiceError) -> Response<Body> {
    let mut response = json_response(error.status, json!({"success":false,"error":error.code}))
        .expect("valid fixed JSON response");
    if error.status == 408 {
        response
            .headers_mut()
            .insert(header::CONNECTION, "close".parse().unwrap());
    }
    if error.code == "PDF_BUSY" {
        response
            .headers_mut()
            .insert(header::RETRY_AFTER, "1".parse().unwrap());
    }
    if error.code.starts_with("PDF_HMAC_")
        || matches!(error.code.as_str(), "PDF_BUSY" | "PDF_REQUEST_TOO_LARGE")
    {
        response.headers_mut().insert(
            header::CONTENT_TYPE,
            "application/json;charset=ISO-8859-1".parse().unwrap(),
        );
    }
    response
}
fn hold_guard<G: Send + Sync + Unpin + 'static>(
    mut response: Response<Body>,
    guard: G,
) -> Response<Body> {
    let body = std::mem::replace(response.body_mut(), Body::empty());
    *response.body_mut() = Body::new(PermitBody {
        body,
        permit: Some(guard),
    });
    response
}
fn hold_permit(
    mut response: Response<Body>,
    permit: Option<OwnedSemaphorePermit>,
) -> Response<Body> {
    if let Some(permit) = permit {
        let body = std::mem::replace(response.body_mut(), Body::empty());
        *response.body_mut() = Body::new(PermitBody {
            body,
            permit: Some(permit),
        });
    }
    response
}
struct PermitBody<G> {
    body: Body,
    permit: Option<G>,
}
impl<G: Unpin> http_body::Body for PermitBody<G> {
    type Data = Bytes;
    type Error = axum::Error;
    fn poll_frame(
        mut self: Pin<&mut Self>,
        cx: &mut Context<'_>,
    ) -> Poll<Option<Result<Frame<Bytes>, Self::Error>>> {
        let result = Pin::new(&mut self.body).poll_frame(cx);
        if matches!(result, Poll::Ready(None)) {
            self.permit.take();
        }
        result
    }
    fn is_end_stream(&self) -> bool {
        self.body.is_end_stream()
    }
    fn size_hint(&self) -> SizeHint {
        self.body.size_hint()
    }
}

// These integration adapters are implemented as the durable execution and
// signature service APIs are finalized; there is no successful fallback.
async fn health(state: &State) -> ServiceResult<Response<Body>> {
    let hmac_ready = state.config.hmac_ready();
    let signing_material_ready = tokio::task::spawn_blocking(lims_legacy::ready)
        .await
        .unwrap_or(false);
    let execution_database_ready = state.execution.database_ready().await;
    let execution_storage_ready = state.execution.storage_ready();
    let ready =
        hmac_ready && signing_material_ready && execution_database_ready && execution_storage_ready;
    json_response(
        if ready { 200 } else { 503 },
        json!({"status":if ready{"ok"}else{"not_ready"},"service":"pdf-renderer-java","hmac_ready":hmac_ready,"signing_material_ready":signing_material_ready,"execution_database_ready":execution_database_ready,"execution_storage_ready":execution_storage_ready}),
    )
}
async fn execution_dispatch(
    state: &State,
    method: &axum::http::Method,
    path: &str,
    parts: Option<MultipartData>,
    body: Bytes,
    headers: &BTreeMap<String, String>,
) -> ServiceResult<Response<Body>> {
    if path == "/internal/pdf/signatures/sign-existing-field" {
        if method != "POST" {
            return Err(ServiceError::new(405, "PDF_METHOD_NOT_ALLOWED"));
        }
        let data = required_parts(parts)?;
        // These JSON strings are parsed inside the Java controller method,
        // outside its execution-gate catches, so malformed JSON is HTTP 500.
        let operation: lims_execution::OperationClaim =
            serde_json::from_str(data.field("operation")?)
                .map_err(|_| ServiceError::internal("PDF_INVALID_JSON"))?;
        lims_execution::validate_headers(headers, &operation)?;
        let command: Value = serde_json::from_str(data.field("command")?)
            .map_err(|_| ServiceError::internal("PDF_INVALID_JSON"))?;
        if !command.is_object() && !command.is_null() {
            return Err(ServiceError::internal("PDF_INVALID_JSON"));
        }
        let (status, body) = state.execution.execute(&data, headers).await?;
        return json_response(status, body);
    }
    let suffix = path
        .strip_prefix("/internal/pdf/signatures/executions/")
        .ok_or_else(|| ServiceError::new(404, "PDF_ROUTE_NOT_FOUND"))?;
    let (id, action) = suffix.split_once('/').unwrap_or((suffix, ""));
    match (method.as_str(), action) {
        ("GET", "") => {
            let (status, body) =
                state
                    .execution
                    .status(id, headers)
                    .await
                    .map_err(|mut error| {
                        if error.code == "EXECUTION_LEDGER_NOT_READY" {
                            error.status = 500;
                        }
                        error
                    })?;
            json_response(status, body)
        }
        ("GET", "result") => {
            let result = state.execution.result(id, headers).await?;
            let file = tokio::fs::File::from_std(result.input);
            let stream = tokio_util::io::ReaderStream::new(file);
            Response::builder()
                .status(200)
                .header(header::CONTENT_TYPE, "application/pdf")
                .header(
                    header::CONTENT_DISPOSITION,
                    "attachment; filename=revision.pdf",
                )
                .header("x-pdf-sha256", result.sha256)
                .header(header::CONTENT_LENGTH, result.size)
                .body(Body::from_stream(stream))
                .map_err(|_| ServiceError::internal("PDF_RESPONSE_FAILED"))
        }
        ("POST", "retirement-evidence/inspect") => {
            let value = serde_json::from_slice(&body)
                .map_err(|_| ServiceError::bad_request("PDF_INVALID_JSON"))?;
            let (value, original_epoch) = normalize_retirement(value)?;
            let mut response = state.execution.retirement_inspect(id, headers, value)?;
            if let Some(epoch) = original_epoch {
                response["retirementEpoch"] = json!(epoch);
            }
            json_response(200, response)
        }
        _ => Err(ServiceError::new(404, "PDF_ROUTE_NOT_FOUND")),
    }
}
/// Jackson's primitive long/String binding semantics for this existing JSON DTO.
fn normalize_retirement(mut value: Value) -> ServiceResult<(Value, Option<i64>)> {
    let bad = || ServiceError::bad_request("PDF_INVALID_JSON");
    let map = value.as_object_mut().ok_or_else(bad)?;
    fn long(value: Option<&Value>) -> Option<i64> {
        match value {
            None | Some(Value::Null) => Some(0),
            Some(Value::Number(n)) => n.as_i64().or_else(|| {
                n.as_f64()
                    .filter(|v| v.is_finite() && *v >= i64::MIN as f64 && *v < i64::MAX as f64)
                    .map(|v| v as i64)
            }),
            Some(Value::String(s)) => {
                if s.trim().is_empty() {
                    Some(0)
                } else {
                    s.trim().parse().ok()
                }
            }
            _ => None,
        }
    }
    fn string(value: Option<&Value>) -> Result<Option<String>, ()> {
        match value {
            None | Some(Value::Null) => Ok(None),
            Some(Value::String(s)) => Ok(Some(s.clone())),
            Some(Value::Number(n)) => Ok(Some(n.to_string())),
            Some(Value::Bool(b)) => Ok(Some(b.to_string())),
            _ => Err(()),
        }
    }
    let epoch = long(map.get("retirementEpoch")).ok_or_else(bad)?;
    let size = long(map.get("expectedSize")).ok_or_else(bad)?;
    let hash = string(map.get("expectedSha256")).map_err(|_| bad())?;
    let phase = string(map.get("retirementPhase")).map_err(|_| bad())?;
    let hash = hash.ok_or_else(|| ServiceError::internal("RETIREMENT_EVIDENCE_NULL_HASH"))?;
    let invalid = || ServiceError::unprocessable("RETIREMENT_EVIDENCE_SNAPSHOT_INVALID");
    if hash.len() != 64
        || !hash
            .bytes()
            .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
        || size <= 0
    {
        return Err(invalid());
    }
    let phase = phase.ok_or_else(|| ServiceError::internal("RETIREMENT_EVIDENCE_NULL_PHASE"))?;
    if !matches!(
        phase.as_str(),
        "none" | "stage_intent" | "staged" | "purge_intent"
    ) || (phase != "none" && epoch <= 0)
    {
        return Err(invalid());
    }
    map.insert("retirementEpoch".into(), json!(epoch.max(0)));
    map.insert("expectedSize".into(), json!(size));
    map.insert("expectedSha256".into(), json!(hash));
    map.insert("retirementPhase".into(), json!(phase));
    Ok((value, (epoch < 0).then_some(epoch)))
}
fn verify_dispatch(data: MultipartData) -> ServiceResult<Response<Body>> {
    let trust = lims_signatures::TrustConfig::from_env().map_err(ServiceError::from)?;
    serialized_response(200, lims_signatures::verify(data.file("pdf")?, &trust))
}

#[cfg(test)]
mod tests {
    use super::*;
    use tower::ServiceExt;
    fn state() -> Arc<State> {
        Arc::new(
            State::new(
                Config::from_lookup(|k| match k {
                    "PDF_SERVICE_HMAC_ENABLED" => Some("false".into()),
                    "PDF_SERVICE_HMAC_NONCE_STORE" => Some("memory".into()),
                    _ => None,
                })
                .unwrap(),
            )
            .unwrap(),
        )
    }
    #[tokio::test]
    async fn confirmed_multipart_error_dispatch_quirk_has_no_body_or_content_type() {
        let response = route_error(
            "/internal/pdf/signatures/prepare",
            true,
            true,
            ServiceError::internal("PDF_SIGNATURE_EXCEPTION"),
        );
        assert_eq!(response.status(), 401);
        assert!(!response.headers().contains_key(header::CONTENT_TYPE));
        assert!(to_bytes(response.into_body(), 1024)
            .await
            .unwrap()
            .is_empty());
        let ordinary = route_error(
            "/internal/pdf/signatures/prepare",
            true,
            false,
            ServiceError::internal("PDF_SIGNATURE_EXCEPTION"),
        );
        assert_eq!(ordinary.status(), 500);
        let policy = route_error(
            "/api/pdf/process",
            true,
            true,
            ServiceError::unprocessable("PDF_LEGACY_MODE_RETIRED"),
        );
        assert_eq!(policy.status(), 422);
    }
    #[test]
    fn retirement_dto_matches_observed_jackson_coercions() {
        assert_eq!(normalize_retirement(Value::Null).unwrap_err().status, 400);
        assert_eq!(normalize_retirement(json!({})).unwrap_err().status, 500);
        let (value,epoch)=normalize_retirement(json!({"retirementEpoch":"1","expectedSize":"1","expectedSha256":"a".repeat(64),"retirementPhase":"none"})).unwrap();
        assert_eq!(value["retirementEpoch"], 1);
        assert_eq!(value["expectedSize"], 1);
        assert!(epoch.is_none());
    }
    #[tokio::test]
    async fn known_route_method_errors_include_exact_allow_header() {
        for (path,allow) in [("/api/pdf/health","GET"),("/api/pdf/contract","POST"),("/internal/pdf/signatures/executions/11111111-2222-4333-8444-555555555555","GET"),("/internal/pdf/signatures/executions/11111111-2222-4333-8444-555555555555/retirement-evidence/inspect","POST")] {
            let response=route_error(path,false,false,ServiceError::new(405,"PDF_METHOD_NOT_ALLOWED"));
            assert_eq!(response.headers()[header::ALLOW],allow);
            assert_eq!(response.status(),405);
        }
    }
    #[tokio::test]
    async fn readiness_is_unauthenticated_and_never_fake_success() {
        let config = Config::from_lookup(|_| None).unwrap();
        let response = router(Arc::new(State::new(config).unwrap()))
            .oneshot(
                Request::builder()
                    .uri("/api/pdf/health")
                    .body(Body::empty())
                    .unwrap(),
            )
            .await
            .unwrap();
        assert_eq!(response.status(), 503);
        let value: Value =
            serde_json::from_slice(&to_bytes(response.into_body(), 1024 * 1024).await.unwrap())
                .unwrap();
        assert_eq!(value["status"], "not_ready");
        assert_eq!(value["service"], "pdf-renderer-java");
        assert_eq!(value["hmac_ready"], false);
    }
    #[tokio::test]
    async fn four_report_controllers_reject_literal_null_before_rendering() {
        for path in [
            "/api/pdf/contract",
            "/api/pdf/entrust-order",
            "/api/pdf/lighting-report",
            "/api/pdf/lm79-report",
        ] {
            let response = router(state())
                .oneshot(
                    Request::builder()
                        .method("POST")
                        .uri(path)
                        .header(header::CONTENT_TYPE, "application/json")
                        .body(Body::from(" \r\nnull\t "))
                        .unwrap(),
                )
                .await
                .unwrap();
            assert_eq!(response.status(), 400, "{path}");
            let value: Value =
                serde_json::from_slice(&to_bytes(response.into_body(), 1024).await.unwrap())
                    .unwrap();
            assert_eq!(value["status"], 400);
            assert_eq!(value["error"], "Bad Request");
        }
    }
    #[tokio::test]
    async fn implicit_head_options_keep_auth_and_spring_mapping_boundaries() {
        for (path, allow) in [
            ("/api/pdf/health", "GET,HEAD,OPTIONS"),
            ("/api/pdf/contract", "POST,OPTIONS"),
        ] {
            let response = router(state())
                .oneshot(
                    Request::builder()
                        .method("OPTIONS")
                        .uri(path)
                        .body(Body::empty())
                        .unwrap(),
                )
                .await
                .unwrap();
            assert_eq!(response.status(), 200);
            assert_eq!(response.headers()[header::ALLOW], allow);
            assert_eq!(response.headers()["accept-patch"], "");
            assert!(!response.headers().contains_key(header::CONTENT_TYPE));
            assert!(to_bytes(response.into_body(), 1).await.unwrap().is_empty());
        }
        let response = router(state())
            .oneshot(
                Request::builder()
                    .method("HEAD")
                    .uri("/api/pdf/health")
                    .body(Body::empty())
                    .unwrap(),
            )
            .await
            .unwrap();
        assert_eq!(response.status(), 503);
        for method in ["HEAD", "OPTIONS"] {
            let authenticated =
                Arc::new(State::new(Config::from_lookup(|_| None).unwrap()).unwrap());
            let response = router(authenticated)
                .oneshot(
                    Request::builder()
                        .method(method)
                        .uri("/api/pdf/health")
                        .body(Body::empty())
                        .unwrap(),
                )
                .await
                .unwrap();
            assert_eq!(
                response.status(),
                401,
                "only GET health is authentication-exempt"
            );
        }
    }
    #[tokio::test]
    async fn request_slot_releases_when_response_finishes_not_when_connection_closes() {
        let state = state();
        let initial = state.http_requests.available_permits();
        let response = router(state.clone())
            .oneshot(
                Request::builder()
                    .uri("/api/pdf/health")
                    .body(Body::empty())
                    .unwrap(),
            )
            .await
            .unwrap();
        assert_eq!(state.http_requests.available_permits(), initial - 1);
        let _ = to_bytes(response.into_body(), 4096).await.unwrap();
        assert_eq!(state.http_requests.available_permits(), initial);
    }
    #[tokio::test]
    async fn detached_cpu_guard_retains_request_slot_after_response_drop() {
        let semaphore = Arc::new(Semaphore::new(1));
        let guard = Arc::new(semaphore.clone().acquire_owned().await.unwrap());
        let cpu_guard = guard.clone();
        let response = hold_guard(json_response(200, json!({"ok":true})).unwrap(), guard);
        drop(response);
        assert_eq!(semaphore.available_permits(), 0);
        drop(cpu_guard);
        assert_eq!(semaphore.available_permits(), 1);
    }
    #[tokio::test]
    async fn permit_lives_through_response_body_drop() {
        let state = state();
        let permit = admit(&state).unwrap();
        let response = hold_permit(
            json_response(200, json!({"ok":true})).unwrap(),
            Some(permit),
        );
        assert_eq!(admit(&state).unwrap_err().code, "PDF_BUSY");
        drop(response);
        assert!(admit(&state).is_ok());
    }
    #[tokio::test]
    async fn busy_has_retry_header_and_does_not_queue() {
        let state = state();
        let _permit = admit(&state).unwrap();
        let response = router(state)
            .oneshot(
                Request::builder()
                    .method("POST")
                    .uri("/api/pdf/contract")
                    .header("content-type", "application/json")
                    .body(Body::from("{}"))
                    .unwrap(),
            )
            .await
            .unwrap();
        assert_eq!(response.status(), 503);
        assert_eq!(response.headers()["retry-after"], "1");
        let value: Value =
            serde_json::from_slice(&to_bytes(response.into_body(), 1024).await.unwrap()).unwrap();
        assert_eq!(value["error"], "PDF_BUSY");
    }
    #[tokio::test]
    async fn busy_multipart_consumes_large_upload_without_parsing_or_admission() {
        let state = state();
        let _permit = admit(&state).unwrap();
        let polled = Arc::new(std::sync::atomic::AtomicUsize::new(0));
        let observed = polled.clone();
        // Deliberately invalid multipart: Busy must win before parsing.
        let stream = futures_util::stream::iter((0..96).map(move |_| {
            observed.fetch_add(1, Ordering::Relaxed);
            Ok::<_, std::io::Error>(Bytes::from(vec![b'x'; 16 * 1024]))
        }));
        let response = router(state.clone())
            .oneshot(
                Request::builder()
                    .method("POST")
                    .uri("/api/pdf/process")
                    .header(header::CONTENT_TYPE, "multipart/form-data; boundary=busy")
                    .body(Body::from_stream(stream))
                    .unwrap(),
            )
            .await
            .unwrap();
        assert_eq!(polled.load(Ordering::Relaxed), 96);
        assert_eq!(state.heavy.available_permits(), 0);
        assert_eq!(response.status(), 503);
        assert_eq!(response.headers()["retry-after"], "1");
        let value: Value =
            serde_json::from_slice(&to_bytes(response.into_body(), 1024).await.unwrap()).unwrap();
        assert_eq!(value, json!({"success":false,"error":"PDF_BUSY"}));
    }
    #[tokio::test]
    async fn busy_upload_discard_stops_at_existing_body_bound() {
        let polled = Arc::new(std::sync::atomic::AtomicUsize::new(0));
        let observed = polled.clone();
        let stream = futures_util::stream::iter((0..100).map(move |_| {
            observed.fetch_add(1, Ordering::Relaxed);
            Ok::<_, std::io::Error>(Bytes::from(vec![b'x'; 1024 * 1024]))
        }));
        discard_busy_upload(Body::from_stream(stream)).await;
        assert_eq!(
            polled.load(Ordering::Relaxed),
            hmac::BODY_LIMIT / (1024 * 1024) + 1
        );
    }
    #[tokio::test]
    async fn busy_large_multipart_upload_receives_http_response_over_tcp() {
        use hyper_util::{rt::TokioIo, service::TowerToHyperService};
        use tokio::io::{AsyncReadExt, AsyncWriteExt};

        let state = state();
        let _permit = admit(&state).unwrap();
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let server = tokio::spawn(async move {
            let (socket, _) = listener.accept().await.unwrap();
            hyper::server::conn::http1::Builder::new()
                .serve_connection(
                    TokioIo::new(socket),
                    TowerToHyperService::new(router(state)),
                )
                .await
                .unwrap();
        });
        tokio::time::timeout(Duration::from_secs(5), async {
            let mut client = tokio::net::TcpStream::connect(address).await.unwrap();
            let size = 96 * 16 * 1024;
            let headers = format!(
                "POST /api/pdf/process HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary=busy\r\nContent-Length: {size}\r\nConnection: close\r\n\r\n"
            );
            client.write_all(headers.as_bytes()).await.unwrap();
            // Give the full limiter time to reject before the upload begins.
            tokio::time::sleep(Duration::from_millis(50)).await;
            let chunk = vec![b'x'; 16 * 1024];
            for _ in 0..96 {
                client.write_all(&chunk).await.unwrap();
            }
            let mut response = Vec::new();
            client.read_to_end(&mut response).await.unwrap();
            let response = String::from_utf8(response).unwrap();
            assert!(response.starts_with("HTTP/1.1 503 Service Unavailable\r\n"));
            assert!(response.to_ascii_lowercase().contains("retry-after: 1\r\n"));
            let (_, body) = response.split_once("\r\n\r\n").unwrap();
            let value: Value = serde_json::from_str(body).unwrap();
            assert_eq!(value, json!({"success":false,"error":"PDF_BUSY"}));
        })
        .await
        .unwrap();
        server.await.unwrap();
    }
    #[tokio::test]
    async fn missing_auth_is_rejected_before_pdf_parser() {
        let response = router(Arc::new(
            State::new(Config::from_lookup(|_| None).unwrap()).unwrap(),
        ))
        .oneshot(
            Request::builder()
                .method("POST")
                .uri("/api/pdf/contract")
                .body(Body::from("bad json"))
                .unwrap(),
        )
        .await
        .unwrap();
        assert_eq!(response.status(), 401);
        assert_eq!(
            response.headers()["content-type"],
            "application/json;charset=ISO-8859-1"
        );
        let v: Value =
            serde_json::from_slice(&to_bytes(response.into_body(), 1024).await.unwrap()).unwrap();
        assert_eq!(v["error"], "PDF_HMAC_REQUIRED");
    }
    #[tokio::test]
    async fn report_routes_return_real_pdf_headers() {
        for (path, disposition) in [
            ("/api/pdf/contract", "attachment; filename=contract.pdf"),
            (
                "/api/pdf/entrust-order",
                "attachment; filename=entrust-order.pdf",
            ),
        ] {
            let response = router(state())
                .oneshot(
                    Request::builder()
                        .method("POST")
                        .uri(path)
                        .header("content-type", "application/json")
                        .body(Body::from("{}"))
                        .unwrap(),
                )
                .await
                .unwrap();
            assert_eq!(response.status(), 200, "{path}");
            assert_eq!(response.headers()["content-type"], "application/pdf");
            assert_eq!(response.headers()["content-disposition"], disposition);
            let bytes = to_bytes(response.into_body(), 20 * 1024 * 1024)
                .await
                .unwrap();
            assert!(bytes.starts_with(b"%PDF-"));
        }
    }
    #[tokio::test]
    async fn pdf_response_hash_and_length_match_exact_bytes() {
        let data = b"%PDF-1.4\n%%EOF".to_vec();
        let expected = lims_core::sha256(&data);
        let r = pdf_response(data.clone(), "revision.pdf", false, true).unwrap();
        assert_eq!(r.headers()["x-pdf-sha256"], expected);
        assert_eq!(r.headers()["content-length"], data.len().to_string());
        assert_eq!(to_bytes(r.into_body(), 1024).await.unwrap(), data);
    }
    #[tokio::test]
    async fn body_limit_is_enforced() {
        let body = vec![b' '; hmac::BODY_LIMIT + 1];
        let r = router(state())
            .oneshot(
                Request::builder()
                    .method("POST")
                    .uri("/api/pdf/contract")
                    .header("content-type", "application/json")
                    .body(Body::from(body))
                    .unwrap(),
            )
            .await
            .unwrap();
        assert_eq!(r.status(), 413);
    }
    #[tokio::test]
    async fn repeated_multipart_fields_are_rejected() {
        let b="--b\r\nContent-Disposition: form-data; name=\"mode\"\r\n\r\nstamp\r\n--b\r\nContent-Disposition: form-data; name=\"mode\"\r\n\r\nstamp\r\n--b--\r\n";
        let r = router(state())
            .oneshot(
                Request::builder()
                    .method("POST")
                    .uri("/api/pdf/process")
                    .header("content-type", "multipart/form-data; boundary=b")
                    .body(Body::from(b))
                    .unwrap(),
            )
            .await
            .unwrap();
        assert_eq!(r.status(), 401);
    }
}
