use super::*;
use crate::cms::*;
use lopdf::{dictionary, Document, Object};
use openssl::{
    asn1::{Asn1Integer, Asn1Time},
    bn::BigNum,
    hash::MessageDigest,
    pkey::{PKey, Private},
    rsa::Rsa,
    x509::{
        extension::{BasicConstraints, ExtendedKeyUsage, KeyUsage},
        X509NameBuilder, X509,
    },
};
use std::io::Cursor;
use std::sync::{
    atomic::{AtomicUsize, Ordering},
    Arc,
};

fn key() -> PKey<Private> {
    PKey::from_rsa(Rsa::generate(2048).unwrap()).unwrap()
}
fn cert(
    key: &PKey<Private>,
    issuer: Option<(&X509, &PKey<Private>)>,
    serial: u32,
    kind: &str,
) -> X509 {
    let mut name = X509NameBuilder::new().unwrap();
    name.append_entry_by_text("CN", &format!("Offline Rust parity test {kind}"))
        .unwrap();
    let name = name.build();
    let mut b = X509::builder().unwrap();
    b.set_version(2).unwrap();
    b.set_serial_number(&Asn1Integer::from_bn(&BigNum::from_u32(serial).unwrap()).unwrap())
        .unwrap();
    b.set_subject_name(&name).unwrap();
    b.set_issuer_name(issuer.map(|(c, _)| c.subject_name()).unwrap_or(&name))
        .unwrap();
    b.set_pubkey(key).unwrap();
    b.set_not_before(&Asn1Time::days_from_now(0).unwrap())
        .unwrap();
    b.set_not_after(&Asn1Time::days_from_now(2).unwrap())
        .unwrap();
    let mut basic = BasicConstraints::new();
    basic.critical();
    if kind == "root" || kind == "intermediate" {
        basic.ca();
    }
    b.append_extension(basic.build().unwrap()).unwrap();
    let mut ku = KeyUsage::new();
    ku.critical();
    if kind == "root" || kind == "intermediate" {
        ku.key_cert_sign().crl_sign();
    } else {
        ku.digital_signature().non_repudiation();
    }
    b.append_extension(ku.build().unwrap()).unwrap();
    if kind != "root" && kind != "intermediate" {
        let mut eku = ExtendedKeyUsage::new();
        if kind == "tsa" {
            eku.critical().time_stamping();
        } else {
            eku.other("1.3.6.1.5.5.7.3.36");
        }
        b.append_extension(eku.build().unwrap()).unwrap();
    }
    b.sign(
        issuer.map(|(_, k)| k).unwrap_or(key),
        MessageDigest::sha256(),
    )
    .unwrap();
    b.build()
}
struct LocalTsa {
    identity: DocumentIdentity,
    count: AtomicUsize,
}
impl TimestampClient for LocalTsa {
    fn require_ready_configuration(&self) -> Result<()> {
        Ok(())
    }
    fn timestamp(&self, sig: &[u8]) -> Result<Vec<u8>> {
        self.count.fetch_add(1, Ordering::SeqCst);
        cms::test_timestamp_token(&self.identity, sig, None)
    }
}
fn identity_and_tsa() -> (DocumentIdentity, Arc<LocalTsa>, TrustConfig) {
    let root_key = key();
    let root = cert(&root_key, None, 1, "root");
    let leaf_key = key();
    let leaf = cert(&leaf_key, Some((&root, &root_key)), 2, "document");
    let tsa_key = key();
    let tsa_cert = cert(&tsa_key, None, 3, "tsa");
    let trust = TrustConfig::from_csv(
        &sha256_hex(&root.to_der().unwrap()),
        &sha256_hex(&tsa_cert.to_der().unwrap()),
    )
    .unwrap();
    (
        DocumentIdentity {
            key: leaf_key,
            chain: vec![leaf, root],
        },
        Arc::new(LocalTsa {
            identity: DocumentIdentity {
                key: tsa_key,
                chain: vec![tsa_cert],
            },
            count: AtomicUsize::new(0),
        }),
        trust,
    )
}
fn source(rotations: &[i64]) -> Vec<u8> {
    let mut d = Document::with_version("1.7");
    let pages = d.new_object_id();
    let mut kids = Vec::new();
    for rotation in rotations {
        let page=d.add_object(dictionary!{"Type"=>"Page","Parent"=>pages,"MediaBox"=>vec![0.into(),0.into(),600.into(),800.into()],"CropBox"=>vec![20.into(),30.into(),580.into(),770.into()],"Rotate"=>*rotation,"UserUnit"=>2,"Resources"=>dictionary!{}});
        kids.push(Object::Reference(page));
    }
    d.set_object(
        pages,
        dictionary! {"Type"=>"Pages","Kids"=>kids,"Count"=>rotations.len()as i64},
    );
    let catalog = d.add_object(dictionary! {"Type"=>"Catalog","Pages"=>pages});
    d.trailer.set("Root", catalog);
    let mut bytes = Vec::new();
    d.save_to(&mut bytes).unwrap();
    bytes
}
fn plan(name: &str, page: i32) -> FieldPlan {
    FieldPlan {
        field_name: name.into(),
        page_index: page,
        rectangle: NormalizedRectangle {
            x: "0.1".into(),
            y: "0.2".into(),
            width: "0.3".into(),
            height: "0.1".into(),
        },
        deferred: false,
    }
}
fn command(name: &str, role: &str) -> SignCommand {
    SignCommand {
        field_name: name.into(),
        signature_role: role.into(),
        signer_name: "Offline normal document signer".into(),
        reason: Some("Normal interoperability test".into()),
        location: None,
        contact: None,
    }
}
fn appearance() -> Vec<u8> {
    let image = image::RgbaImage::from_pixel(60, 20, image::Rgba([30, 80, 160, 180]));
    let mut b = Cursor::new(Vec::new());
    image::DynamicImage::ImageRgba8(image)
        .write_to(&mut b, image::ImageFormat::Png)
        .unwrap();
    b.into_inner()
}

#[test]
fn geometry_preparation_and_business_validation() {
    let pdf = source(&[0, 90, 180, 270]);
    let plans: Vec<_> = (0..4).map(|i| plan(&format!("field_{i}"), i)).collect();
    let bytes = prepare_fields(&pdf, &plans).unwrap();
    let info = inspect(&bytes).unwrap();
    assert_eq!(info.page_count, 4);
    assert_eq!(info.signature_count, 0);
    for (i, f) in info.fields.iter().enumerate() {
        assert!(f.self_only_lock);
        assert!(!f.signed);
        assert!(f.object_ref.is_some());
        assert_eq!(f.widget_count, 1);
        let w = &f.widgets[0];
        assert_eq!(w.page_index, i);
        assert!(w.object_ref.is_some());
        assert_eq!(w.appearance_object_refs.len(), 1);
        assert_eq!(
            w.normalized_rectangle,
            NormalizedRectangle {
                x: "0.100000".into(),
                y: "0.200000".into(),
                width: "0.300000".into(),
                height: "0.100000".into()
            }
        );
        assert_eq!(info.pages[i].user_unit, "2");
    }
    assert_eq!(finalize_unsigned(&bytes).unwrap(), bytes);
    assert!(prepare_fields(&pdf, &[]).is_err());
    assert!(prepare_fields(&pdf, &[plan("same", 0), plan("same", 1)])
        .unwrap_err()
        .to_string()
        .contains("exactly one widget"));
    assert!(validate_sign_target(&bytes, &command("field_0", "approval")).is_err());
    assert!(validate_sign_target(&bytes, &command("missing", "certification_p2")).is_err());
}

