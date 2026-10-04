package com.luang.pdfsigner.web;

import com.luang.pdfsigner.dto.LightingReportPayload;
import com.luang.pdfsigner.service.LightingReportRenderer;
import com.luang.pdfsigner.service.PdfFiles;
import jakarta.servlet.http.HttpServletResponse;
import java.io.BufferedOutputStream;
import java.nio.file.Files;
import java.util.Map;
import org.springframework.http.HttpHeaders;
import org.springframework.http.MediaType;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RestController;

@RestController
public final class LightingReportController {
    private final LightingReportRenderer renderer;

    public LightingReportController(LightingReportRenderer renderer) {
        this.renderer = renderer;
    }

    @PostMapping(value = "/api/pdf/lighting-report", consumes = MediaType.APPLICATION_JSON_VALUE)
    public void render(@RequestBody LightingReportPayload payload, HttpServletResponse response) throws Exception {
        try (PdfFiles files = new PdfFiles()) {
            var output = files.create();
            try (var stream = new BufferedOutputStream(Files.newOutputStream(output.toPath()))) {
                renderer.render(payload, stream);
            }
            response.setContentType(MediaType.APPLICATION_PDF_VALUE);
            response.setHeader(HttpHeaders.CONTENT_DISPOSITION, "attachment; filename=lighting-report.pdf");
            response.setContentLengthLong(output.length());
            try (var input = Files.newInputStream(output.toPath())) {
                input.transferTo(response.getOutputStream());
            }
        }
    }

    @ExceptionHandler(IllegalArgumentException.class)
    public ResponseEntity<Map<String, String>> invalidPayload(IllegalArgumentException exception) {
        return ResponseEntity.unprocessableEntity().body(Map.of("error", exception.getMessage()));
    }
}
