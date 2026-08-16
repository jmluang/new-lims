package com.luang.pdfsigner.execution;

import java.io.IOException;
import java.io.InputStream;
import java.nio.ByteBuffer;
import java.nio.channels.Channels;
import java.nio.channels.FileChannel;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.NoSuchFileException;
import java.nio.file.StandardCopyOption;
import java.nio.file.StandardOpenOption;
import java.nio.file.LinkOption;
import java.nio.file.attribute.BasicFileAttributes;
import java.nio.file.attribute.PosixFilePermission;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.List;
import java.util.Set;
import java.util.UUID;
import org.springframework.stereotype.Component;

@Component
public final class ExecutionStorage {
    private static final HexFormat HEX = HexFormat.of();
    private final Path root;

    public ExecutionStorage(ExecutionLedgerProperties properties) {
        this.root = properties.resultRoot();
    }

    public StoredResult persist(UUID operationUuid, byte[] bytes, long maximumBytes) throws Exception {
        if (bytes.length == 0 || bytes.length > maximumBytes) {
            throw new IllegalArgumentException("The generated revision exceeds the frozen policy budget");
        }
        Files.createDirectories(root);
        Path canonical = resultPath(operationUuid);
        Path temporary = root.resolve("." + operationUuid + ".tmp-" + UUID.randomUUID()).normalize();
        requireInsideRoot(temporary);
        if (Files.exists(canonical)) {
            throw new IllegalStateException("An execution result already exists for this operation");
        }
        try {
            try (FileChannel channel = FileChannel.open(
                    temporary,
                    StandardOpenOption.CREATE_NEW,
                    StandardOpenOption.WRITE
            )) {
                channel.write(java.nio.ByteBuffer.wrap(bytes));
                channel.force(true);
            }
            Files.move(temporary, canonical, StandardCopyOption.ATOMIC_MOVE);
            forceDirectory(root);
            try {
                Files.setPosixFilePermissions(canonical, Set.of(
                        PosixFilePermission.OWNER_READ,
                        PosixFilePermission.GROUP_READ
                ));
            } catch (UnsupportedOperationException ignored) {
                canonical.toFile().setReadOnly();
            }
            BasicFileAttributes attributes = Files.readAttributes(canonical, BasicFileAttributes.class);
            MessageDigest readBackDigest = MessageDigest.getInstance("SHA-256");
            long readBackSize = 0;
            try (FileChannel channel = FileChannel.open(
                    canonical,
                    StandardOpenOption.READ,
                    LinkOption.NOFOLLOW_LINKS
            )) {
                ByteBuffer buffer = ByteBuffer.allocate(1024 * 1024);
                while (channel.read(buffer) != -1) {
                    int read = buffer.position();
                    if (read == 0) {
                        continue;
                    }
                    buffer.flip();
                    readBackDigest.update(buffer);
                    readBackSize += read;
                    buffer.clear();
                }
            }
            String sha256 = HEX.formatHex(readBackDigest.digest());
            String expectedSha256 = HEX.formatHex(MessageDigest.getInstance("SHA-256").digest(bytes));
            if (readBackSize != bytes.length || !MessageDigest.isEqual(
                    sha256.getBytes(java.nio.charset.StandardCharsets.US_ASCII),
                    expectedSha256.getBytes(java.nio.charset.StandardCharsets.US_ASCII)
            )) {
                throw new IllegalStateException("Persisted execution result failed read-back verification");
            }
            return new StoredResult(
                    canonical.toString(),
                    sha256,
                    readBackSize,
                    String.valueOf(attributes.fileKey())
            );
        } finally {
            Files.deleteIfExists(temporary);
        }
    }

