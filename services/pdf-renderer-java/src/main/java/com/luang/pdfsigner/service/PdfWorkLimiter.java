package com.luang.pdfsigner.service;

import java.util.concurrent.Semaphore;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Component;

/** Shared admission boundary for HTTP processing, signing and deadline recovery. */
@Component
public final class PdfWorkLimiter {
    private final Semaphore jobs;

    public PdfWorkLimiter(@Value("${pdf.max-concurrent-jobs:1}") int maximumJobs) {
        if (maximumJobs < 1) {
            throw new IllegalArgumentException("pdf.max-concurrent-jobs must be positive");
        }
        jobs = new Semaphore(maximumJobs);
    }

    public Permit acquire() {
        // Never wait inside an authenticated request or an execution lease.
        if (!jobs.tryAcquire()) {
            throw new BusyException();
        }
        return new Permit();
    }

    public final class Permit implements AutoCloseable {
        private boolean closed;

        private Permit() {}

        @Override
        public void close() {
            if (!closed) {
                closed = true;
                jobs.release();
            }
        }
    }

    public static final class BusyException extends IllegalStateException {
        public BusyException() {
            super("PDF_BUSY");
        }
    }
}
