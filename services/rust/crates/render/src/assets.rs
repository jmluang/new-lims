//! HTTP asset reads for the authenticated server-side Java-compatible renderers.
//! TLS verification is mandatory. Redirects follow Java's same-protocol rule.
use anyhow::{Context, Result, ensure};
use std::time::Duration;

pub(crate) fn load_http_asset(raw: &str) -> Result<Vec<u8>> {
    fetch(raw, true)
}

pub(crate) fn load_contract_asset(raw: &str) -> Result<Vec<u8>> {
    fetch(raw, false)
}

fn fetch(raw: &str, bounded_timeout: bool) -> Result<Vec<u8>> {
    let raw = raw.to_owned();
    std::thread::spawn(move || {
        tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()?
            .block_on(fetch_async(&raw, bounded_timeout))
    })
    .join()
    .map_err(|_| anyhow::anyhow!("Asset HTTP worker panicked"))?
}
async fn fetch_async(raw: &str, bounded_timeout: bool) -> Result<Vec<u8>> {
    let url = reqwest::Url::parse(raw).context("Invalid asset URL")?;
    ensure!(
        matches!(url.scheme(), "http" | "https"),
        "Unsupported asset URL scheme"
    );
    let mut builder = reqwest::Client::builder().redirect(reqwest::redirect::Policy::none());
    if bounded_timeout {
        builder = builder
            .connect_timeout(Duration::from_secs(5))
            .read_timeout(Duration::from_secs(10));
    }
    let client = builder.build()?;
    let mut url = url;
    let mut redirects = 0;
    loop {
        let mut response = client
            .get(url.clone())
            .send()
            .await
            .context("Asset fetch failed")?;
        let status = response.status().as_u16();
        // Java 17 HttpURLConnection follows these status codes, but not 308.
        if matches!(status, 300 | 301 | 302 | 303 | 305 | 307)
            && let Some(location) = response.headers().get(reqwest::header::LOCATION)
        {
            let next = url.join(location.to_str()?)?;
            if next.scheme() == url.scheme() {
                redirects += 1;
                ensure!(redirects < 20, "Too many asset redirects");
                url = next;
                continue;
            }
        }
        if bounded_timeout {
            ensure!(status == 200, "Non-200 asset response: {status}");
        } else {
            ensure!(status < 400, "HTTP asset error response: {status}");
        }
        let mut bytes = Vec::new();
        while let Some(chunk) = response.chunk().await? {
            bytes.extend_from_slice(&chunk);
        }
        return Ok(bytes);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        io::{Read, Write},
        net::TcpListener,
        thread,
    };
    fn server(responses: Vec<String>) -> (String, thread::JoinHandle<()>) {
        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        let address = format!("http://{}/asset", listener.local_addr().unwrap());
        let task = thread::spawn(move || {
            for response in responses {
                let (mut stream, _) = listener.accept().unwrap();
                stream
                    .set_read_timeout(Some(Duration::from_secs(5)))
                    .unwrap();
                let mut request = Vec::new();
                let mut byte = [0u8; 1];
                while !request.ends_with(b"\r\n\r\n") {
                    stream.read_exact(&mut byte).unwrap();
                    request.push(byte[0]);
                    assert!(request.len() < 8192);
                }
                stream.write_all(response.as_bytes()).unwrap();
            }
        });
        (address, task)
    }
    #[test]
    fn chunked_and_same_protocol_redirect_assets() {
        let (url,worker)=server(vec!["HTTP/1.1 302 Found\r\nLocation: /next\r\nContent-Length: 0\r\nConnection: close\r\n\r\n".into(),"HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n4\r\nTEST\r\n3\r\n123\r\n0\r\n\r\n".into()]);
        assert_eq!(load_http_asset(&url).unwrap(), b"TEST123");
        worker.join().unwrap();
    }
    #[test]
    fn contract_url_openstream_and_entrust_http_ok_are_different() {
        let (url, worker) = server(vec![
            "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\nConnection: close\r\n\r\n".into(),
        ]);
        assert!(load_contract_asset(&url).unwrap().is_empty());
        worker.join().unwrap();
        let (url, worker) = server(vec![
            "HTTP/1.1 204 No Content\r\nContent-Length: 0\r\nConnection: close\r\n\r\n".into(),
        ]);
        assert!(load_http_asset(&url).is_err());
        worker.join().unwrap();
    }
    #[test]
    fn java_17_does_not_follow_308_and_non200_entrust_is_failure() {
        let (url,worker)=server(vec!["HTTP/1.1 308 Permanent Redirect\r\nLocation: /next\r\nContent-Length: 4\r\nConnection: close\r\n\r\nTEST".into()]);
        assert_eq!(load_contract_asset(&url).unwrap(), b"TEST");
        worker.join().unwrap();
        let (url, worker) = server(vec![
            "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n".into(),
        ]);
        assert!(load_http_asset(&url).is_err());
        worker.join().unwrap();
    }
}