#[test]
fn genuine_certification_and_approval_round_trip() {
    let (identity, tsa, trust) = identity_and_tsa();
    let service = SignatureService::new(identity, tsa.clone(), trust.clone());
    let bytes = prepare_fields(&source(&[0, 90]), &[plan("first", 0), plan("second", 1)]).unwrap();
    let app = appearance();
    let preflight = service
        .preflight(&bytes, &app, &command("first", "certification_p2"), None)
        .unwrap();
    assert_eq!(tsa.count.load(Ordering::SeqCst), 0);
    assert_eq!(preflight.source_sha256(), sha256_hex(&bytes));
    let first = service.sign_preflighted(preflight).unwrap();
    assert!(first.starts_with(&bytes));
    let report = service.verify(&first);
    assert_eq!(report.document_current_state, "valid", "{report:?}");
    let second = service
        .sign_preflighted(
            service
                .preflight(&first, &app, &command("second", "approval"), None)
                .unwrap(),
        )
        .unwrap();
    assert!(second.starts_with(&first));
    if let Ok(directory) = std::env::var("LIMS_SIGNATURE_FIXTURE_DIR") {
        let path = std::path::Path::new(&directory);
        std::fs::create_dir_all(path).unwrap();
        std::fs::write(path.join("prepared.pdf"), &bytes).unwrap();
        std::fs::write(path.join("rust-first.pdf"), &first).unwrap();
        std::fs::write(path.join("rust-second.pdf"), &second).unwrap();
        std::fs::write(path.join("appearance.png"), &app).unwrap();
        std::fs::write(path.join("trust.json"),serde_json::to_vec_pretty(&serde_json::json!({"documentRoots":trust.document_roots,"tsaCertificates":trust.tsa_certificates})).unwrap()).unwrap();
    }
    let report = verify(&second, &trust);
    assert_eq!(report.document_current_state, "valid", "{report:?}");
    assert_eq!(report.signatures.len(), 2);
    assert_eq!(tsa.count.load(Ordering::SeqCst), 2);
    assert!(service
        .preflight(&second, &app, &command("first", "approval"), None)
        .unwrap_err_string()
        .contains("already signed"));
    assert!(prepare_fields(&first, &[plan("third", 0)]).is_err());
    assert!(finalize_unsigned(&first).is_err());
    let unknown = verify(&second, &TrustConfig::default());
    assert_eq!(
        unknown.document_current_state, "indeterminate",
        "{unknown:?}"
    );
    // Independent OpenSSL CMS verification of each genuine detached revision.
    let temp = tempfile::tempdir().unwrap();
    for (index, (_, sig)) in pdf::signatures(&Document::load_mem(&second).unwrap())
        .unwrap()
        .iter()
        .enumerate()
    {
        let range = service::byte_range(sig, second.len()).unwrap();
        let padded = hex::decode(&second[range[1] + 1..range[2] - 1]).unwrap();
        let cms = cms::cms_unpadded(&padded).unwrap();
        let mut content = second[..range[1]].to_vec();
        content.extend_from_slice(&second[range[2]..range[2] + range[3]]);
        std::fs::write(temp.path().join("signature.der"), cms).unwrap();
        std::fs::write(temp.path().join("content.bin"), content).unwrap();
        let (signature_value, timestamp) = cms::test_timestamp_parts(cms).unwrap();
        std::fs::write(temp.path().join("timestamp.der"), timestamp).unwrap();
        std::fs::write(temp.path().join("signature.bin"), signature_value).unwrap();
        std::fs::write(
            temp.path().join("tsa.pem"),
            tsa.identity.chain[0].to_pem().unwrap(),
        )
        .unwrap();
        let ts_output = std::process::Command::new("openssl")
            .args([
                "ts",
                "-verify",
                "-token_in",
                "-in",
                "timestamp.der",
                "-data",
                "signature.bin",
                "-CAfile",
                "tsa.pem",
            ])
            .current_dir(temp.path())
            .output()
            .unwrap();
        assert!(
            ts_output.status.success(),
            "RFC 3161 revision {index}: {}",
            String::from_utf8_lossy(&ts_output.stderr)
        );

        let output = std::process::Command::new("openssl")
            .args([
                "cms",
                "-verify",
                "-binary",
                "-inform",
                "DER",
                "-in",
                "signature.der",
                "-content",
                "content.bin",
                "-noverify",
                "-out",
                "verified.bin",
            ])
            .current_dir(temp.path())
            .output()
            .unwrap();
        assert!(
            output.status.success(),
            "revision {index}: {}",
            String::from_utf8_lossy(&output.stderr)
        );
    }
}
trait ErrorString {
    fn unwrap_err_string(self) -> String;
}
impl<T> ErrorString for Result<T> {
    fn unwrap_err_string(self) -> String {
        match self {
            Ok(_) => panic!("Expected error"),
            Err(e) => e.to_string(),
        }
    }
}

#[test]
fn preflight_configuration_is_before_key_or_timestamp() {
    let (identity, tsa, trust) = identity_and_tsa();
    let service = SignatureService::new(identity, tsa.clone(), trust);
    let bytes = prepare_fields(&source(&[0]), &[plan("first", 0)]).unwrap();
    assert!(service
        .preflight(
            &bytes,
            &appearance(),
            &command("first", "certification_p2"),
            Some(&"00".repeat(32))
        )
        .is_err());
    assert!(service
        .preflight(&bytes, b"", &command("first", "certification_p2"), None)
        .is_err());
    assert_eq!(tsa.count.load(Ordering::SeqCst), 0);
}

struct RequestTsa {
    identity: DocumentIdentity,
}
impl TimestampTransport for RequestTsa {
    fn post_timestamp_query(&self, request: &[u8]) -> Result<Vec<u8>> {
        cms::test_timestamp_response(&self.identity, request)
    }
}
#[test]
fn rfc3161_request_response_nonce_and_policy_normal_interop() {
    let (identity, tsa, trust) = identity_and_tsa();
    let client = Rfc3161Client::new(
        Arc::new(RequestTsa {
            identity: tsa.identity.clone(),
        }),
        trust.clone(),
        Some("1.3.6.1.4.1.55555.1".into()),
    )
    .unwrap();
    let service = SignatureService::new(identity, Arc::new(client), trust);
    let prepared = prepare_fields(&source(&[0]), &[plan("first", 0)]).unwrap();
    let signed = service
        .sign_preflighted(
            service
                .preflight(
                    &prepared,
                    &appearance(),
                    &command("first", "certification_p2"),
                    None,
                )
                .unwrap(),
        )
        .unwrap();
    let report = service.verify(&signed);
    assert_eq!(report.document_current_state, "valid", "{report:?}");
}

