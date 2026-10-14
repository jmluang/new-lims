use crate::hmac::{Authenticated, NONCE_TTL_SECONDS, VERSION};
use lims_core::{ServiceError, ServiceResult};
use std::{
    collections::HashMap,
    sync::Mutex,
    time::{Duration, Instant},
};

/// Redis is the default shared production store. Memory is explicit/test-only,
/// matching the Java opt-in store; it never replaces a failed Redis request.
pub enum NonceStore {
    Redis(redis::Client),
    Memory(Mutex<HashMap<String, Instant>>),
}
impl NonceStore {
    pub fn new(config: &crate::config::Config) -> ServiceResult<Self> {
        if config.nonce_store == "memory" {
            return Ok(Self::Memory(Mutex::new(HashMap::new())));
        }
        let client = redis::Client::open(redis::ConnectionInfo {
            addr: redis::ConnectionAddr::Tcp(config.redis_host.clone(), config.redis_port),
            redis: redis::RedisConnectionInfo::default(),
        })
        .map_err(|_| unavailable())?;
        Ok(Self::Redis(client))
    }
    pub async fn reserve(&self, auth: &Authenticated) -> ServiceResult<()> {
        let key = format!("pdf-hmac:{VERSION}:{}:{}", auth.key_id, auth.nonce);
        let reserved = match self {
            Self::Memory(store) => {
                let mut data = store.lock().map_err(|_| unavailable())?;
                let now = Instant::now();
                data.retain(|_, expiry| *expiry > now);
                if let std::collections::hash_map::Entry::Vacant(entry) = data.entry(key) {
                    entry.insert(now + Duration::from_secs(NONCE_TTL_SECONDS));
                    true
                } else {
                    false
                }
            }
            Self::Redis(client) => {
                let work = async {
                    let mut conn = client.get_multiplexed_async_connection().await?;
                    redis::cmd("SET")
                        .arg(&key)
                        .arg(&auth.correlation_id)
                        .arg("NX")
                        .arg("EX")
                        .arg(NONCE_TTL_SECONDS)
                        .query_async::<Option<String>>(&mut conn)
                        .await
                };
                tokio::time::timeout(Duration::from_secs(2), work)
                    .await
                    .map_err(|_| unavailable())?
                    .map_err(|_| unavailable())?
                    .is_some()
            }
        };
        if reserved {
            Ok(())
        } else {
            Err(ServiceError::new(409, "PDF_HMAC_REPLAYED"))
        }
    }
    /// Separate deep diagnostic; the public Java-compatible health does not probe Redis.
    pub async fn readiness(&self) -> bool {
        match self {
            Self::Memory(_) => true,
            Self::Redis(client) => tokio::time::timeout(Duration::from_secs(2), async {
                let mut c = client.get_multiplexed_async_connection().await?;
                redis::cmd("PING").query_async::<String>(&mut c).await
            })
            .await
            .is_ok_and(|r| r.is_ok_and(|s| s == "PONG")),
        }
    }
}
fn unavailable() -> ServiceError {
    ServiceError::unavailable("PDF_HMAC_NONCE_STORE_UNAVAILABLE")
}
#[cfg(test)]
mod tests {
    use super::*;
    #[tokio::test]
    async fn each_nonce_is_single_use_and_key_scoped() {
        let s = NonceStore::Memory(Mutex::new(HashMap::new()));
        let mut a = Authenticated {
            key_id: "a".into(),
            nonce: "nonce012345678901".into(),
            correlation_id: "correlation".into(),
            manifest: String::new(),
        };
        s.reserve(&a).await.unwrap();
        assert_eq!(s.reserve(&a).await.unwrap_err().code, "PDF_HMAC_REPLAYED");
        a.key_id = "b".into();
        s.reserve(&a).await.unwrap();
    }
    #[tokio::test]
    async fn redis_connection_failure_is_closed() {
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let port = listener.local_addr().unwrap().port();
        drop(listener);
        let c = crate::config::Config::from_lookup(|k| {
            (k == "PDF_SERVICE_REDIS_PORT").then(|| port.to_string())
        })
        .unwrap();
        let s = NonceStore::new(&c).unwrap();
        let a = Authenticated {
            key_id: "a".into(),
            nonce: "nonce012345678901".into(),
            correlation_id: "correlation".into(),
            manifest: String::new(),
        };
        assert_eq!(
            s.reserve(&a).await.unwrap_err().code,
            "PDF_HMAC_NONCE_STORE_UNAVAILABLE"
        );
        assert!(!s.readiness().await);
    }
}
