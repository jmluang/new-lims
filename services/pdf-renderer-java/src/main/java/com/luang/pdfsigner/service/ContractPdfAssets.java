package com.luang.pdfsigner.service;

import java.awt.Color;
import java.io.ByteArrayInputStream;
import java.io.IOException;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDFont;
import org.apache.pdfbox.pdmodel.font.PDType0Font;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.core.io.ClassPathResource;

public final class ContractPdfAssets {

    private static final Logger log = LoggerFactory.getLogger(ContractPdfAssets.class);

    private ContractPdfAssets() {
    }

    public static final float PAGE_WIDTH = PDRectangle.A4.getWidth();
    public static final float PAGE_HEIGHT = PDRectangle.A4.getHeight();

    public static final Color BRAND_BLUE = new Color(0x00, 0x66, 0xCC);
    public static final float RULE_STROKE_WIDTH = 1.4f;

    private static final String[] FONT_CANDIDATES = new String[] {
            "/fonts/ms-song.ttf",
            "/fonts/SourceHanSerifSC-VF.ttf",
            "/fonts/SourceHanSerifHC-VF.ttf",
    };

    private static final String[] SYSTEM_FONT_CANDIDATES = new String[] {
            System.getenv("PDF_FONT_PATH"),
            "/opt/fonts/NotoSansCJKsc-Regular.ttf",
    };

    private static final Map<String, byte[]> FONT_DATA_CACHE = new ConcurrentHashMap<>();

    public static PDFont loadPrimaryFont(PDDocument document) throws IOException {
        for (String candidate : FONT_CANDIDATES) {
            String normalized = candidate.startsWith("/") ? candidate.substring(1) : candidate;
            ClassPathResource resource = new ClassPathResource(normalized);
            if (!resource.exists()) {
                continue;
            }
            try {
                byte[] fontBytes = FONT_DATA_CACHE.get(normalized);
                if (fontBytes == null) {
                    try (InputStream in = resource.getInputStream()) {
                        fontBytes = in.readAllBytes();
                        FONT_DATA_CACHE.put(normalized, fontBytes);
                    }
                }

                log.info("Loaded contract font from classpath: {}", normalized);
                return PDType0Font.load(document, new ByteArrayInputStream(fontBytes));
            } catch (IOException ex) {
                log.warn("Failed to load font {}: {}", normalized, ex.getMessage());
            }
        }

        for (String candidate : SYSTEM_FONT_CANDIDATES) {
            if (candidate == null || candidate.isBlank()) {
                continue;
            }
            Path path = Path.of(candidate);
            if (!Files.isRegularFile(path) || !Files.isReadable(path)) {
                continue;
            }
            try {
                byte[] fontBytes = FONT_DATA_CACHE.get(candidate);
                if (fontBytes == null) {
                    fontBytes = Files.readAllBytes(path);
                    FONT_DATA_CACHE.put(candidate, fontBytes);
                }

                log.info("Loaded contract font from filesystem: {}", candidate);
                return PDType0Font.load(document, new ByteArrayInputStream(fontBytes));
            } catch (IOException ex) {
                log.warn("Failed to load font {}: {}", candidate, ex.getMessage());
            }
        }

        throw new IOException("No suitable CJK font found for contract renderer");
    }

    public static float mmToPt(double mm) {
        return (float) (mm * 72d / 25.4d);
    }
}