    public OpenResult openVerified(ExecutionRecord execution) throws Exception {
        Path expected = resultPath(execution.operationUuid());
        Path recorded = Path.of(execution.resultPath()).toAbsolutePath().normalize();
        if (!expected.equals(recorded)) {
            throw new ResultBreachedException("Execution result path escaped its immutable contract");
        }
        FileChannel channel;
        try {
            channel = FileChannel.open(expected, StandardOpenOption.READ, LinkOption.NOFOLLOW_LINKS);
        } catch (NoSuchFileException exception) {
            throw new ResultMissingException("Execution result bytes are missing", exception);
        }
        try {
            BasicFileAttributes attributes = Files.readAttributes(
                    expected,
                    BasicFileAttributes.class,
                    LinkOption.NOFOLLOW_LINKS
            );
            if (!attributes.isRegularFile()) {
                throw new ResultBreachedException("Execution result is not a regular file");
            }
            if (execution.resultFileKey() != null
                    && !execution.resultFileKey().equals(String.valueOf(attributes.fileKey()))) {
                throw new ResultBreachedException("Execution result file identity changed");
            }
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            long size = 0;
            ByteBuffer buffer = ByteBuffer.allocate(1024 * 1024);
            while (channel.read(buffer) != -1) {
                int read = buffer.position();
                if (read == 0) {
                    continue;
                }
                buffer.flip();
                digest.update(buffer);
                size += read;
                buffer.clear();
            }
            String sha256 = HEX.formatHex(digest.digest());
            if (size != execution.resultSize() || !sha256.equals(execution.resultSha256())) {
                throw new ResultBreachedException("Execution result bytes failed size or SHA-256 verification");
            }
            channel.position(0);
            return new OpenResult(Channels.newInputStream(channel), size, sha256);
        } catch (Exception exception) {
            channel.close();
            throw exception;
        }
    }

    public RecoveryCandidate findRecoveryCandidate(UUID operationUuid, long maximumBytes) throws Exception {
        Files.createDirectories(root);
        Path canonical = resultPath(operationUuid);
        List<Path> temporaryCandidates = new ArrayList<>();
        String temporaryPattern = "." + operationUuid + ".tmp-*";
        try (var candidates = Files.newDirectoryStream(root, temporaryPattern)) {
            for (Path candidate : candidates) {
                temporaryCandidates.add(candidate.toAbsolutePath().normalize());
            }
        }
        boolean canonicalExists = Files.exists(canonical, LinkOption.NOFOLLOW_LINKS);
        if (canonicalExists && !temporaryCandidates.isEmpty()) {
            throw new ResultBreachedException("Recovery found canonical and temporary result copies");
        }
        if (temporaryCandidates.size() > 1) {
            throw new ResultBreachedException("Recovery found multiple temporary result copies");
        }
        if (canonicalExists) {
            return readRecoveryCandidate(canonical, true, maximumBytes);
        }
        if (temporaryCandidates.isEmpty()) {
            return null;
        }
        return readRecoveryCandidate(temporaryCandidates.get(0), false, maximumBytes);
    }

    public StoredResult promoteRecoveryCandidate(RecoveryCandidate candidate) throws Exception {
        Path canonical = resultPath(candidate.operationUuid());
        Path recorded = candidate.path().toAbsolutePath().normalize();
        Path expectedTemporaryPrefix = root.resolve("." + candidate.operationUuid() + ".tmp-").normalize();
        if (candidate.canonical()) {
            if (!recorded.equals(canonical)) {
                throw new ResultBreachedException("Canonical recovery candidate escaped its immutable contract");
            }
        } else if (!recorded.getParent().equals(root)
                || !recorded.getFileName().toString().startsWith(expectedTemporaryPrefix.getFileName().toString())) {
            throw new ResultBreachedException("Temporary recovery candidate escaped its immutable contract");
        }
        RecoveryCandidate verified = readRecoveryCandidate(recorded, candidate.canonical(), candidate.size());
        if (verified.size() != candidate.size()
                || !verified.sha256().equals(candidate.sha256())
                || !verified.fileKey().equals(candidate.fileKey())) {
            throw new ResultBreachedException("Recovery candidate identity changed before promotion");
        }
        if (!candidate.canonical()) {
            if (Files.exists(canonical, LinkOption.NOFOLLOW_LINKS)) {
                throw new ResultBreachedException("Recovery promotion found an existing canonical result");
            }
            Files.move(recorded, canonical, StandardCopyOption.ATOMIC_MOVE);
            forceDirectory(root);
        }
        makeReadOnly(canonical);
        RecoveryCandidate promoted = readRecoveryCandidate(canonical, true, candidate.size());
        if (promoted.size() != candidate.size() || !promoted.sha256().equals(candidate.sha256())) {
            throw new ResultBreachedException("Promoted recovery result failed read-back verification");
        }
        return new StoredResult(
                canonical.toString(),
                promoted.sha256(),
                promoted.size(),
                promoted.fileKey()
        );
    }

