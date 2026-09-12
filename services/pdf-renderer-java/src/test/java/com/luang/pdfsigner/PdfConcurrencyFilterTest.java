package com.luang.pdfsigner;

import com.luang.pdfsigner.service.PdfWorkLimiter;
import com.luang.pdfsigner.web.PdfConcurrencyFilter;
import java.io.IOException;
import java.util.List;
import java.util.concurrent.*;
import org.junit.jupiter.api.Test;
import org.springframework.mock.web.MockHttpServletRequest;
import org.springframework.mock.web.MockHttpServletResponse;
import static org.assertj.core.api.Assertions.*;

class PdfConcurrencyFilterTest {
    @Test
    void rejectsWithoutWaitingAndHoldsPermitThroughResponseFailure() throws Exception {
        var limiter = new PdfWorkLimiter(1);
        var filter = new PdfConcurrencyFilter(limiter);
        var entered = new CountDownLatch(1);
        var finish = new CountDownLatch(1);
        var executor = Executors.newSingleThreadExecutor();
        try {
            Future<?> first = executor.submit(() -> {
                assertThatThrownBy(() -> filter.doFilter(request("/api/pdf/process"), new MockHttpServletResponse(), (req, res) -> {
                    entered.countDown();
                    try {
                        assertThat(finish.await(5, TimeUnit.SECONDS)).isTrue();
                    } catch (InterruptedException e) {
                        throw new AssertionError(e);
                    }
                    throw new IOException("Response disconnected");
                })).isInstanceOf(IOException.class);
            });
            assertThat(entered.await(5, TimeUnit.SECONDS)).isTrue();
            for (String path : List.of("/api/pdf/process", "/api/pdf/extract-cover", "/api/pdf/contract",
                    "/api/pdf/entrust-order", "/internal/pdf/signatures/inspect", "/internal/pdf/signatures/prepare",
                    "/internal/pdf/signatures/finalize-unsigned", "/internal/pdf/signatures/verify")) {
                var response = new MockHttpServletResponse();
                filter.doFilter(request(path), response, (req, res) -> { throw new AssertionError("Must not enter controller"); });
                assertThat(response.getStatus()).isEqualTo(503);
                assertThat(response.getContentAsString()).contains("PDF_BUSY");
            }
            assertThatThrownBy(limiter::acquire).isInstanceOf(PdfWorkLimiter.BusyException.class);
            finish.countDown();
            first.get(5, TimeUnit.SECONDS);
            var response = new MockHttpServletResponse();
            filter.doFilter(request("/api/pdf/process"), response, (req, res) -> res.getWriter().write("ok"));
            assertThat(response.getContentAsString()).isEqualTo("ok");
        } finally {
            finish.countDown();
            executor.shutdownNow();
        }
    }

    @Test
    void leavesLedgerAdmissionAndOrdinaryStatusToTheExecutionService() throws Exception {
        var limiter = new PdfWorkLimiter(1);
        var filter = new PdfConcurrencyFilter(limiter);
        try (var permit = limiter.acquire()) {
            for (String path : List.of("/internal/pdf/signatures/sign-existing-field", "/api/pdf/health",
                    "/internal/pdf/signatures/executions/00000000-0000-0000-0000-000000000001")) {
                var request = new MockHttpServletRequest(path.endsWith("sign-existing-field") ? "POST" : "GET", path);
                var response = new MockHttpServletResponse();
                filter.doFilter(request, response, (req, res) -> res.getWriter().write("ok"));
                assertThat(response.getContentAsString()).isEqualTo("ok");
            }
        }
        assertThatThrownBy(() -> new PdfWorkLimiter(0)).isInstanceOf(IllegalArgumentException.class);
    }

    @Test
    void configuredCapacityAndIdempotentReleaseDoNotLeakPermits() {
        var limiter = new PdfWorkLimiter(2);
        try (var first = limiter.acquire(); var second = limiter.acquire()) {
            assertThatThrownBy(limiter::acquire).isInstanceOf(PdfWorkLimiter.BusyException.class);
            first.close();
            first.close();
            try (var replacement = limiter.acquire()) {
                assertThatThrownBy(limiter::acquire).isInstanceOf(PdfWorkLimiter.BusyException.class);
            }
        }
    }

    private static MockHttpServletRequest request(String path) {
        return new MockHttpServletRequest("POST", path);
    }
}
