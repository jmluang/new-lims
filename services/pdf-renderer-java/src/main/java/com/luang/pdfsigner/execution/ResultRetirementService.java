package com.luang.pdfsigner.execution;

import java.time.Instant;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Service;

@Service
public final class ResultRetirementService {
    private static final Logger LOG = LoggerFactory.getLogger(ResultRetirementService.class);
    private final ExecutionLedgerProperties properties;
    private final SigningExecutionRepository repository;
    private final ExecutionStorage storage;

    public ResultRetirementService(
            ExecutionLedgerProperties properties,
            SigningExecutionRepository repository,
            ExecutionStorage storage
    ) {
        this.properties = properties;
        this.repository = repository;
        this.storage = storage;
    }

    @Scheduled(fixedDelayString = "${pdf.execution-ledger.retirement-sweep-interval-ms:60000}")
    public void scheduledSweep() {
        if (!properties.enabled()) return;
        try {
            sweep(100);
        } catch (Exception exception) {
            LOG.error("Java signing result retirement sweep failed", exception);
        }
    }

    public int sweep(int limit) throws Exception {
        int advanced = 0;
        for (ExecutionRecord candidate : repository.retirementCandidates(limit)) {
            try {
                ExecutionRecord current = candidate;
                if (!"none".equals(current.retirementPhase())
                        && current.evidenceHoldMask() != 0
                        && "active".equals(current.evidenceHoldState())) {
                    repository.applyRetirementRestore(
                            current.operationUuid(),
                            current.retirementEpoch(),
                            storage
                    );
                    advanced++;
                    continue;
                }
                if ("none".equals(current.retirementPhase())) {
                    current = repository.beginRetirement(current.operationUuid());
                    advanced++;
                }
                if ("stage_intent".equals(current.retirementPhase())) {
                    current = repository.applyRetirementStage(current.operationUuid(), current.retirementEpoch(), storage);
                    advanced++;
                }
                if ("staged".equals(current.retirementPhase())
                        && current.retirementPurgeNotBefore() != null
                        && !Instant.now().isBefore(current.retirementPurgeNotBefore())) {
                    storage.verifyStaged(current);
                    current = repository.beginRetirementPurge(current.operationUuid(), current.retirementEpoch());
                    advanced++;
                }
                if ("purge_intent".equals(current.retirementPhase())) {
                    repository.applyRetirementPurge(current.operationUuid(), current.retirementEpoch(), storage);
                    advanced++;
                }
            } catch (SigningExecutionRepository.ExecutionGateException exception) {
                LOG.info("Retirement candidate {} lost a gate: {}", candidate.operationUuid(), exception.getMessage());
            } catch (Exception exception) {
                LOG.error("Retirement candidate {} requires review", candidate.operationUuid(), exception);
            }
        }
        return advanced;
    }
}
