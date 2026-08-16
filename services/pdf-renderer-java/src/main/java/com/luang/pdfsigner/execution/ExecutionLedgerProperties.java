package com.luang.pdfsigner.execution;

import java.nio.file.Path;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

@Component
public final class ExecutionLedgerProperties {
    private final boolean enabled;
    private final String jdbcUrl;
    private final String username;
    private final String password;
    private final Path resultRoot;

    public ExecutionLedgerProperties(
            @Value("${pdf.execution-ledger.enabled:false}") boolean enabled,
            @Value("${pdf.execution-ledger.jdbc-url:}") String jdbcUrl,
            @Value("${pdf.execution-ledger.username:}") String username,
            @Value("${pdf.execution-ledger.password:}") String password,
            @Value("${pdf.execution-ledger.result-root:./data/signing-results}") String resultRoot
    ) {
        this.enabled = enabled;
        this.jdbcUrl = jdbcUrl;
        this.username = username;
        this.password = password;
        this.resultRoot = Path.of(resultRoot).toAbsolutePath().normalize();
    }

    public void requireReadyConfiguration() {
        if (!enabled || jdbcUrl.isBlank() || username.isBlank() || password.isBlank()) {
            throw new IllegalStateException("The PDF execution ledger is not fully configured");
        }
    }

    public boolean enabled() {
        return enabled;
    }

    public String jdbcUrl() {
        return jdbcUrl;
    }

    public String username() {
        return username;
    }

    public String password() {
        return password;
    }

    public Path resultRoot() {
        return resultRoot;
    }
}