#[test]
#[ignore = "Requires Java baseline classes and JDK, run with JAVA_INTEROP_CLASSPATH and JAVA_INTEROP_HOME"]
fn java_business_layer_bidirectional_normal_continuation() {
    let cp = std::env::var("JAVA_INTEROP_CLASSPATH").expect("JAVA_INTEROP_CLASSPATH");
    let java_home = std::env::var("JAVA_INTEROP_HOME").expect("JAVA_INTEROP_HOME");
    let (identity, tsa, mut trust) = identity_and_tsa();
    let mut service = SignatureService::new(identity.clone(), tsa.clone(), trust.clone());
    let source = source(&[0, 90]);
    let prepared = prepare_fields(&source, &[plan("first", 0), plan("second", 1)]).unwrap();
    let image = appearance();
    let rust_first = service
        .sign_preflighted(
            service
                .preflight(
                    &prepared,
                    &image,
                    &command("first", "certification_p2"),
                    None,
                )
                .unwrap(),
        )
        .unwrap();
    let temp = tempfile::tempdir().unwrap();
    let dir = temp.path();
    std::fs::write(dir.join("source.pdf"), source).unwrap();
    std::fs::write(dir.join("rust-first.pdf"), &rust_first).unwrap();
    std::fs::write(dir.join("appearance.png"), &image).unwrap();
    let harness = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("tests/java/com/luang/pdfsigner/signature/NormalInterop.java");
    let compilation = std::process::Command::new(format!("{java_home}/bin/javac"))
        .args([
            "-cp",
            &cp,
            "-d",
            dir.to_str().unwrap(),
            harness.to_str().unwrap(),
        ])
        .output()
        .unwrap();
    assert!(
        compilation.status.success(),
        "{}",
        String::from_utf8_lossy(&compilation.stderr)
    );
    let root = trust.document_roots.iter().next().unwrap();
    let ts = trust.tsa_certificates.iter().next().unwrap();
    let output = std::process::Command::new(format!("{java_home}/bin/java"))
        .args([
            "-Djava.awt.headless=true",
            "-cp",
            &format!("{}:{cp}", dir.display()),
            "com.luang.pdfsigner.signature.NormalInterop",
            dir.to_str().unwrap(),
            root,
            ts,
        ])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "stdout={} stderr={}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
    let java_trust: serde_json::Value =
        serde_json::from_slice(&std::fs::read(dir.join("java-trust.json")).unwrap()).unwrap();
    trust
        .document_roots
        .insert(java_trust["root"].as_str().unwrap().into());
    trust
        .tsa_certificates
        .insert(java_trust["tsa"].as_str().unwrap().into());
    let mixed = std::fs::read(dir.join("java-after-rust.pdf")).unwrap();
    assert!(mixed.starts_with(&rust_first));
    let report = verify(&mixed, &trust);
    assert_eq!(
        report.document_current_state, "valid",
        "Java-after-Rust: {report:?}"
    );
    let java_first = std::fs::read(dir.join("java-first.pdf")).unwrap();
    let report = verify(&java_first, &trust);
    assert_eq!(
        report.document_current_state, "valid",
        "Java certification: {report:?}"
    );
    service = SignatureService::new(identity, tsa, trust.clone());
    let rust_after_java = service
        .sign_preflighted(
            service
                .preflight(&java_first, &image, &command("second", "approval"), None)
                .unwrap(),
        )
        .unwrap();
    assert!(rust_after_java.starts_with(&java_first));
    let report = service.verify(&rust_after_java);
    assert_eq!(
        report.document_current_state, "valid",
        "Rust-after-Java: {report:?}"
    );
    // Compare the complete geometry and field/reference DTO shape, not hashes.
    let java_inspect: serde_json::Value =
        serde_json::from_slice(&std::fs::read(dir.join("java-inspect.json")).unwrap()).unwrap();
    let rust_inspect = serde_json::to_value(inspect(&java_first).unwrap()).unwrap();
    assert_eq!(rust_inspect, java_inspect);
    std::fs::write(dir.join("rust-after-java.pdf"), &rust_after_java).unwrap();
    let roots = trust
        .document_roots
        .iter()
        .cloned()
        .collect::<Vec<_>>()
        .join(",");
    let tsas = trust
        .tsa_certificates
        .iter()
        .cloned()
        .collect::<Vec<_>>()
        .join(",");
    let output = std::process::Command::new(format!("{java_home}/bin/java"))
        .args([
            "-Djava.awt.headless=true",
            "-cp",
            &format!("{}:{cp}", dir.display()),
            "com.luang.pdfsigner.signature.NormalInterop",
            "verify",
            dir.join("rust-after-java.pdf").to_str().unwrap(),
            &roots,
            &tsas,
        ])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "Final Java verifier: {}",
        String::from_utf8_lossy(&output.stderr)
    );
}

#[test]
fn ordinary_field_and_rectangle_contracts() {
    let source = source(&[0]);
    for rect in [
        NormalizedRectangle {
            x: "0.8".into(),
            y: "0.2".into(),
            width: "0.3".into(),
            height: "0.1".into(),
        },
        NormalizedRectangle {
            x: "0.1".into(),
            y: "0.2".into(),
            width: "0".into(),
            height: "0.1".into(),
        },
        NormalizedRectangle {
            x: "0.1000001".into(),
            y: "0.2".into(),
            width: "0.3".into(),
            height: "0.1".into(),
        },
    ] {
        let mut p = plan("field", 0);
        p.rectangle = rect;
        assert!(prepare_fields(&source, &[p]).is_err());
    }
    assert!(prepare_fields(&source, &[plan("invalid name", 0)]).is_err());
    assert!(prepare_fields(&source, &[plan("field", 1)]).is_err());
    let mut doc = Document::load_mem(&source).unwrap();
    let text =
        doc.add_object(dictionary! {"FT"=>"Tx","T"=>Object::string_literal("ordinary_text")});
    let form = doc.add_object(dictionary! {"Fields"=>vec![Object::Reference(text)]});
    doc.catalog_mut().unwrap().set("AcroForm", form);
    let mut pdf = Vec::new();
    doc.save_to(&mut pdf).unwrap();
    assert!(prepare_fields(&pdf, &[plan("ordinary_text", 0)])
        .unwrap_err_string()
        .contains("Duplicate signature field name"));
}

#[test]
fn unicode_signer_name_survives_pdf_text_encoding() {
    let (identity, tsa, trust) = identity_and_tsa();
    let service = SignatureService::new(identity, tsa, trust);
    let bytes = prepare_fields(&source(&[270]), &[plan("first", 0)]).unwrap();
    let mut cmd = command("first", "certification_p2");
    cmd.signer_name = "离线签名测试员".into();
    cmd.reason = Some("合规报告签名".into());
    let signed = service
        .sign_preflighted(
            service
                .preflight(&bytes, &appearance(), &cmd, None)
                .unwrap(),
        )
        .unwrap();
    let report = service.verify(&signed);
    assert_eq!(report.document_current_state, "valid", "{report:?}");
    assert_eq!(
        report.signatures[0].signer_name.as_deref(),
        Some("离线签名测试员")
    );
}