    public boolean readinessProbe() {
        try {
            Files.createDirectories(root);
            Path first = root.resolve(".readiness-" + UUID.randomUUID());
            Path second = root.resolve(first.getFileName() + ".renamed");
            byte[] marker = UUID.randomUUID().toString().getBytes(java.nio.charset.StandardCharsets.US_ASCII);
            try (FileChannel channel = FileChannel.open(first, StandardOpenOption.CREATE_NEW, StandardOpenOption.WRITE)) {
                channel.write(java.nio.ByteBuffer.wrap(marker));
                channel.force(true);
            }
            Files.move(first, second, StandardCopyOption.ATOMIC_MOVE);
            forceDirectory(root);
            try (InputStream descriptor = Files.newInputStream(second)) {
                Files.delete(second);
                return MessageDigest.isEqual(marker, descriptor.readAllBytes());
            } finally {
                Files.deleteIfExists(first);
                Files.deleteIfExists(second);
            }
        } catch (Exception exception) {
            return false;
        }
    }

    public String stageForRetirement(ExecutionRecord execution, long epoch) throws Exception {
        Path canonical = validatedCanonicalPath(execution);
        Path staged = retirementPath(execution.operationUuid(), epoch);
        verifyPath(canonical, execution);
        if (Files.exists(staged, LinkOption.NOFOLLOW_LINKS)) {
            throw new ResultBreachedException("Retirement staged path already exists");
        }
        Files.move(canonical, staged, StandardCopyOption.ATOMIC_MOVE);
        forceDirectory(root);
        verifyPath(staged, execution);
        return staged.toString();
    }

    public void verifyStaged(ExecutionRecord execution) throws Exception {
        if (execution.retirementStagedPath() == null) {
            throw new ResultBreachedException("Retirement staged path is missing from the ledger");
        }
        Path expected = retirementPath(execution.operationUuid(), execution.retirementEpoch());
        Path recorded = Path.of(execution.retirementStagedPath()).toAbsolutePath().normalize();
        if (!expected.equals(recorded)) {
            throw new ResultBreachedException("Retirement staged path escaped its immutable contract");
        }
        verifyPath(expected, execution);
    }

    public void purgeStaged(ExecutionRecord execution) throws Exception {
        verifyStaged(execution);
        Files.delete(Path.of(execution.retirementStagedPath()));
        forceDirectory(root);
    }

    public boolean stagedExists(ExecutionRecord execution) {
        return execution.retirementStagedPath() != null
                && Files.exists(Path.of(execution.retirementStagedPath()), LinkOption.NOFOLLOW_LINKS);
    }

    public boolean canonicalExists(ExecutionRecord execution) {
        return Files.exists(validatedCanonicalPath(execution), LinkOption.NOFOLLOW_LINKS);
    }

