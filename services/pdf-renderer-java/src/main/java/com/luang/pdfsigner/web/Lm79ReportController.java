package com.luang.pdfsigner.web;

import com.luang.pdfsigner.dto.Lm79ReportPayload;
import com.luang.pdfsigner.service.Lm79ReportRenderer;
import com.luang.pdfsigner.service.PdfFiles;
import jakarta.servlet.http.HttpServletResponse;
import java.nio.file.Files;
import java.util.Map;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;

@RestController
public final class Lm79ReportController {
    private final Lm79ReportRenderer renderer;
    public Lm79ReportController(Lm79ReportRenderer renderer) { this.renderer = renderer; }

    @PostMapping(value = "/api/pdf/lm79-report", consumes = "application/json")
    public void render(@RequestBody Lm79ReportPayload payload, HttpServletResponse response) throws Exception {
        try (var files = new PdfFiles()) {
            var file = files.create();
            try (var output = Files.newOutputStream(file.toPath())) { renderer.render(payload, output); }
            response.setContentType("application/pdf");
            response.setHeader("Content-Disposition", "inline; filename=lm79-report.pdf");
            response.setContentLengthLong(file.length());
            try (var input = Files.newInputStream(file.toPath())) { input.transferTo(response.getOutputStream()); }
        }
    }

    @ExceptionHandler(IllegalArgumentException.class)
    public ResponseEntity<Map<String, String>> invalidPayload(IllegalArgumentException error) {
        return ResponseEntity.unprocessableEntity().body(Map.of("error", error.getMessage()));
    }
}