#[test]
fn genuine_loopback_http_rfc3161_transport() {
    use std::io::{Read, Write};
    let (identity, tsa, trust) = identity_and_tsa();
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let address = listener.local_addr().unwrap();
    let tsa_identity = tsa.identity.clone();
    let server = std::thread::spawn(move || {
        let (mut stream, _) = listener.accept().unwrap();
        stream
            .set_read_timeout(Some(std::time::Duration::from_secs(10)))
            .unwrap();
        let mut buffer = Vec::new();
        let mut chunk = [0u8; 4096];
        let header_end = loop {
            let count = stream.read(&mut chunk).unwrap();
            assert!(count > 0);
            buffer.extend_from_slice(&chunk[..count]);
            if let Some(end) = buffer.windows(4).position(|s| s == b"\r\n\r\n") {
                break end + 4;
            }
        };
        let headers = String::from_utf8(buffer[..header_end].to_vec())
            .unwrap()
            .to_ascii_lowercase();
        assert!(headers.starts_with("post /tsa http/1.1"));
        assert!(headers.contains("content-type: application/timestamp-query"));
        assert!(headers.contains("accept: application/timestamp-reply"));
        let length: usize = headers
            .lines()
            .find_map(|l| l.strip_prefix("content-length:").map(str::trim))
            .unwrap()
            .parse()
            .unwrap();
        while buffer.len() < header_end + length {
            let count = stream.read(&mut chunk).unwrap();
            assert!(count > 0);
            buffer.extend_from_slice(&chunk[..count]);
        }
        let response =
            cms::test_timestamp_response(&tsa_identity, &buffer[header_end..header_end + length])
                .unwrap();
        write!(stream,"HTTP/1.1 200 OK\r\nContent-Type: application/timestamp-reply\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",response.len()).unwrap();
        stream.write_all(&response).unwrap();
    });
    let transport = HttpTimestampTransport::new(&format!("http://{address}/tsa"), 10).unwrap();
    let client = Rfc3161Client::new(
        Arc::new(transport),
        trust.clone(),
        Some("1.3.6.1.4.1.55555.1".into()),
    )
    .unwrap();
    let service = SignatureService::new(identity, Arc::new(client), trust);
    let prepared = prepare_fields(&source(&[0]), &[plan("first", 0)]).unwrap();
    let signed = service
        .sign_preflighted(
            service
                .preflight(
                    &prepared,
                    &appearance(),
                    &command("first", "certification_p2"),
                    None,
                )
                .unwrap(),
        )
        .unwrap();
    server.join().unwrap();
    let report = service.verify(&signed);
    assert_eq!(report.document_current_state, "valid", "{report:?}");
}

#[test]
fn pkcs12_document_identity_round_trip() {
    let (identity, _, trust) = identity_and_tsa();
    let mut chain = openssl::stack::Stack::new().unwrap();
    chain.push(identity.chain[1].clone()).unwrap();
    let mut builder = openssl::pkcs12::Pkcs12::builder();
    builder
        .name("offline-signer")
        .pkey(&identity.key)
        .cert(&identity.chain[0])
        .ca(chain);
    let pfx = builder
        .build2("ephemeral-test-password")
        .unwrap()
        .to_der()
        .unwrap();
    let loaded = DocumentIdentity::from_pkcs12(&pfx, "ephemeral-test-password").unwrap();
    assert_eq!(
        loaded.validate(&trust).unwrap(),
        identity.certificate_fingerprint().unwrap()
    );
}

#[test]
#[ignore = "Requires Java baseline classes and JDK for normal field/image support oracle"]
fn java_normal_field_tree_and_image_formats() {
    let cp = std::env::var("JAVA_INTEROP_CLASSPATH").expect("JAVA_INTEROP_CLASSPATH");
    let java_home = std::env::var("JAVA_INTEROP_HOME").expect("JAVA_INTEROP_HOME");
    let temp = tempfile::tempdir().unwrap();
    let dir = temp.path();
    let harness = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("tests/java/com/luang/pdfsigner/signature/NormalSupportOracle.java");
    let compile = std::process::Command::new(format!("{java_home}/bin/javac"))
        .args([
            "-cp",
            &cp,
            "-d",
            dir.to_str().unwrap(),
            harness.to_str().unwrap(),
        ])
        .output()
        .unwrap();
    assert!(
        compile.status.success(),
        "{}",
        String::from_utf8_lossy(&compile.stderr)
    );
    let output = std::process::Command::new(format!("{java_home}/bin/java"))
        .args([
            "-Djava.awt.headless=true",
            "-cp",
            &format!("{}:{cp}", dir.display()),
            "com.luang.pdfsigner.signature.NormalSupportOracle",
            dir.to_str().unwrap(),
        ])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    let oracle: serde_json::Value =
        serde_json::from_slice(&std::fs::read(dir.join("support.json")).unwrap()).unwrap();
    let (identity, tsa, mut trust) = identity_and_tsa();
    trust
        .document_roots
        .insert(oracle["root"].as_str().unwrap().into());
    trust
        .tsa_certificates
        .insert(oracle["tsa"].as_str().unwrap().into());
    let service = SignatureService::new(identity, tsa, trust.clone());
    for shape in [
        "separate",
        "fractional_crop",
        "fractional_box",
        "tiny_width",
        "tiny_height",
        "tiny_both",
        "nested",
        "inherited",
        "nested_unicode",
    ] {
        assert_eq!(
            oracle[shape]["verification"]["documentCurrentState"],
            "valid"
        );
        let input = std::fs::read(dir.join(format!("{shape}-prepared.pdf"))).unwrap();
        assert_eq!(
            serde_json::to_value(inspect(&input).unwrap()).unwrap(),
            oracle[shape]["inspection"]
        );
        let target = match shape {
            "nested" | "inherited" => "group.first",
            "nested_unicode" => "审批.签名",
            _ => "first",
        };
        let result = service
            .sign_preflighted(
                service
                    .preflight(
                        &input,
                        &appearance(),
                        &command(target, "certification_p2"),
                        None,
                    )
                    .unwrap(),
            )
            .unwrap();
        assert!(result.starts_with(&input));
        let signed_document = Document::load_mem(&result).unwrap();
        let target_field = pdf::fields(&signed_document)
            .unwrap()
            .into_iter()
            .find(|field| field.name == target)
            .unwrap();
        let widget = pdf::dict(&signed_document, &target_field.widgets[0]).unwrap();
        let ap = pdf::dict(&signed_document, widget.get(b"AP").unwrap()).unwrap();
        let stream = pdf::resolve(&signed_document, ap.get(b"N").unwrap())
            .unwrap()
            .as_stream()
            .unwrap();
        let bbox = stream.dict.get(b"BBox").unwrap().as_array().unwrap();
        let expected = &oracle[shape]["appearanceBox"];
        assert_eq!(
            pdf::number(&bbox[0]).unwrap(),
            expected[0].as_f64().unwrap()
        );
        assert_eq!(
            pdf::number(&bbox[1]).unwrap(),
            expected[1].as_f64().unwrap()
        );
        assert_eq!(
            pdf::number(&bbox[2]).unwrap() - pdf::number(&bbox[0]).unwrap(),
            expected[2].as_f64().unwrap()
        );
        assert_eq!(
            pdf::number(&bbox[3]).unwrap() - pdf::number(&bbox[1]).unwrap(),
            expected[3].as_f64().unwrap()
        );
        let report = service.verify(&result);
        assert_eq!(
            report.document_current_state, "valid",
            "{shape}: {report:?}"
        );
        let java_result = std::fs::read(dir.join(format!("{shape}-signed.pdf"))).unwrap();
        let report = service.verify(&java_result);
        assert_eq!(
            report.document_current_state, "valid",
            "Java {shape}: {report:?}"
        );
    }
    // The Java signer accepts these shapes, but its own unchanged permission
    // validator rejects the result. Preserve those verdicts, never loosen them.
    for (shape, code) in [
        ("merged", "TARGET_FIELD_CHANGED_OUTSIDE_SIGNATURE_VALUE"),
        (
            "direct_field",
            "ACROFORM_CHANGED_OUTSIDE_SIGNATURE_RESOURCES",
        ),
    ] {
        assert_eq!(oracle[shape]["verification"]["error"], code);
        let input = std::fs::read(dir.join(format!("{shape}-prepared.pdf"))).unwrap();
        assert_eq!(
            serde_json::to_value(inspect(&input).unwrap()).unwrap(),
            oracle[shape]["inspection"]
        );
        let signed = std::fs::read(dir.join(format!("{shape}-signed.pdf"))).unwrap();
        assert_eq!(service.verify(&signed).error.as_deref(), Some(code));
        let rust_signed = service
            .sign_preflighted(
                service
                    .preflight(
                        &input,
                        &appearance(),
                        &command("first", "certification_p2"),
                        None,
                    )
                    .unwrap(),
            )
            .unwrap();
        assert_eq!(
            service.verify(&rust_signed).error.as_deref(),
            Some(code),
            "Rust {shape} must retain the Java permission verdict"
        );
    }
    let direct_widget = std::fs::read(dir.join("direct_widget-prepared.pdf")).unwrap();
    assert!(inspect(&direct_widget)
        .unwrap_err_string()
        .contains("not attached"));
    let prepared = prepare_fields(&source(&[0]), &[plan("first", 0)]).unwrap();
    for format in ["png", "jpg", "bmp", "gif", "tiff", "wbmp"] {
        assert_eq!(
            oracle["formats"][format]["verification"]["documentCurrentState"],
            "valid"
        );
        let image = std::fs::read(dir.join(format!("appearance.{format}"))).unwrap();
        let decoded = crate::appearance::decode(&image).unwrap();
        assert_eq!(decoded.dimensions(), (80, 30));
        let signed = service
            .sign_preflighted(
                service
                    .preflight(
                        &prepared,
                        &image,
                        &command("first", "certification_p2"),
                        None,
                    )
                    .unwrap(),
            )
            .unwrap();
        let report = service.verify(&signed);
        assert_eq!(
            report.document_current_state, "valid",
            "{format}: {report:?}"
        );
    }
}