    public void restoreStaged(ExecutionRecord execution) throws Exception {
        Path canonical = validatedCanonicalPath(execution);
        if (canonicalExists(execution)) {
            throw new ResultBreachedException("Retirement restore found duplicate canonical bytes");
        }
        verifyStaged(execution);
        Files.move(
                Path.of(execution.retirementStagedPath()),
                canonical,
                StandardCopyOption.ATOMIC_MOVE
        );
        forceDirectory(root);
        verifyPath(canonical, execution);
    }

    public RetirementEvidenceInspection inspectRetirementEvidence(
            UUID operationUuid,
            long epoch,
            String retirementPhase,
            String expectedSha256,
            long expectedSize
    ) throws Exception {
        if (!expectedSha256.matches("[0-9a-f]{64}") || expectedSize <= 0
                || !Set.of("none", "stage_intent", "staged", "purge_intent").contains(retirementPhase)
                || (!"none".equals(retirementPhase) && epoch <= 0)) {
            throw new IllegalArgumentException("Retirement evidence snapshot is invalid");
        }
        Path canonical = resultPath(operationUuid);
        Path staged = retirementPath(operationUuid, epoch);
        boolean canonicalPresent = Files.exists(canonical, LinkOption.NOFOLLOW_LINKS);
        boolean stagedPresent = !"none".equals(retirementPhase)
                && Files.exists(staged, LinkOption.NOFOLLOW_LINKS);
        String state;
        if (!canonicalPresent && !stagedPresent) {
            state = "missing";
        } else if (canonicalPresent && stagedPresent) {
            state = "duplicate";
        } else {
            Path evidence = canonicalPresent ? canonical : staged;
            try {
                verifyPath(evidence, expectedSha256, expectedSize);
                state = canonicalPresent ? "canonical" : "staged";
            } catch (ResultMissingException exception) {
                canonicalPresent = Files.exists(canonical, LinkOption.NOFOLLOW_LINKS);
                stagedPresent = !"none".equals(retirementPhase)
                        && Files.exists(staged, LinkOption.NOFOLLOW_LINKS);
                state = canonicalPresent || stagedPresent ? "breached" : "missing";
            } catch (ResultBreachedException exception) {
                state = "breached";
            }
        }
        return new RetirementEvidenceInspection(
                operationUuid,
                epoch,
                retirementPhase,
                expectedSha256,
                expectedSize,
                canonicalPresent,
                stagedPresent,
                state
        );
    }

    private Path validatedCanonicalPath(ExecutionRecord execution) {
        Path expected = resultPath(execution.operationUuid());
        Path recorded = Path.of(execution.resultPath()).toAbsolutePath().normalize();
        if (!expected.equals(recorded)) {
            throw new ResultBreachedException("Execution result path escaped its immutable contract");
        }
        return expected;
    }

    private Path retirementPath(UUID operationUuid, long epoch) {
        Path path = root.resolve(operationUuid + ".pdf.retirement-" + epoch).normalize();
        requireInsideRoot(path);
        return path;
    }

    private void verifyPath(Path path, ExecutionRecord execution) throws Exception {
        verifyPath(path, execution.resultSha256(), execution.resultSize());
    }

    private void verifyPath(Path path, String expectedSha256, long expectedSize) throws Exception {
        BasicFileAttributes attributes;
        try {
            attributes = Files.readAttributes(path, BasicFileAttributes.class, LinkOption.NOFOLLOW_LINKS);
        } catch (NoSuchFileException exception) {
            throw new ResultMissingException("Execution result bytes are missing", exception);
        }
        if (!attributes.isRegularFile()) {
            throw new ResultBreachedException("Execution result is not a regular file");
        }
        MessageDigest digest = MessageDigest.getInstance("SHA-256");
        long size = 0;
        try (FileChannel channel = FileChannel.open(path, StandardOpenOption.READ, LinkOption.NOFOLLOW_LINKS)) {
            ByteBuffer buffer = ByteBuffer.allocate(1024 * 1024);
            while (channel.read(buffer) != -1) {
                int read = buffer.position();
                if (read == 0) continue;
                buffer.flip();
                digest.update(buffer);
                size += read;
                buffer.clear();
            }
        }
        if (size != expectedSize || !HEX.formatHex(digest.digest()).equals(expectedSha256)) {
            throw new ResultBreachedException("Execution result bytes failed size or SHA-256 verification");
        }
    }

