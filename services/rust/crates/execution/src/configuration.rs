//! Pure URL/options translation; never opens a connection or reads a certificate/key.
//! Unknown parameters are rejected before SQLx can silently discard their meaning.
use crate::{ExecutionError as E, Result};
use sqlx::mysql::{MySqlConnectOptions, MySqlSslMode};
use std::{collections::BTreeSet, str::FromStr};

pub fn database_connect_options(
    input: &str,
    username: &str,
    password: &str,
) -> Result<MySqlConnectOptions> {
    if input.is_empty() || username.is_empty() || password.is_empty() {
        return Err(E::NotReady);
    }
    let mut url = url::Url::parse(input.strip_prefix("jdbc:").unwrap_or(input))
        .map_err(|_| invalid("invalid single-host MySQL URL"))?;
    if url.scheme() != "mysql" || url.host_str().is_none() || url.fragment().is_some() {
        return Err(invalid(
            "only a single-host mysql:// or jdbc:mysql:// URL is supported",
        ));
    }
    let pairs = url
        .query_pairs()
        .map(|(k, v)| (k.into_owned(), v.into_owned()))
        .collect::<Vec<_>>();
    let mut seen = BTreeSet::new();
    let mut retained = Vec::new();
    let mut explicit_mode = None;
    let mut use_ssl = true;
    let mut require_ssl = false;
    let mut verify_server = false;
    for (key, value) in pairs {
        let canonical = match key.as_str() {
            "sslMode" | "sslmode" | "ssl-mode" => "ssl-mode",
            "useSSL" => "useSSL",
            "requireSSL" => "requireSSL",
            "verifyServerCertificate" => "verifyServerCertificate",
            "sslca" | "ssl-ca" => "ssl-ca",
            "sslcert" | "ssl-cert" => "ssl-cert",
            "sslkey" | "ssl-key" => "ssl-key",
            "timezone" | "time-zone" | "serverTimezone" | "connectionTimeZone" => "timezone",
            "charset" => "charset",
            "collation" => "collation",
            "statement-cache-capacity" => "statement-cache-capacity",
            "socket" => "socket",
            // Includes Java keystores, TLS protocol/cipher restrictions and auth options.
            // Those need an explicit audited mapping; never pass them through silently.
            _ => {
                return Err(invalid(
                    "unsupported database URL option; see execution TLS compatibility notes",
                ))
            }
        };
        if !seen.insert(canonical.to_owned()) {
            return Err(invalid(
                "duplicate or conflicting database URL option aliases",
            ));
        }
        match canonical {
            "ssl-mode" => explicit_mode = Some(parse_ssl_mode(&value)?),
            "useSSL" => use_ssl = parse_bool(&value)?,
            "requireSSL" => require_ssl = parse_bool(&value)?,
            "verifyServerCertificate" => verify_server = parse_bool(&value)?,
            "timezone" => {
                if !matches!(value.as_str(), "UTC" | "Etc/UTC" | "+00:00") {
                    return Err(invalid(
                        "execution database time zone must be explicitly UTC",
                    ));
                }
                retained.push(("timezone".to_owned(), "+00:00".to_owned()));
            }
            "statement-cache-capacity" => {
                value
                    .parse::<usize>()
                    .map_err(|_| invalid("invalid statement cache capacity"))?;
                retained.push((canonical.to_owned(), value));
            }
            _ => retained.push((canonical.to_owned(), value)),
        }
    }
    // Connector/J 8.3 JdbcPropertySetImpl/DefaultPropertySet verified by local oracle:
    // explicit sslMode overrides the legacy flags; otherwise false useSSL wins,
    // then verifyServerCertificate, then requireSSL, otherwise PREFERRED.
    let ssl_mode = explicit_mode.unwrap_or(if !use_ssl {
        MySqlSslMode::Disabled
    } else if verify_server {
        MySqlSslMode::VerifyCa
    } else if require_ssl {
        MySqlSslMode::Required
    } else {
        MySqlSslMode::Preferred
    });
    url.set_query(None);
    if !retained.is_empty() {
        let mut query = url.query_pairs_mut();
        for (key, value) in retained {
            query.append_pair(&key, &value);
        }
    }
    // Never include parser diagnostics containing the complete URL or credentials.
    let options = MySqlConnectOptions::from_str(url.as_str())
        .map_err(|_| invalid("database URL contains an invalid supported option"))?;
    Ok(options
        .username(username)
        .password(password)
        .ssl_mode(ssl_mode))
}
fn invalid(message: &'static str) -> E {
    E::DatabaseConfiguration(message)
}
fn parse_bool(value: &str) -> Result<bool> {
    match value.to_ascii_lowercase().as_str() {
        "true" | "yes" => Ok(true),
        "false" | "no" => Ok(false),
        _ => Err(invalid("JDBC TLS boolean must be true, false, yes or no")),
    }
}
fn parse_ssl_mode(value: &str) -> Result<MySqlSslMode> {
    match value.to_ascii_uppercase().as_str() {
        "DISABLED" => Ok(MySqlSslMode::Disabled),
        "PREFERRED" => Ok(MySqlSslMode::Preferred),
        "REQUIRED" => Ok(MySqlSslMode::Required),
        "VERIFY_CA" => Ok(MySqlSslMode::VerifyCa),
        "VERIFY_IDENTITY" => Ok(MySqlSslMode::VerifyIdentity),
        _ => Err(invalid("unsupported TLS mode")),
    }
}