#[test]
fn wbmp_normal_monochrome_appearance() {
    let image = crate::appearance::decode(&[0, 0, 8, 2, 0b11110000, 0b00001111]).unwrap();
    assert_eq!(image.dimensions(), (8, 2));
    assert_eq!(image.get_pixel(0, 0).0, [255, 255, 255, 255]);
    assert_eq!(image.get_pixel(7, 0).0, [0, 0, 0, 255]);
    assert_eq!(image.get_pixel(7, 1).0, [255, 255, 255, 255]);
}

#[test]
fn normal_intermediate_certificate_chain_policy() {
    let root_key = key();
    let root = cert(&root_key, None, 11, "root");
    let intermediate_key = key();
    let intermediate = cert(
        &intermediate_key,
        Some((&root, &root_key)),
        12,
        "intermediate",
    );
    let leaf_key = key();
    let leaf = cert(
        &leaf_key,
        Some((&intermediate, &intermediate_key)),
        13,
        "document",
    );
    let identity = DocumentIdentity {
        key: leaf_key,
        chain: vec![leaf, intermediate, root.clone()],
    };
    let trust = TrustConfig::from_csv(&sha256_hex(&root.to_der().unwrap()), "").unwrap();
    assert_eq!(
        identity.validate(&trust).unwrap(),
        identity.certificate_fingerprint().unwrap()
    );
}

#[test]
#[ignore = "Requires Java baseline classes and JDK for normal intermediate-chain oracle"]
fn java_normal_intermediate_chain_semantics() {
    let cp = std::env::var("JAVA_INTEROP_CLASSPATH").expect("JAVA_INTEROP_CLASSPATH");
    let java_home = std::env::var("JAVA_INTEROP_HOME").expect("JAVA_INTEROP_HOME");
    let root_key = key();
    let root = cert(&root_key, None, 21, "root");
    let intermediate_key = key();
    let intermediate = cert(
        &intermediate_key,
        Some((&root, &root_key)),
        22,
        "intermediate",
    );
    let leaf_key = key();
    let leaf = cert(
        &leaf_key,
        Some((&intermediate, &intermediate_key)),
        23,
        "document",
    );
    let identity = DocumentIdentity {
        key: leaf_key,
        chain: vec![leaf, intermediate, root.clone()],
    };
    let mut chain = openssl::stack::Stack::new().unwrap();
    chain.push(identity.chain[1].clone()).unwrap();
    chain.push(root.clone()).unwrap();
    let mut builder = openssl::pkcs12::Pkcs12::builder();
    builder
        .name("normal-chain")
        .pkey(&identity.key)
        .cert(&identity.chain[0])
        .ca(chain);
    let temp = tempfile::tempdir().unwrap();
    let dir = temp.path();
    std::fs::write(
        dir.join("chain.p12"),
        builder
            .build2("ephemeral-test-password")
            .unwrap()
            .to_der()
            .unwrap(),
    )
    .unwrap();
    std::fs::write(dir.join("appearance.png"), appearance()).unwrap();
    let root_fingerprint = sha256_hex(&root.to_der().unwrap());
    let mut trust = TrustConfig::from_csv(&root_fingerprint, "").unwrap();
    assert_eq!(
        identity.validate(&trust).unwrap(),
        identity.certificate_fingerprint().unwrap()
    );
    let harness = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("tests/java/com/luang/pdfsigner/signature/NormalChainOracle.java");
    let compile = std::process::Command::new(format!("{java_home}/bin/javac"))
        .args([
            "-cp",
            &cp,
            "-d",
            dir.to_str().unwrap(),
            harness.to_str().unwrap(),
        ])
        .output()
        .unwrap();
    assert!(
        compile.status.success(),
        "{}",
        String::from_utf8_lossy(&compile.stderr)
    );
    let output = std::process::Command::new(format!("{java_home}/bin/java"))
        .args([
            "-Djava.awt.headless=true",
            "-cp",
            &format!("{}:{cp}", dir.display()),
            "com.luang.pdfsigner.signature.NormalChainOracle",
            dir.to_str().unwrap(),
            &root_fingerprint,
        ])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    let oracle: serde_json::Value =
        serde_json::from_slice(&std::fs::read(dir.join("chain.json")).unwrap()).unwrap();
    assert_eq!(oracle["chainLength"], 3);
    assert_eq!(
        oracle["preflightFingerprint"],
        identity.certificate_fingerprint().unwrap()
    );
    trust
        .tsa_certificates
        .insert(oracle["tsa"].as_str().unwrap().into());
    let pdf = std::fs::read(dir.join("java-chain.pdf")).unwrap();
    let report = verify(&pdf, &trust);
    assert_eq!(oracle["verification"]["documentCurrentState"], "invalid");
    assert_eq!(report.document_current_state, "invalid");
    assert_eq!(
        oracle["verification"]["signatures"][0]["cmsIntegrity"],
        report.signatures[0].cms_integrity
    );
}

