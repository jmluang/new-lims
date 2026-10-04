package com.luang.pdfsigner.web;

import com.luang.pdfsigner.service.PdfWorkLimiter;
import jakarta.servlet.FilterChain;
import jakarta.servlet.ServletException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.io.IOException;
import java.util.Set;
import org.springframework.core.Ordered;
import org.springframework.core.annotation.Order;
import org.springframework.stereotype.Component;
import org.springframework.web.filter.OncePerRequestFilter;

/** Runs after HMAC headers and before multipart parsing; holds admission through response writing. */
@Component
@Order(Ordered.HIGHEST_PRECEDENCE + 20)
public final class PdfConcurrencyFilter extends OncePerRequestFilter {
    private static final Set<String> PATHS = Set.of(
            "/api/pdf/process", "/api/pdf/extract-cover", "/api/pdf/contract", "/api/pdf/entrust-order", "/api/pdf/lighting-report", "/api/pdf/lm79-report",
            "/internal/pdf/signatures/inspect", "/internal/pdf/signatures/prepare",
            "/internal/pdf/signatures/finalize-unsigned", "/internal/pdf/signatures/verify");
    private final PdfWorkLimiter limiter;

    public PdfConcurrencyFilter(PdfWorkLimiter limiter) {
        this.limiter = limiter;
    }

    @Override
    protected boolean shouldNotFilter(HttpServletRequest request) {
        // Signing admission belongs inside the execution ledger, before loading PDF bytes.
        // Status/result requests acquire only when they actually need deadline recovery.
        return !"POST".equals(request.getMethod())
                || !PATHS.contains(request.getRequestURI().substring(request.getContextPath().length()));
    }

    @Override
    protected void doFilterInternal(HttpServletRequest request, HttpServletResponse response, FilterChain chain)
            throws ServletException, IOException {
        PdfWorkLimiter.Permit permit;
        try {
            permit = limiter.acquire();
        } catch (PdfWorkLimiter.BusyException exception) {
            response.setStatus(HttpServletResponse.SC_SERVICE_UNAVAILABLE);
            response.setContentType("application/json");
            response.setHeader("Retry-After", "1");
            response.getWriter().write("{\"success\":false,\"error\":\"PDF_BUSY\"}");
            return;
        }
        try (permit) {
            chain.doFilter(request, response);
        }
    }
}
