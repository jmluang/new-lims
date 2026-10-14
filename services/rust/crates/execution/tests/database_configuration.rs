//! Pure options tests: no sockets, trust-store reads, credentials or TLS handshakes.
use lims_execution::{database_connect_options, ExecutionError};
use sqlx::mysql::MySqlSslMode;
fn mode(query: &str) -> MySqlSslMode {
    let input = if query.is_empty() {
        "jdbc:mysql://localhost/test".to_owned()
    } else {
        format!("jdbc:mysql://localhost/test?{query}")
    };
    database_connect_options(&input, "test-only-user", "test-only-password")
        .unwrap()
        .get_ssl_mode()
}
#[test]
fn jdbc_explicit_tls_modes_preserve_the_requested_security_level() {
    for (query, expected) in [
        ("", "Preferred"),
        ("sslMode=DISABLED", "Disabled"),
        ("sslMode=PREFERRED", "Preferred"),
        ("sslMode=REQUIRED", "Required"),
        ("sslMode=VERIFY_CA", "VerifyCa"),
        ("sslMode=VERIFY_IDENTITY", "VerifyIdentity"),
    ] {
        assert_eq!(format!("{:?}", mode(query)), expected, "case {query}");
    }
}
#[test]
fn jdbc_legacy_tls_flags_and_explicit_precedence_match_connector_j_oracle() {
    for (query, expected) in [
        ("useSSL=false", "Disabled"),
        ("useSSL=true", "Preferred"),
        ("requireSSL=true", "Required"),
        ("verifyServerCertificate=true", "VerifyCa"),
        ("useSSL=false&requireSSL=true", "Disabled"),
        ("useSSL=false&verifyServerCertificate=true", "Disabled"),
        ("requireSSL=true&verifyServerCertificate=true", "VerifyCa"),
        ("sslMode=VERIFY_IDENTITY&useSSL=false", "VerifyIdentity"),
        ("sslMode=DISABLED&requireSSL=true", "Disabled"),
    ] {
        assert_eq!(format!("{:?}", mode(query)), expected, "case {query}");
    }
}
#[test]
fn native_tls_aliases_and_utc_jdbc_option_are_preserved() {
    assert!(matches!(mode("ssl-mode=REQUIRED"), MySqlSslMode::Required));
    assert!(matches!(
        mode("sslmode=VERIFY_IDENTITY"),
        MySqlSslMode::VerifyIdentity
    ));
    assert!(matches!(
        mode("ssl%4dode=VERIFY_CA"),
        MySqlSslMode::VerifyCa
    ));
    assert!(matches!(
        mode("useSSL=false&serverTimezone=UTC"),
        MySqlSslMode::Disabled
    ));
    let options = database_connect_options(
        "mysql://localhost/test?ssl-mode=VERIFY_IDENTITY&ssl-ca=/nonexistent/test-ca.pem",
        "test-only-user",
        "test-only-password",
    )
    .unwrap();
    assert!(matches!(
        options.get_ssl_mode(),
        MySqlSslMode::VerifyIdentity
    ));
    // The nonexistent path proves parsing does not read the trust material.
}
#[test]
fn unsupported_or_ambiguous_jdbc_security_options_fail_before_sqlx() {
    for query in [
        "sslMode=UNKNOWN",
        "useSSL=perhaps",
        "sslMode=VERIFY_IDENTITY&ssl-mode=DISABLED",
        "requireSSL=true&requireSSL=false",
        "trustCertificateKeyStoreUrl=file:unsupported.jks",
        "clientCertificateKeyStorePassword=synthetic-secret",
        "tlsVersions=TLSv1.3",
        "enabledTLSProtocols=TLSv1.3",
        "tlsCiphersuites=unsupported-suite",
        "fallbackToSystemTrustStore=false",
        "allowPublicKeyRetrieval=false",
        "serverRSAPublicKeyFile=/nonexistent/rsa.pem",
        "unmappedOption=synthetic-secret",
        "serverTimezone=America/New_York",
    ] {
        let error = database_connect_options(
            &format!("jdbc:mysql://test-only-user:synthetic-secret@localhost/test?{query}"),
            "test-only-user",
            "test-only-password",
        )
        .unwrap_err();
        assert!(matches!(error, ExecutionError::DatabaseConfiguration(_)));
        let message = error.to_string();
        assert!(!message.contains("synthetic-secret"));
        assert!(!message.contains("test-only-user"));
        assert!(!message.contains("jdbc:mysql"));
    }
}