fn network_condition(mode: &str) -> (String, Option<std::thread::JoinHandle<()>>) {
    use std::io::{Read, Write};
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let address = listener.local_addr().unwrap();
    if mode == "refused" {
        drop(listener);
        return (format!("http://{address}/tsa"), None);
    }
    let tls = if mode == "tls_untrusted" {
        let key = key();
        let certificate = cert(&key, None, 90, "tsa");
        let mut builder =
            openssl::ssl::SslAcceptor::mozilla_intermediate(openssl::ssl::SslMethod::tls())
                .unwrap();
        builder.set_private_key(&key).unwrap();
        builder.set_certificate(&certificate).unwrap();
        Some(builder.build())
    } else {
        None
    };
    let scheme = if tls.is_some() { "https" } else { "http" };
    let url = format!("{scheme}://{address}/tsa");
    let mode = mode.to_string();
    let handle = std::thread::spawn(move || {
        let (mut stream, _) = listener.accept().unwrap();
        stream
            .set_read_timeout(Some(std::time::Duration::from_secs(5)))
            .unwrap();
        if let Some(tls) = tls {
            let _ = tls.accept(stream);
            return;
        }
        let mut bytes = Vec::new();
        let mut buf = [0u8; 4096];
        let header_end = loop {
            let n = stream.read(&mut buf).unwrap();
            if n == 0 {
                return;
            }
            bytes.extend_from_slice(&buf[..n]);
            if let Some(i) = bytes.windows(4).position(|p| p == b"\r\n\r\n") {
                break i + 4;
            }
        };
        let headers = String::from_utf8_lossy(&bytes[..header_end]).to_ascii_lowercase();
        let length: usize = headers
            .lines()
            .find_map(|l| l.strip_prefix("content-length:").map(str::trim))
            .unwrap()
            .parse()
            .unwrap();
        while bytes.len() < header_end + length {
            let n = stream.read(&mut buf).unwrap();
            if n == 0 {
                return;
            }
            bytes.extend_from_slice(&buf[..n]);
        }
        match mode.as_str() {
            "status_503" => {
                stream.write_all(b"HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nConnection: close\r\n\r\n").unwrap();
            }
            "timeout" => std::thread::sleep(std::time::Duration::from_millis(1600)),
            "closed" => {}
            "connection_reset" => {
                socket2::SockRef::from(&stream)
                    .set_linger(Some(std::time::Duration::ZERO))
                    .unwrap();
            }
            "body_delay" => {
                stream.write_all(b"HTTP/1.1 200 OK\r\nContent-Type: application/timestamp-reply\r\nContent-Length: 100\r\nConnection: close\r\n\r\n").unwrap();
                std::thread::sleep(std::time::Duration::from_millis(1600));
            }
            "truncated_body" => {
                stream.write_all(b"HTTP/1.1 200 OK\r\nContent-Type: application/timestamp-reply\r\nContent-Length: 100\r\nConnection: close\r\n\r\n").unwrap();
            }
            _ => panic!("Unknown network condition"),
        }
    });
    (url, Some(handle))
}

