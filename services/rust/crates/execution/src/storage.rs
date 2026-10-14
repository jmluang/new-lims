use crate::{sha256, ExecutionError as E, ExecutionRecord, Result};
use serde::{Deserialize, Serialize};
#[cfg(unix)]
use std::os::unix::{
    ffi::OsStrExt,
    fs::{MetadataExt, OpenOptionsExt, PermissionsExt},
};
use std::{
    fs::{self, File, OpenOptions},
    io::{Read, Seek, SeekFrom, Write},
    path::{Component, Path, PathBuf},
};
use uuid::Uuid;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StoredResult {
    pub path: String,
    pub sha256: String,
    pub size: u64,
    pub file_key: String,
}
#[derive(Debug)]
pub struct OpenResult {
    pub input: File,
    pub size: u64,
    pub sha256: String,
}
#[derive(Debug, Clone)]
pub struct RecoveryCandidate {
    pub path: PathBuf,
    pub canonical: bool,
    pub bytes: Vec<u8>,
    pub sha256: String,
    pub size: u64,
    pub file_key: String,
    pub operation_uuid: Uuid,
}
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct RetirementEvidenceInspection {
    pub operation_uuid: Uuid,
    pub retirement_epoch: u64,
    pub retirement_phase: String,
    pub expected_sha256: String,
    pub expected_size: u64,
    pub canonical_present: bool,
    pub staged_present: bool,
    pub state: String,
}
#[derive(Debug, Clone)]
pub struct ExecutionStorage {
    root: PathBuf,
}
impl ExecutionStorage {
    pub fn new(root: impl AsRef<Path>) -> Result<Self> {
        let root = root.as_ref();
        let path = if root.is_absolute() {
            root.to_path_buf()
        } else {
            std::env::current_dir()?.join(root)
        };
        Ok(Self {
            root: normalize(&path),
        })
    }
    pub fn root(&self) -> &Path {
        &self.root
    }
    pub fn result_path(&self, id: Uuid) -> PathBuf {
        self.root.join(format!("{id}.pdf"))
    }
    pub fn retirement_path(&self, id: Uuid, epoch: u64) -> PathBuf {
        self.root.join(format!("{id}.pdf.retirement-{epoch}"))
    }
    fn sync_dir(&self) -> Result<()> {
        File::open(&self.root)?.sync_all()?;
        Ok(())
    }
    pub fn persist(&self, id: Uuid, bytes: &[u8], maximum_bytes: u64) -> Result<StoredResult> {
        if bytes.is_empty() || bytes.len() as u64 > maximum_bytes {
            return Err(E::Invalid(
                "Generated revision exceeds the frozen policy budget".into(),
            ));
        }
        fs::create_dir_all(&self.root)?;
        let canonical = self.result_path(id);
        let temporary = self.root.join(format!(".{id}.tmp-{}", Uuid::new_v4()));
        if present(&canonical) {
            return Err(E::Breached(
                "Result already exists for this operation".into(),
            ));
        }
        let result = (|| {
            let mut file = OpenOptions::new()
                .write(true)
                .create_new(true)
                .open(&temporary)?;
            file.write_all(bytes)?;
            file.sync_all()?;
            drop(file);
            atomic_move(&temporary, &canonical)?;
            self.sync_dir()?;
            make_read_only(&canonical)?;
            let verified = self.read_candidate(&canonical, true, maximum_bytes, id)?;
            if verified.size != bytes.len() as u64 || verified.sha256 != sha256(bytes) {
                return Err(E::Breached(
                    "Persisted result failed read-back verification".into(),
                ));
            }
            Ok(StoredResult {
                path: path_string(&canonical)?,
                sha256: verified.sha256,
                size: verified.size,
                file_key: verified.file_key,
            })
        })();
        match fs::remove_file(&temporary) {
            Ok(()) => (),
            Err(e) if e.kind() == std::io::ErrorKind::NotFound => (),
            Err(e) => return Err(e.into()),
        }
        result
    }
    fn canonical(&self, e: &ExecutionRecord) -> Result<PathBuf> {
        let expected = self.result_path(e.operation_uuid);
        let recorded = e
            .result_path
            .as_deref()
            .ok_or_else(|| E::Breached("Missing recorded result path".into()))?;
        if normalize(Path::new(recorded)) != expected {
            return Err(E::Breached(
                "Result path escaped its immutable contract".into(),
            ));
        }
        Ok(expected)
    }
    pub fn open_verified(&self, e: &ExecutionRecord) -> Result<OpenResult> {
        let expected = self.canonical(e)?;
        let mut file = open_read(&expected)?;
        let meta = file.metadata()?;
        if !meta.is_file() {
            return Err(E::Breached("Result is not a regular file".into()));
        }
        if e.result_file_key
            .as_ref()
            .is_some_and(|key| *key != file_key(&meta))
        {
            return Err(E::Breached("Result file identity changed".into()));
        }
        let (size, hash) = digest_file(&mut file)?;
        if size != e.result_size || Some(&hash) != e.result_sha256.as_ref() {
            return Err(E::Breached(
                "Result failed size or SHA-256 verification".into(),
            ));
        }
        file.seek(SeekFrom::Start(0))?;
        Ok(OpenResult {
            input: file,
            size,
            sha256: hash,
        })
    }
    fn read_candidate(
        &self,
        path: &Path,
        canonical: bool,
        maximum: u64,
        id: Uuid,
    ) -> Result<RecoveryCandidate> {
        if path.parent() != Some(self.root.as_path()) {
            return Err(E::Breached("Recovery path escaped storage root".into()));
        }
        let mut file = open_read(path)?;
        let meta = file.metadata()?;
        if !meta.is_file()
            || meta.len() == 0
            || meta.len() > maximum
            || meta.len() > i32::MAX as u64
        {
            return Err(E::Breached(
                "Recovery result exceeds frozen policy budget or is not regular".into(),
            ));
        }
        let mut bytes = Vec::with_capacity(meta.len() as usize);
        (&mut file).take(meta.len() + 1).read_to_end(&mut bytes)?;
        if bytes.len() as u64 != meta.len() {
            return Err(E::Breached("Recovery size changed while reading".into()));
        }
        Ok(RecoveryCandidate {
            path: path.to_path_buf(),
            canonical,
            sha256: sha256(&bytes),
            size: bytes.len() as u64,
            file_key: file_key(&meta),
            bytes,
            operation_uuid: id,
        })
    }
    pub fn find_recovery_candidate(
        &self,
        id: Uuid,
        maximum: u64,
    ) -> Result<Option<RecoveryCandidate>> {
        fs::create_dir_all(&self.root)?;
        let canonical = self.result_path(id);
        let prefix = format!(".{id}.tmp-");
        let mut temporary = Vec::new();
        for entry in fs::read_dir(&self.root)? {
            let entry = entry?;
            if entry.file_name().to_string_lossy().starts_with(&prefix) {
                temporary.push(entry.path())
            }
        }
        if (present(&canonical) && !temporary.is_empty()) || temporary.len() > 1 {
            return Err(E::Breached("Recovery found multiple result copies".into()));
        }
        if present(&canonical) {
            return self.read_candidate(&canonical, true, maximum, id).map(Some);
        }
        match temporary.first() {
            Some(path) => self.read_candidate(path, false, maximum, id).map(Some),
            None => Ok(None),
        }
    }
    pub fn promote_recovery_candidate(&self, c: &RecoveryCandidate) -> Result<StoredResult> {
        let canonical = self.result_path(c.operation_uuid);
        let valid = if c.canonical {
            c.path == canonical
        } else {
            c.path.parent() == Some(&self.root)
                && c.path.file_name().is_some_and(|v| {
                    v.to_string_lossy()
                        .starts_with(&format!(".{}.tmp-", c.operation_uuid))
                })
        };
        if !valid {
            return Err(E::Breached(
                "Recovery candidate escaped immutable contract".into(),
            ));
        }
        let verified = self.read_candidate(&c.path, c.canonical, c.size, c.operation_uuid)?;
        if verified.size != c.size || verified.sha256 != c.sha256 || verified.file_key != c.file_key
        {
            return Err(E::Breached(
                "Recovery identity changed before promotion".into(),
            ));
        }
        if !c.canonical {
            atomic_move(&c.path, &canonical)?;
            self.sync_dir()?
        }
        make_read_only(&canonical)?;
        let promoted = self.read_candidate(&canonical, true, c.size, c.operation_uuid)?;
        if promoted.size != c.size || promoted.sha256 != c.sha256 {
            return Err(E::Breached(
                "Promoted recovery result failed verification".into(),
            ));
        }
        Ok(StoredResult {
            path: path_string(&canonical)?,
            sha256: promoted.sha256,
            size: promoted.size,
            file_key: promoted.file_key,
        })
    }
    pub fn canonical_exists(&self, e: &ExecutionRecord) -> Result<bool> {
        Ok(present(&self.canonical(e)?))
    }
    fn staged(&self, e: &ExecutionRecord) -> Result<PathBuf> {
        let expected = self.retirement_path(e.operation_uuid, e.retirement_epoch);
        if e.retirement_staged_path
            .as_deref()
            .map(Path::new)
            .map(normalize)
            != Some(expected.clone())
        {
            return Err(E::Breached(
                "Retirement staged path escaped immutable contract".into(),
            ));
        }
        Ok(expected)
    }
    pub fn staged_exists(&self, e: &ExecutionRecord) -> Result<bool> {
        if e.retirement_staged_path.is_none() {
            Ok(false)
        } else {
            Ok(present(&self.staged(e)?))
        }
    }
    pub fn stage_for_retirement(&self, e: &ExecutionRecord, epoch: u64) -> Result<String> {
        let canonical = self.canonical(e)?;
        let staged = self.retirement_path(e.operation_uuid, epoch);
        verify_path(
            &canonical,
            e.result_sha256.as_deref().unwrap_or(""),
            e.result_size,
        )?;
        atomic_move(&canonical, &staged)?;
        self.sync_dir()?;
        verify_path(
            &staged,
            e.result_sha256.as_deref().unwrap_or(""),
            e.result_size,
        )?;
        path_string(&staged)
    }
    pub fn verify_staged(&self, e: &ExecutionRecord) -> Result<()> {
        verify_path(
            &self.staged(e)?,
            e.result_sha256.as_deref().unwrap_or(""),
            e.result_size,
        )
    }
    pub fn purge_staged(&self, e: &ExecutionRecord) -> Result<()> {
        self.verify_staged(e)?;
        fs::remove_file(self.staged(e)?)?;
        self.sync_dir()
    }
    pub fn restore_staged(&self, e: &ExecutionRecord) -> Result<()> {
        self.verify_staged(e)?;
        let canonical = self.canonical(e)?;
        atomic_move(&self.staged(e)?, &canonical)?;
        self.sync_dir()?;
        verify_path(
            &canonical,
            e.result_sha256.as_deref().unwrap_or(""),
            e.result_size,
        )
    }
    pub fn inspect_retirement_evidence(
        &self,
        id: Uuid,
        epoch: u64,
        phase: &str,
        hash: &str,
        size: u64,
    ) -> Result<RetirementEvidenceInspection> {
        if hash.len() != 64
            || !hash
                .bytes()
                .all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
            || size == 0
            || !matches!(phase, "none" | "stage_intent" | "staged" | "purge_intent")
            || (phase != "none" && epoch == 0)
        {
            return Err(E::Invalid("Retirement evidence snapshot is invalid".into()));
        }
        let canonical = self.result_path(id);
        let staged = self.retirement_path(id, epoch);
        let mut cp = present(&canonical);
        let mut sp = phase != "none" && present(&staged);
        let state = match (cp, sp) {
            (false, false) => "missing",
            (true, true) => "duplicate",
            _ => match verify_path(if cp { &canonical } else { &staged }, hash, size) {
                Ok(()) => {
                    if cp {
                        "canonical"
                    } else {
                        "staged"
                    }
                }
                Err(E::Missing) => {
                    cp = present(&canonical);
                    sp = phase != "none" && present(&staged);
                    if cp || sp {
                        "breached"
                    } else {
                        "missing"
                    }
                }
                Err(E::Breached(_)) => "breached",
                Err(e) => return Err(e),
            },
        };
        Ok(RetirementEvidenceInspection {
            operation_uuid: id,
            retirement_epoch: epoch,
            retirement_phase: phase.into(),
            expected_sha256: hash.into(),
            expected_size: size,
            canonical_present: cp,
            staged_present: sp,
            state: state.into(),
        })
    }
    pub fn readiness_probe(&self) -> bool {
        (|| -> Result<bool> {
            fs::create_dir_all(&self.root)?;
            let first = self.root.join(format!(".readiness-{}", Uuid::new_v4()));
            let second = first.with_extension("renamed");
            let result = (|| {
                let marker = Uuid::new_v4().to_string();
                let mut f = OpenOptions::new()
                    .write(true)
                    .create_new(true)
                    .open(&first)?;
                f.write_all(marker.as_bytes())?;
                f.sync_all()?;
                drop(f);
                atomic_move(&first, &second)?;
                self.sync_dir()?;
                let mut reader = open_read(&second)?;
                fs::remove_file(&second)?;
                let mut bytes = Vec::new();
                reader.read_to_end(&mut bytes)?;
                Ok(bytes == marker.as_bytes())
            })();
            let _ = fs::remove_file(first);
            let _ = fs::remove_file(second);
            result
        })()
        .unwrap_or(false)
    }
}
fn normalize(path: &Path) -> PathBuf {
    let mut result = PathBuf::new();
    for c in path.components() {
        match c {
            Component::CurDir => (),
            Component::ParentDir => {
                result.pop();
            }
            other => result.push(other.as_os_str()),
        }
    }
    result
}
fn path_string(path: &Path) -> Result<String> {
    path.to_str()
        .map(str::to_owned)
        .ok_or_else(|| E::Invalid("Result path must be UTF-8".into()))
}
fn present(path: &Path) -> bool {
    fs::symlink_metadata(path).is_ok()
}
fn open_read(path: &Path) -> Result<File> {
    let mut options = OpenOptions::new();
    options.read(true);
    #[cfg(unix)]
    options.custom_flags(libc::O_NOFOLLOW | libc::O_CLOEXEC | libc::O_NONBLOCK);
    let file = options.open(path).map_err(|e| match e.kind() {
        std::io::ErrorKind::NotFound => E::Missing,
        _ if e.raw_os_error() == Some(libc::ELOOP) => {
            E::Breached("Result path is a symbolic link".into())
        }
        _ => E::Io(e),
    })?;
    if !file.metadata()?.is_file() {
        return Err(E::Breached("Result is not a regular file".into()));
    }
    Ok(file)
}
fn digest_file(file: &mut File) -> Result<(u64, String)> {
    use sha2::Digest;
    let mut d = sha2::Sha256::new();
    let mut size = 0;
    let mut buf = [0u8; 65536];
    loop {
        let n = file.read(&mut buf)?;
        if n == 0 {
            break;
        }
        d.update(&buf[..n]);
        size += n as u64
    }
    Ok((size, hex::encode(d.finalize())))
}
fn verify_path(path: &Path, hash: &str, size: u64) -> Result<()> {
    let mut f = open_read(path)?;
    let (actual, sha) = digest_file(&mut f)?;
    if actual != size || sha != hash {
        return Err(E::Breached(
            "Result failed size or SHA-256 verification".into(),
        ));
    }
    Ok(())
}
#[cfg(unix)]
fn file_key(meta: &fs::Metadata) -> String {
    format!("(dev={:x},ino={})", meta.dev(), meta.ino())
}
#[cfg(not(unix))]
fn file_key(_: &fs::Metadata) -> String {
    "null".into()
}
fn make_read_only(path: &Path) -> Result<()> {
    #[cfg(unix)]
    fs::set_permissions(path, fs::Permissions::from_mode(0o440))?;
    #[cfg(not(unix))]
    {
        let mut p = fs::metadata(path)?.permissions();
        p.set_readonly(true);
        fs::set_permissions(path, p)?;
    }
    Ok(())
}
/// Linux atomic, no-clobber rename. Never overwrite an existing immutable result.
#[cfg(target_os = "linux")]
fn atomic_move(from: &Path, to: &Path) -> Result<()> {
    let a = std::ffi::CString::new(from.as_os_str().as_bytes())
        .map_err(|_| E::Invalid("NUL path".into()))?;
    let b = std::ffi::CString::new(to.as_os_str().as_bytes())
        .map_err(|_| E::Invalid("NUL path".into()))?;
    let rc = unsafe {
        libc::renameat2(
            libc::AT_FDCWD,
            a.as_ptr(),
            libc::AT_FDCWD,
            b.as_ptr(),
            libc::RENAME_NOREPLACE,
        )
    };
    if rc != 0 {
        let e = std::io::Error::last_os_error();
        return Err(if e.kind() == std::io::ErrorKind::AlreadyExists {
            E::Breached("Atomic destination already exists".into())
        } else {
            E::Io(e)
        });
    }
    Ok(())
}
#[cfg(not(target_os = "linux"))]
fn atomic_move(_: &Path, _: &Path) -> Result<()> {
    Err(E::Io(std::io::Error::new(
        std::io::ErrorKind::Unsupported,
        "Atomic no-clobber result storage requires Linux",
    )))
}
