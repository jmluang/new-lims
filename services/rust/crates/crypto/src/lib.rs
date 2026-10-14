//! Shared PKCS#12 envelope compatibility. Signature algorithm policies stay with callers.
use openssl::{
    error::ErrorStack,
    pkcs12::{ParsedPkcs12_2, Pkcs12},
    provider::Provider,
};
#[derive(Debug)]
pub enum Pkcs12Error {
    InvalidEncoding(ErrorStack),
    InvalidPasswordOrData(ErrorStack),
    LegacyProviderUnavailable(ErrorStack),
}
impl std::fmt::Display for Pkcs12Error {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.write_str(match self {
            Self::InvalidEncoding(_) => "Invalid PKCS#12 encoding",
            Self::InvalidPasswordOrData(_) => "Invalid PKCS#12 password or data",
            Self::LegacyProviderUnavailable(_) => {
                "OpenSSL legacy provider unavailable for configured PKCS#12 envelope"
            }
        })
    }
}
impl std::error::Error for Pkcs12Error {}
/// New envelopes use the default provider. Standard older RC2 PBE envelopes get one
/// explicit retry only after an unsupported-algorithm error. The provider is temporary,
/// fallback providers stay available, and no system configuration is changed.
pub fn parse_pkcs12(der: &[u8], password: &str) -> Result<ParsedPkcs12_2, Pkcs12Error> {
    let pkcs12 = Pkcs12::from_der(der).map_err(Pkcs12Error::InvalidEncoding)?;
    match pkcs12.parse2(password) {
        Ok(parsed) => Ok(parsed),
        Err(error)
            if error.errors().iter().any(|entry| {
                entry
                    .reason()
                    .is_some_and(|reason| reason.eq_ignore_ascii_case("unsupported"))
            }) =>
        {
            let _legacy = Provider::try_load(None, "legacy", true)
                .map_err(Pkcs12Error::LegacyProviderUnavailable)?;
            pkcs12
                .parse2(password)
                .map_err(Pkcs12Error::InvalidPasswordOrData)
        }
        Err(error) => Err(Pkcs12Error::InvalidPasswordOrData(error)),
    }
}