#[test]
#[ignore = "Requires Java baseline classes and JDK for normal TSA network-error oracle"]
fn java_loopback_tsa_network_error_classification() {
    let cp = std::env::var("JAVA_INTEROP_CLASSPATH").expect("JAVA_INTEROP_CLASSPATH");
    let java_home = std::env::var("JAVA_INTEROP_HOME").expect("JAVA_INTEROP_HOME");
    let temp = tempfile::tempdir().unwrap();
    let dir = temp.path();
    let harness = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("tests/java/com/luang/pdfsigner/signature/TimestampNetworkOracle.java");
    let compile = std::process::Command::new(format!("{java_home}/bin/javac"))
        .args([
            "-cp",
            &cp,
            "-d",
            dir.to_str().unwrap(),
            harness.to_str().unwrap(),
        ])
        .output()
        .unwrap();
    assert!(
        compile.status.success(),
        "{}",
        String::from_utf8_lossy(&compile.stderr)
    );
    let (identity, _, trust) = identity_and_tsa();
    let attributes = cms::signed_attributes(
        b"Normal transport classification document",
        &identity.chain[0],
        "1.2.840.113549.1.7.1",
    )
    .unwrap();
    let signature = cms::sign_attributes(&attributes, &identity).unwrap();
    std::fs::write(dir.join("signature.bin"), &signature).unwrap();
    let fingerprint = trust.tsa_certificates.iter().next().unwrap();
    let mut differences = Vec::new();
    for mode in [
        "status_503",
        "timeout",
        "refused",
        "closed",
        "truncated_body",
        "tls_untrusted",
        "connection_reset",
        "body_delay",
    ] {
        let (url, server) = network_condition(mode);
        let client = Rfc3161Client::new(
            Arc::new(HttpTimestampTransport::new(&url, 1).unwrap()),
            trust.clone(),
            None,
        )
        .unwrap();
        let error = client.timestamp(&signature).unwrap_err();
        if let Some(server) = server {
            server.join().unwrap();
        }
        let (url, server) = network_condition(mode);
        let output = std::process::Command::new(format!("{java_home}/bin/java"))
            .args([
                "-cp",
                &format!("{}:{cp}", dir.display()),
                "com.luang.pdfsigner.signature.TimestampNetworkOracle",
                &url,
                fingerprint,
                dir.join("signature.bin").to_str().unwrap(),
            ])
            .output()
            .unwrap();
        if let Some(server) = server {
            server.join().unwrap();
        }
        assert!(
            output.status.success(),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
        let stdout = String::from_utf8_lossy(&output.stdout);
        let json = stdout
            .lines()
            .rev()
            .find(|line| line.starts_with('{'))
            .unwrap();
        let oracle: serde_json::Value = serde_json::from_str(json).unwrap();
        let java_uncertain = oracle["uncertain"].as_bool().unwrap();
        eprintln!(
            "TSA {mode}: Java uncertain={java_uncertain} causes={} Rust uncertain={} {error}",
            oracle["causes"],
            error.outcome_uncertain()
        );
        if error.outcome_uncertain() != java_uncertain {
            differences.push(mode);
        }
    }
    assert!(
        differences.is_empty(),
        "Network classification mismatches: {differences:?}"
    );
}

#[test]
fn normal_coordinate_role_and_reservation_boundaries() {
    let input = source(&[360, -90]);
    let full = FieldPlan {
        field_name: "f".repeat(128),
        page_index: 0,
        rectangle: NormalizedRectangle {
            x: "0".into(),
            y: "0".into(),
            width: "1".into(),
            height: "1".into(),
        },
        deferred: true,
    };
    let mut edge = plan("edge", 1);
    edge.rectangle = NormalizedRectangle {
        x: "0.999999".into(),
        y: "0.999999".into(),
        width: "0.000001".into(),
        height: "0.000001".into(),
    };
    let prepared = prepare_fields(&input, &[full.clone(), edge]).unwrap();
    let info = inspect(&prepared).unwrap();
    assert_eq!(info.pages[0].rotation, 0);
    assert_eq!(info.pages[1].rotation, 270);
    assert_eq!(
        info.fields[0].widgets[0].normalized_rectangle,
        NormalizedRectangle {
            x: "0.000000".into(),
            y: "0.000000".into(),
            width: "1.000000".into(),
            height: "1.000000".into()
        }
    );
    let (identity, tsa, trust) = identity_and_tsa();
    let service = SignatureService::new(identity, tsa, trust);
    assert!(service.clone().with_reserved_size(16383).is_err());
    assert!(service.clone().with_reserved_size(1024 * 1024 + 1).is_err());
    let service = service.with_reserved_size(16384).unwrap();
    let signed = service
        .sign_preflighted(
            service
                .preflight(
                    &prepared,
                    &appearance(),
                    &command(&full.field_name, "CERTIFICATION_P2"),
                    None,
                )
                .unwrap(),
        )
        .unwrap();
    assert_eq!(service.verify(&signed).document_current_state, "valid");
    let too_long = FieldPlan {
        field_name: "f".repeat(129),
        ..full
    };
    assert!(prepare_fields(&input, &[too_long]).is_err());
    assert!(prepare_fields(&source(&[45]), &[plan("field", 0)]).is_err());
}

#[test]
fn environment_backed_identity_rotation_is_rechecked() {
    fn pfx(identity: &DocumentIdentity) -> Vec<u8> {
        let mut chain = openssl::stack::Stack::new().unwrap();
        for cert in identity.chain.iter().skip(1) {
            chain.push(cert.clone()).unwrap();
        }
        let mut builder = openssl::pkcs12::Pkcs12::builder();
        builder
            .name("offline-rotation")
            .pkey(&identity.key)
            .cert(&identity.chain[0])
            .ca(chain);
        builder
            .build2("ephemeral-test-password")
            .unwrap()
            .to_der()
            .unwrap()
    }
    let (first, _, first_trust) = identity_and_tsa();
    let (second, _, second_trust) = identity_and_tsa();
    let temp = tempfile::tempdir().unwrap();
    let dir = temp.path();
    std::fs::write(dir.join("active.p12"), pfx(&first)).unwrap();
    std::fs::write(dir.join("rotated.p12"), pfx(&second)).unwrap();
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    listener.set_nonblocking(true).unwrap();
    let address = listener.local_addr().unwrap();
    let roots = first_trust
        .document_roots
        .union(&second_trust.document_roots)
        .cloned()
        .collect::<Vec<_>>()
        .join(",");
    // A separate test process avoids mutating process-global environment while
    // other offline tests are concurrently running. Only test identities enter it.
    let output = std::process::Command::new(std::env::current_exe().unwrap())
        .args([
            "--exact",
            "tests::environment_rotation_child",
            "--ignored",
            "--nocapture",
        ])
        .env("LIMS_ROTATION_TEST_CHILD", "1")
        .env("DEFAULT_PFX_PATH", dir.join("active.p12"))
        .env("LIMS_ROTATED_PFX_PATH", dir.join("rotated.p12"))
        .env("DEFAULT_PFX_PASS", "ephemeral-test-password")
        .env("PDF_PADES_SIGNER_TRUSTED_ROOT_SHA256", roots)
        .env(
            "PDF_PADES_TSA_TRUSTED_CERT_SHA256",
            first_trust.tsa_certificates.iter().next().unwrap(),
        )
        .env("PDF_PADES_TSA_URL", format!("http://{address}/tsa"))
        .env("PDF_PADES_TSA_POLICY_OID", "")
        .env("PDF_PADES_TSA_TIMEOUT_SECONDS", "1")
        .env("SIGNATURE_RESERVED_SIZE", "32768")
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{} {}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
    assert!(matches!(listener.accept(),Err(e) if e.kind()==std::io::ErrorKind::WouldBlock));
}

#[test]
#[ignore = "Internal child invoked by environment_backed_identity_rotation_is_rechecked"]
fn environment_rotation_child() {
    assert_eq!(
        std::env::var("LIMS_ROTATION_TEST_CHILD").as_deref(),
        Ok("1")
    );
    let service = SignatureService::from_env().unwrap();
    let prepared = prepare_fields(&source(&[0]), &[plan("first", 0)]).unwrap();
    let operation = service
        .preflight(
            &prepared,
            &appearance(),
            &command("first", "certification_p2"),
            None,
        )
        .unwrap();
    std::fs::copy(
        std::env::var("LIMS_ROTATED_PFX_PATH").unwrap(),
        std::env::var("DEFAULT_PFX_PATH").unwrap(),
    )
    .unwrap();
    assert!(service
        .sign_preflighted(operation)
        .unwrap_err_string()
        .contains("does not match the frozen operation"));
}

/// Explicit integration fixture service, not part of the default test suite.
/// Generates only short-lived offline keys in the caller's temporary directory.
#[test]
#[ignore = "Manual loopback TSA fixture service; requires LIMS_OFFLINE_TSA_DIR"]
fn offline_fixture_http_authority() {
    use std::io::{Read, Write};
    let directory = std::env::var("NORMAL_TSA_FIXTURE_DIR")
        .or_else(|_| std::env::var("LIMS_OFFLINE_TSA_DIR"))
        .expect("Set LIMS_OFFLINE_TSA_DIR to a fresh temporary directory");
    let directory = std::path::PathBuf::from(directory);
    std::fs::create_dir_all(&directory).unwrap();
    assert!(
        !directory.join("doc-signer.p12").exists(),
        "Use a fresh offline fixture directory"
    );
    let (identity, tsa, trust) = identity_and_tsa();
    let mut chain = openssl::stack::Stack::new().unwrap();
    for certificate in identity.chain.iter().skip(1) {
        chain.push(certificate.clone()).unwrap();
    }
    let mut builder = openssl::pkcs12::Pkcs12::builder();
    builder
        .name("offline-signer")
        .pkey(&identity.key)
        .cert(&identity.chain[0])
        .ca(chain);
    let mut random_password = [0u8; 24];
    openssl::rand::rand_bytes(&mut random_password).unwrap();
    let password = hex::encode(random_password);
    let pfx = builder.build2(&password).unwrap().to_der().unwrap();
    let path = directory.join("doc-signer.p12");
    let mut options = std::fs::OpenOptions::new();
    options.write(true).create_new(true);
    #[cfg(unix)]
    {
        use std::os::unix::fs::OpenOptionsExt;
        options.mode(0o600);
    }
    options.open(&path).unwrap().write_all(&pfx).unwrap();
    let source = source(&[0, 90]);
    let prepared = prepare_fields(&source, &[plan("first", 0), plan("second", 1)]).unwrap();
    let appearance = appearance();
    std::fs::write(directory.join("source.pdf"), source).unwrap();
    std::fs::write(directory.join("prepared.pdf"), &prepared).unwrap();
    std::fs::write(directory.join("appearance.png"), &appearance).unwrap();
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    listener.set_nonblocking(true).unwrap();
    let url = format!("http://{}/tsa", listener.local_addr().unwrap());
    let settings = serde_json::json!({"DEFAULT_PFX_PATH":path,"DEFAULT_PFX_PASS":password,"PDF_PADES_SIGNER_TRUSTED_ROOT_SHA256":trust.document_roots.iter().next().unwrap(),"PDF_PADES_TSA_TRUSTED_CERT_SHA256":trust.tsa_certificates.iter().next().unwrap(),"PDF_PADES_TSA_URL":url,"PDF_PADES_TSA_POLICY_OID":"1.3.6.1.4.1.55555.1","PDF_PADES_TSA_TIMEOUT_SECONDS":"10","expectedCertificateFingerprint":identity.certificate_fingerprint().unwrap(),"preparedSha256":sha256_hex(&prepared),"appearanceSha256":sha256_hex(&appearance)});
    std::fs::write(
        directory.join("ready.json.tmp"),
        serde_json::to_vec_pretty(&settings).unwrap(),
    )
    .unwrap();
    std::fs::rename(
        directory.join("ready.json.tmp"),
        directory.join("ready.json"),
    )
    .unwrap();
    std::fs::write(
        directory.join("env.json"),
        serde_json::to_vec_pretty(&settings).unwrap(),
    )
    .unwrap();
    std::fs::write(directory.join("request-count.txt"), "0").unwrap();
    let mut count = 0;
    while !directory.join("stop").exists() {
        let (mut stream, _) = match listener.accept() {
            Ok(client) => client,
            Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                std::thread::sleep(std::time::Duration::from_millis(30));
                continue;
            }
            Err(error) => panic!("Offline TSA accept failed: {error}"),
        };
        stream
            .set_read_timeout(Some(std::time::Duration::from_secs(5)))
            .unwrap();
        let mut buffer = Vec::new();
        let mut chunk = [0u8; 4096];
        let header_end = loop {
            let n = stream.read(&mut chunk).unwrap();
            assert!(n > 0);
            buffer.extend_from_slice(&chunk[..n]);
            assert!(buffer.len() < 65536);
            if let Some(i) = buffer.windows(4).position(|w| w == b"\r\n\r\n") {
                break i + 4;
            }
        };
        let headers = String::from_utf8_lossy(&buffer[..header_end]).to_ascii_lowercase();
        let length: usize = headers
            .lines()
            .find_map(|line| line.strip_prefix("content-length:").map(str::trim))
            .unwrap()
            .parse()
            .unwrap();
        assert!(length < 65536);
        while buffer.len() < header_end + length {
            let n = stream.read(&mut chunk).unwrap();
            assert!(n > 0);
            buffer.extend_from_slice(&chunk[..n]);
        }
        let response =
            cms::test_timestamp_response(&tsa.identity, &buffer[header_end..header_end + length])
                .unwrap();
        write!(stream,"HTTP/1.1 200 OK\r\nContent-Type: application/timestamp-reply\r\nContent-Length: {}\r\nConnection: close\r\n\r\n",response.len()).unwrap();
        stream.write_all(&response).unwrap();
        count += 1;
        std::fs::write(directory.join("request-count.txt"), count.to_string()).unwrap();
    }
}