    private Path resultPath(UUID operationUuid) {
        Path path = root.resolve(operationUuid + ".pdf").normalize();
        requireInsideRoot(path);
        return path;
    }

    private RecoveryCandidate readRecoveryCandidate(Path path, boolean canonical, long maximumBytes) throws Exception {
        requireInsideRoot(path);
        BasicFileAttributes attributes = Files.readAttributes(
                path,
                BasicFileAttributes.class,
                LinkOption.NOFOLLOW_LINKS
        );
        if (!attributes.isRegularFile()) {
            throw new ResultBreachedException("Recovery result is not a regular file");
        }
        if (attributes.size() == 0 || attributes.size() > maximumBytes || attributes.size() > Integer.MAX_VALUE) {
            throw new ResultBreachedException("Recovery result exceeds the frozen policy budget");
        }
        byte[] bytes;
        try (FileChannel channel = FileChannel.open(path, StandardOpenOption.READ, LinkOption.NOFOLLOW_LINKS)) {
            ByteBuffer buffer = ByteBuffer.allocate(Math.toIntExact(attributes.size()));
            while (buffer.hasRemaining()) {
                if (channel.read(buffer) < 0) break;
            }
            if (buffer.hasRemaining() || channel.read(ByteBuffer.allocate(1)) != -1) {
                throw new ResultBreachedException("Recovery result size changed while reading");
            }
            bytes = buffer.array();
        }
        return new RecoveryCandidate(
                path,
                canonical,
                bytes,
                HEX.formatHex(MessageDigest.getInstance("SHA-256").digest(bytes)),
                bytes.length,
                String.valueOf(attributes.fileKey()),
                extractOperationUuid(path, canonical)
        );
    }

    private UUID extractOperationUuid(Path path, boolean canonical) {
        String name = path.getFileName().toString();
        String value = canonical
                ? name.substring(0, name.length() - ".pdf".length())
                : name.substring(1, name.indexOf(".tmp-"));
        return UUID.fromString(value);
    }

    private static void makeReadOnly(Path path) throws IOException {
        try {
            Files.setPosixFilePermissions(path, Set.of(
                    PosixFilePermission.OWNER_READ,
                    PosixFilePermission.GROUP_READ
            ));
        } catch (UnsupportedOperationException ignored) {
            path.toFile().setReadOnly();
        }
    }

    private void requireInsideRoot(Path path) {
        if (!path.startsWith(root)) {
            throw new IllegalArgumentException("Execution result path escaped its storage root");
        }
    }

    private static void forceDirectory(Path directory) throws IOException {
        try (FileChannel channel = FileChannel.open(directory, StandardOpenOption.READ)) {
            channel.force(true);
        }
    }

    public record StoredResult(String path, String sha256, long size, String fileKey) {}

    public record OpenResult(InputStream input, long size, String sha256) {}

    public record RetirementEvidenceInspection(
            UUID operationUuid,
            long retirementEpoch,
            String retirementPhase,
            String expectedSha256,
            long expectedSize,
            boolean canonicalPresent,
            boolean stagedPresent,
            String state
    ) {}

    public record RecoveryCandidate(
            Path path,
            boolean canonical,
            byte[] bytes,
            String sha256,
            long size,
            String fileKey,
            UUID operationUuid
    ) {}

    public static final class ResultMissingException extends IllegalStateException {
        ResultMissingException(String message, Throwable cause) {
            super(message, cause);
        }
    }

    public static final class ResultBreachedException extends IllegalStateException {
        ResultBreachedException(String message) {
            super(message);
        }
    }
}
