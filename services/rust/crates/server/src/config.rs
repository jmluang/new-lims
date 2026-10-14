use base64::{engine::general_purpose::STANDARD, Engine};
use lims_core::{ServiceError, ServiceResult};
use std::{collections::BTreeMap, env, net::IpAddr};

/// Secret-bearing configuration deliberately does not implement Debug.
#[derive(Clone)]
pub struct Config {
    pub bind: IpAddr,
    pub port: u16,
    pub max_concurrent_jobs: usize,
    pub max_http_requests: usize,
    pub max_connections: usize,
    pub accept_count: u32,
    pub hmac_enabled: bool,
    pub active_key_id: String,
    pub hmac_keys: BTreeMap<String, Vec<u8>>,
    pub max_clock_skew_seconds: u64,
    pub nonce_store: String,
    pub redis_host: String,
    pub redis_port: u16,
}
impl Config {
    pub fn from_env() -> ServiceResult<Self> {
        Self::from_lookup(|key| env::var(key).ok())
    }
    pub fn from_lookup(get: impl Fn(&str) -> Option<String>) -> ServiceResult<Self> {
        let value = |key: &str, default: &str| get(key).unwrap_or_else(|| default.into());
        let number = |key: &str, default: &str| {
            value(key, default)
                .parse::<u64>()
                .map_err(|_| ServiceError::internal(format!("CONFIG_INVALID_{key}")))
        };
        let boolean =
            |key: &str, default: &str| match value(key, default).to_ascii_lowercase().as_str() {
                "true" => Ok(true),
                "false" => Ok(false),
                _ => Err(ServiceError::internal(format!("CONFIG_INVALID_{key}"))),
            };
        let connector_number = |name: &str, java_name: &str, default: &str| {
            get(name)
                .or_else(|| get(java_name))
                .unwrap_or_else(|| default.into())
                .parse::<u64>()
                .map_err(|_| ServiceError::internal(format!("CONFIG_INVALID_{name}")))
        };
        let max_connections = connector_number(
            "PDF_HTTP_MAX_CONNECTIONS",
            "SERVER_TOMCAT_MAX_CONNECTIONS",
            "8192",
        )?;
        let accept_count =
            connector_number("PDF_HTTP_ACCEPT_COUNT", "SERVER_TOMCAT_ACCEPT_COUNT", "100")?;
        if max_connections == 0
            || max_connections > i32::MAX as u64
            || accept_count == 0
            || accept_count > i32::MAX as u64
        {
            return Err(ServiceError::internal("CONFIG_CONNECTION_LIMITS_INVALID"));
        }
        let jobs = number("PDF_MAX_CONCURRENT_JOBS", "1")?;
        let threads = number("PDF_HTTP_MAX_THREADS", "16")?;
        if jobs == 0 || threads == 0 {
            return Err(ServiceError::internal(
                "CONFIG_CONCURRENCY_MUST_BE_POSITIVE",
            ));
        }
        if !value("PDF_SIGNING_HASH_ALGO", "SHA256").eq_ignore_ascii_case("SHA256") {
            return Err(ServiceError::internal(
                "PDF_SIGNING_POLICY_HASH_UNSUPPORTED",
            ));
        }
        let config = Self {
            bind: value("PDF_SERVICE_BIND_ADDRESS", "127.0.0.1")
                .parse()
                .map_err(|_| ServiceError::internal("CONFIG_BIND_ADDRESS_INVALID"))?,
            port: number("PDF_SERVICE_PORT", "8081")?
                .try_into()
                .map_err(|_| ServiceError::internal("CONFIG_PORT_INVALID"))?,
            max_concurrent_jobs: jobs
                .try_into()
                .map_err(|_| ServiceError::internal("CONFIG_CONCURRENCY_INVALID"))?,
            max_http_requests: threads
                .try_into()
                .map_err(|_| ServiceError::internal("CONFIG_CONCURRENCY_INVALID"))?,
            max_connections: max_connections as usize,
            accept_count: accept_count as u32,
            hmac_enabled: boolean("PDF_SERVICE_HMAC_ENABLED", "true")?,
            active_key_id: value("PDF_SERVICE_HMAC_ACTIVE_KEY_ID", "primary"),
            hmac_keys: parse_keys(&value("PDF_SERVICE_HMAC_KEYS", ""))?,
            max_clock_skew_seconds: number("PDF_SERVICE_HMAC_MAX_CLOCK_SKEW_SECONDS", "60")?,
            nonce_store: value("PDF_SERVICE_HMAC_NONCE_STORE", "redis"),
            redis_host: value("PDF_SERVICE_REDIS_HOST", "127.0.0.1"),
            redis_port: number("PDF_SERVICE_REDIS_PORT", "6379")?
                .try_into()
                .map_err(|_| ServiceError::internal("CONFIG_REDIS_PORT_INVALID"))?,
        };
        if !matches!(config.nonce_store.as_str(), "redis" | "memory") {
            return Err(ServiceError::internal("CONFIG_NONCE_STORE_INVALID"));
        }
        Ok(config)
    }
    pub fn hmac_ready(&self) -> bool {
        !self.hmac_enabled || self.hmac_keys.contains_key(&self.active_key_id)
    }
}
pub fn token(value: &str, min: usize, max: usize) -> bool {
    (min..=max).contains(&value.len())
        && value
            .bytes()
            .all(|c| c.is_ascii_alphanumeric() || b"._-".contains(&c))
}
fn parse_keys(encoded: &str) -> ServiceResult<BTreeMap<String, Vec<u8>>> {
    let mut keys = BTreeMap::new();
    if encoded.trim().is_empty() {
        return Ok(keys);
    }
    for entry in encoded.split(',') {
        let (id, value) = entry
            .trim()
            .split_once(':')
            .ok_or_else(|| ServiceError::internal("CONFIG_HMAC_KEYS_INVALID"))?;
        if !token(id, 1, 64) {
            return Err(ServiceError::internal("CONFIG_HMAC_KEY_ID_INVALID"));
        }
        let secret = STANDARD
            .decode(value)
            .map_err(|_| ServiceError::internal("CONFIG_HMAC_KEY_BASE64_INVALID"))?;
        if secret.len() < 32 {
            return Err(ServiceError::internal("CONFIG_HMAC_KEY_TOO_SHORT"));
        }
        if keys.insert(id.into(), secret).is_some() {
            return Err(ServiceError::internal("CONFIG_HMAC_KEY_DUPLICATE"));
        }
    }
    Ok(keys)
}
#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn default_boundary_matches_java_application() {
        let c = Config::from_lookup(|_| None).unwrap();
        assert!(c.bind.is_loopback());
        assert_eq!(c.port, 8081);
        assert_eq!(c.max_concurrent_jobs, 1);
        assert_eq!(c.max_http_requests, 16);
        assert_eq!(c.max_connections, 8192);
        assert_eq!(c.accept_count, 100);
        assert!(c.hmac_enabled);
        assert!(!c.hmac_ready());
    }
    #[test]
    fn connection_limits_are_independent_from_request_workers() {
        let config = Config::from_lookup(|key| match key {
            "PDF_HTTP_MAX_THREADS" => Some("23".into()),
            "SERVER_TOMCAT_MAX_CONNECTIONS" => Some("32".into()),
            "PDF_HTTP_ACCEPT_COUNT" => Some("7".into()),
            _ => None,
        })
        .unwrap();
        assert_eq!(config.max_http_requests, 23);
        assert_eq!(config.max_connections, 32);
        assert_eq!(config.accept_count, 7);
        assert_eq!(config.max_concurrent_jobs, 1);
        assert!(
            Config::from_lookup(|key| (key == "PDF_HTTP_MAX_CONNECTIONS").then(|| "0".into()))
                .is_err()
        );
    }
    #[test]
    fn secret_validation_and_no_silent_fallback() {
        let key = STANDARD.encode([1u8; 32]);
        assert_eq!(
            parse_keys(&format!("primary:{key}")).unwrap()["primary"].len(),
            32
        );
        assert!(parse_keys("bad:YWJj").is_err());
        assert!(parse_keys(&format!("p:{key},p:{key}")).is_err());
        assert!(
            Config::from_lookup(|k| (k == "PDF_MAX_CONCURRENT_JOBS").then(|| "0".into())).is_err()
        );
    }
}