#[test]
#[ignore = "Requires Java baseline, real PDF renderers and LIMS_SIGNATURE_VISUAL_DIR"]
fn java_visual_signature_geometry_and_alpha() {
    let cp = std::env::var("JAVA_INTEROP_CLASSPATH").expect("JAVA_INTEROP_CLASSPATH");
    let java_home = std::env::var("JAVA_INTEROP_HOME").expect("JAVA_INTEROP_HOME");
    let directory = std::path::PathBuf::from(
        std::env::var("LIMS_SIGNATURE_VISUAL_DIR").expect("LIMS_SIGNATURE_VISUAL_DIR"),
    );
    std::fs::create_dir_all(&directory).unwrap();
    let appearance = image::RgbaImage::from_fn(120, 48, |x, y| {
        if x < 2 || y < 2 || x >= 118 || y >= 46 {
            image::Rgba([10, 30, 90, 255])
        } else if x < 40 {
            image::Rgba([235, 35, 25, if x > y { 190 } else { 50 }])
        } else if x < 80 {
            image::Rgba([20, 90, 225, 255])
        } else {
            image::Rgba([20, 200, 65, if y < 24 { 0 } else { 110 }])
        }
    });
    let mut image_bytes = Cursor::new(Vec::new());
    image::DynamicImage::ImageRgba8(appearance)
        .write_to(&mut image_bytes, image::ImageFormat::Png)
        .unwrap();
    let image_bytes = image_bytes.into_inner();
    std::fs::write(directory.join("appearance.png"), &image_bytes).unwrap();
    let (identity, tsa, trust) = identity_and_tsa();
    let service = SignatureService::new(identity, tsa, trust);
    let mut cases = Vec::new();
    for geometry in ["media", "crop", "unit"] {
        for rotation in [0, 90, 180, 270] {
            let name = format!("{geometry}-r{rotation}");
            let mut doc = Document::load_mem(&source(&[rotation])).unwrap();
            let page = *doc.get_pages().values().next().unwrap();
            {
                let page = doc.get_dictionary_mut(page).unwrap();
                if geometry == "media" {
                    page.remove(b"CropBox");
                }
                page.set(
                    "UserUnit",
                    if geometry == "unit" {
                        Object::Real(1.5)
                    } else {
                        Object::Integer(1)
                    },
                );
            }
            let mut backdrop = String::from("q 0.94 0.91 0.82 rg 0 0 600 800 re f\n");
            for band in 0..16 {
                if band % 2 == 0 {
                    backdrop.push_str(&format!("0.78 0.88 0.94 rg 0 {} 600 25 re f\n", band * 50));
                }
            }
            backdrop.push_str("0.3 0.4 0.5 RG 1 w 25 35 550 730 re S 30 400 m 570 400 l S 300 40 m 300 760 l S Q\n");
            let contents = doc.add_object(lopdf::Stream::new(
                lopdf::Dictionary::new(),
                backdrop.into_bytes(),
            ));
            doc.get_dictionary_mut(page)
                .unwrap()
                .set("Contents", contents);
            let mut source = Vec::new();
            doc.save_to(&mut source).unwrap();
            let first_plan = FieldPlan {
                field_name: "first".into(),
                page_index: 0,
                rectangle: NormalizedRectangle {
                    x: "0.10".into(),
                    y: "0.20".into(),
                    width: "0.30".into(),
                    height: "0.12".into(),
                },
                deferred: false,
            };
            let second_plan = FieldPlan {
                field_name: "second".into(),
                page_index: 0,
                rectangle: NormalizedRectangle {
                    x: "0.55".into(),
                    y: "0.65".into(),
                    width: "0.32".into(),
                    height: "0.18".into(),
                },
                deferred: false,
            };
            let prepared = prepare_fields(&source, &[first_plan, second_plan]).unwrap();
            std::fs::write(directory.join(format!("{name}-prepared.pdf")), &prepared).unwrap();
            let first = service
                .sign_preflighted(
                    service
                        .preflight(
                            &prepared,
                            &image_bytes,
                            &command("first", "certification_p2"),
                            None,
                        )
                        .unwrap(),
                )
                .unwrap();
            let final_pdf = service
                .sign_preflighted(
                    service
                        .preflight(&first, &image_bytes, &command("second", "approval"), None)
                        .unwrap(),
                )
                .unwrap();
            assert_eq!(service.verify(&final_pdf).document_current_state, "valid");
            std::fs::write(directory.join(format!("{name}-rust-first.pdf")), first).unwrap();
            std::fs::write(directory.join(format!("{name}-rust-final.pdf")), final_pdf).unwrap();
            cases.push(serde_json::json!({"name":name,"rotation":rotation,"geometry":geometry}));
        }
    }
    std::fs::write(
        directory.join("cases.json"),
        serde_json::to_vec_pretty(&cases).unwrap(),
    )
    .unwrap();
    let harness = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("tests/java/com/luang/pdfsigner/signature/NormalVisualOracle.java");
    let compile = std::process::Command::new(format!("{java_home}/bin/javac"))
        .args([
            "-cp",
            &cp,
            "-d",
            directory.to_str().unwrap(),
            harness.to_str().unwrap(),
        ])
        .output()
        .unwrap();
    assert!(
        compile.status.success(),
        "{}",
        String::from_utf8_lossy(&compile.stderr)
    );
    let output = std::process::Command::new(format!("{java_home}/bin/java"))
        .args([
            "-Djava.awt.headless=true",
            "-cp",
            &format!("{}:{cp}", directory.display()),
            "com.luang.pdfsigner.signature.NormalVisualOracle",
            directory.to_str().unwrap(),
        ])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    let script = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/visual/compare.py");
    let output = std::process::Command::new("python3")
        .args([script.to_str().unwrap(), directory.to_str().unwrap()])
        .output()
        .unwrap();
    assert!(
        output.status.success(),
        "{}",
        String::from_utf8_lossy(&output.stderr)
    );
    let summary: serde_json::Value =
        serde_json::from_slice(&std::fs::read(directory.join("visual-summary.json")).unwrap())
            .unwrap();
    assert_eq!(
        summary["allPixelEqual"],
        true,
        "{} (full renders and metrics in {})",
        String::from_utf8_lossy(&output.stdout),
        directory.display()
    );
}
