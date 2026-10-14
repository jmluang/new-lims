use hyper_util::{
    rt::{TokioIo, TokioTimer},
    service::TowerToHyperService,
};
use lims_server::{
    config::Config,
    http::{router, State},
};
use std::{
    sync::{
        atomic::{AtomicUsize, Ordering},
        Arc,
    },
    time::Duration,
};
use tokio::{
    sync::{watch, Semaphore},
    task::JoinSet,
};

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error>> {
    tracing_subscriber::fmt()
        .with_env_filter(
            tracing_subscriber::EnvFilter::try_from_default_env().unwrap_or_else(|_| "info".into()),
        )
        .init();
    let config = Config::from_env()?;
    let address = std::net::SocketAddr::new(config.bind, config.port);
    // Match the actual Tomcat connector defaults independently of its active
    // worker-thread limit. Idle keep-alive sockets do not consume request slots.
    let socket = if address.is_ipv4() {
        tokio::net::TcpSocket::new_v4()?
    } else {
        tokio::net::TcpSocket::new_v6()?
    };
    socket.set_reuseaddr(true)?;
    socket.bind(address)?;
    let listener = socket.listen(config.accept_count)?;
    let connections = Arc::new(Semaphore::new(config.max_connections));
    let state = Arc::new(State::new(config)?);
    let app = router(state.clone());
    tracing::info!(%address,"Rust PDF service listening");
    let (shutdown_tx, shutdown_rx) = watch::channel(false);
    tokio::spawn(async move {
        shutdown_signal().await;
        let _ = shutdown_tx.send(true);
    });
    let mut stop = shutdown_rx.clone();
    let mut jobs = JoinSet::new();
    let retirement = if std::env::var("PDF_EXECUTION_LEDGER_ENABLED")
        .is_ok_and(|v| v.eq_ignore_ascii_case("true"))
    {
        let service = state.execution.clone();
        let mut stop = shutdown_rx.clone();
        let delay = std::env::var("PDF_EXECUTION_RETIREMENT_SWEEP_INTERVAL_MS")
            .ok()
            .and_then(|s| s.parse::<u64>().ok())
            .filter(|n| *n > 0)
            .unwrap_or(60_000);
        Some(tokio::spawn(async move {
            loop {
                if *stop.borrow() {
                    break;
                }
                if service.retirement_sweep(100).await.is_err() {
                    tracing::warn!("Execution retirement sweep failed; existing gates retained");
                }
                tokio::select! {_=stop.changed()=>break,_=tokio::time::sleep(Duration::from_millis(delay))=>{}}
            }
        }))
    } else {
        None
    };
    loop {
        let slot = tokio::select! { _=stop.changed()=>break, slot=connections.clone().acquire_owned()=>slot? };
        let (stream, _) =
            tokio::select! {_=stop.changed()=>break, result=listener.accept()=>result?};
        let tower_service = TowerToHyperService::new(app.clone());
        let request_count = Arc::new(AtomicUsize::new(0));
        // Actual Tomcat default and the ordinary 102-request oracle both close
        // the connection after its hundredth response, without dropping it.
        let service = hyper::service::service_fn(move |request| {
            let service = tower_service.clone();
            let request_number = request_count.fetch_add(1, Ordering::Relaxed) + 1;
            async move {
                let mut response = hyper::service::Service::call(&service, request).await?;
                if request_number >= 100 {
                    response.headers_mut().insert(
                        hyper::header::CONNECTION,
                        hyper::header::HeaderValue::from_static("close"),
                    );
                }
                Ok::<_, std::convert::Infallible>(response)
            }
        });
        let mut stop = shutdown_rx.clone();
        jobs.spawn(async move {
            let _slot=slot;
            let mut builder=hyper::server::conn::http1::Builder::new();
            // Spring preserves X-Pdf-Sha256; the existing PHP client indexes
            // getHeaders() by that exact spelling before verifying result bytes.
            builder.title_case_headers(true);
            builder.timer(TokioTimer::new()).header_read_timeout(Duration::from_secs(15)).max_buf_size(16*1024);
            let connection=builder.serve_connection(TokioIo::new(stream),service);
            tokio::pin!(connection);
            tokio::select! {
                result=&mut connection=>{if result.is_err(){tracing::debug!("HTTP connection closed with protocol error");}},
                _=stop.changed()=>{connection.as_mut().graceful_shutdown();let _=connection.await;},
            }
        });
        while jobs.try_join_next().is_some() {}
    }
    // Stop accepting first, then allow admitted CPU jobs and response writes to
    // finish. Never release the limiter while spawn_blocking still uses memory.
    while jobs.join_next().await.is_some() {}
    state.execution.begin_shutdown();
    if let Some(retirement) = retirement {
        let _ = retirement.await;
    }
    state.execution.wait_idle().await;
    tracing::info!("Rust PDF service shut down");
    Ok(())
}
async fn shutdown_signal() {
    #[cfg(unix)]
    {
        let mut term = tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
            .expect("signal handler");
        tokio::select! {_=tokio::signal::ctrl_c()=>{},_=term.recv()=>{}}
    }
    #[cfg(not(unix))]
    {
        let _ = tokio::signal::ctrl_c().await;
    }
}
