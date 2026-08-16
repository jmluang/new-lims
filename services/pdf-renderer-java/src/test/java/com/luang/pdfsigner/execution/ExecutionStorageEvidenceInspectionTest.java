package com.luang.pdfsigner.execution;

import static org.assertj.core.api.Assertions.assertThat;

import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.nio.charset.StandardCharsets;
import java.util.UUID;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class ExecutionStorageEvidenceInspectionTest {
    @TempDir
    Path temporaryDirectory;

    private ExecutionStorage storage;

    @BeforeEach
    void setUp() {
        storage = new ExecutionStorage(new ExecutionLedgerProperties(
                true,
                "jdbc:h2:mem:" + UUID.randomUUID(),
                "sa",
                "test",
                temporaryDirectory.toString()
        ));
    }

    @Test
    void distinguishesExactCanonicalStagedDuplicateMissingAndBreachedEvidence() throws Exception {
        UUID operationUuid = UUID.randomUUID();
        byte[] bytes = "%PDF-1.7 retirement probe".getBytes(StandardCharsets.US_ASCII);
        ExecutionStorage.StoredResult result = storage.persist(operationUuid, bytes, 1024 * 1024);

        assertThat(inspect(operationUuid, 0, "none", result).state()).isEqualTo("canonical");

        Path canonical = Path.of(result.path());
        Path staged = temporaryDirectory.resolve(operationUuid + ".pdf.retirement-4");
        Files.move(canonical, staged, StandardCopyOption.ATOMIC_MOVE);
        assertThat(inspect(operationUuid, 4, "staged", result).state()).isEqualTo("staged");

        Files.copy(staged, canonical);
        assertThat(inspect(operationUuid, 4, "purge_intent", result).state()).isEqualTo("duplicate");

        Files.delete(canonical);
        Files.delete(staged);
        assertThat(inspect(operationUuid, 4, "purge_intent", result).state()).isEqualTo("missing");

        Files.writeString(canonical, "tampered", StandardCharsets.US_ASCII);
        assertThat(inspect(operationUuid, 0, "none", result).state()).isEqualTo("breached");
    }

    private ExecutionStorage.RetirementEvidenceInspection inspect(
            UUID operationUuid,
            long epoch,
            String phase,
            ExecutionStorage.StoredResult result
    ) throws Exception {
        return storage.inspectRetirementEvidence(
                operationUuid,
                epoch,
                phase,
                result.sha256(),
                result.size()
        );
    }
}
