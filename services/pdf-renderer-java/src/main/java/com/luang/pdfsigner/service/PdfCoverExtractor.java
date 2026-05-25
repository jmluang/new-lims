package com.luang.pdfsigner.service;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.text.Normalizer;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.HashSet;
import java.util.Set;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.text.PDFTextStripper;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import com.luang.pdfsigner.dto.CoverExtractionResponse;

/**
 * Extracts predefined cover fields from the first page of a PDF.
 */
public class PdfCoverExtractor {

    private static final Logger log = LoggerFactory.getLogger(PdfCoverExtractor.class);

    private static final Map<String, List<String>> FIELD_ALIASES = Map.ofEntries(
            Map.entry("reportNumber", List.of("报告编号", "Report No", "Report No.", "Report Number", "Reference No", "Reference No.", "Reference Number")),
            Map.entry("productName", List.of("产品名称", "Product", "Product Name", "Test item description")),
            Map.entry("modelSpecification", List.of("型号规格", "Model", "Model Specification", "Model No", "Model No.", "Model/Type reference")),
            Map.entry("entrustCompany", List.of("委托单位", "Applicant", "Entrust Company", "Applicant's name")),
            Map.entry("testItems", List.of("检测项目", "Test Items", "Test Item", "Test specification", "Test Specification", "Standard")),
            Map.entry("reportDate", List.of("报告日期", "Report Date", "Date of Test", "Date of issue"))
    );

    private static final Set<String> NORMALIZED_FIELD_ALIASES;

    static {
        Set<String> aliasSet = new HashSet<>();
        FIELD_ALIASES.values().forEach(list -> list.forEach(alias -> aliasSet.add(normalizeAlias(alias))));
        NORMALIZED_FIELD_ALIASES = Set.copyOf(aliasSet);
    }

    /**
     * Extract cover information from PDF first page.
     *
     * @param stream PDF input stream
     * @return structured response
     * @throws IOException when PDF cannot be parsed
     */
    public CoverExtractionResponse extract(InputStream stream) throws IOException {
        byte[] pdfBytes = toByteArray(stream);

        try (PDDocument document = Loader.loadPDF(pdfBytes)) {
            if (document.getNumberOfPages() == 0) {
                log.warn("PDF contains no pages, skip cover extraction");
                return CoverExtractionResponse.empty();
            }

            PDFTextStripper stripper = new PDFTextStripper();
            stripper.setStartPage(1);
            stripper.setEndPage(1);
            String rawText = stripper.getText(document);
            String normalized = normalize(rawText);
            List<String> lines = normalized.lines()
                    .map(String::trim)
                    .filter(line -> !line.isEmpty())
                    .toList();

            Map<String, String> results = new HashMap<>();
            for (Map.Entry<String, List<String>> entry : FIELD_ALIASES.entrySet()) {
                String value = findValue(lines, entry.getValue());
                results.put(entry.getKey(), value);
            }

            log.info("Cover extraction summary: {}", results);

            return new CoverExtractionResponse(
                    results.get("reportNumber"),
                    results.get("productName"),
                    results.get("modelSpecification"),
                    results.get("entrustCompany"),
                    results.get("testItems"),
                    results.get("reportDate")
            );
        }
    }

    private String normalize(String text) {
        if (text == null) {
            return "";
        }
        String decomposed = Normalizer.normalize(text, Normalizer.Form.NFKC);
        decomposed = decomposed.replace('\u00A0', ' '); // non-breaking space
        decomposed = decomposed.replace('\u3000', ' '); // full-width space
        return decomposed;
    }

    private String findValue(List<String> lines, List<String> aliases) {
        for (int i = 0; i < lines.size(); i++) {
            String line = lines.get(i);
            if (!aliasMatches(line, aliases)) {
                continue;
            }

            String inlineValue = extractInlineValue(line, aliases);
            if (inlineValue != null) {
                return inlineValue;
            }

            String nextLineValue = extractFromNextLine(lines, i);
            if (nextLineValue != null) {
                return nextLineValue;
            }
        }

        return null;
    }

    private boolean aliasMatches(String line, List<String> aliases) {
        String normalizedLine = normalizeAlias(line);
        for (String alias : aliases) {
            if (normalizedLine.startsWith(normalizeAlias(alias))) {
                return true;
            }
        }
        return false;
    }

    private String extractInlineValue(String line, List<String> aliases) {
        for (String alias : aliases) {
            Pattern pattern = buildInlinePattern(alias);
            Matcher matcher = pattern.matcher(line);
            if (matcher.find()) {
                return sanitize(matcher.group(1));
            }
        }
        return null;
    }

    private Pattern buildInlinePattern(String alias) {
        String aliasPattern = aliasToPattern(alias);
        // 改进：要求冒号前有任意字符（非冒号），冒号后捕获值
        // 这样可以正确处理 "Product Name.................. : Value" 格式
        String regex = "(?i)^" + aliasPattern + "[^:]+[:：]\\s*(.+)$";
        return Pattern.compile(regex);
    }

    private String aliasToPattern(String alias) {
        String trimmed = alias.trim();
        if (trimmed.isEmpty()) {
            return "";
        }
        String[] parts = trimmed.split("\\s+");
        StringBuilder builder = new StringBuilder();
        for (int i = 0; i < parts.length; i++) {
            if (i > 0) {
                builder.append("\\s*");
            }
            builder.append(Pattern.quote(parts[i]));
        }
        return builder.toString();
    }

    private String extractFromNextLine(List<String> lines, int index) {
        for (int i = index + 1; i < lines.size(); i++) {
            String candidate = lines.get(i);
            if (isAliasLine(candidate)) {
                break;
            }
            String sanitized = sanitize(candidate);
            // 跳过只有标点符号的无效值（如单独的 ":" 或 "...."）
            if (sanitized != null && !sanitized.matches("^[\\.\\:：\\-\\s]+$")) {
                return sanitized;
            }
        }
        return null;
    }

    private boolean isAliasLine(String line) {
        return NORMALIZED_FIELD_ALIASES.contains(normalizeAlias(line));
    }

    private static String normalizeAlias(String value) {
        if (value == null) {
            return "";
        }
        String lowered = value.toLowerCase(Locale.ROOT);
        StringBuilder builder = new StringBuilder(lowered.length());
        for (int i = 0; i < lowered.length(); i++) {
            char ch = lowered.charAt(i);
            if (Character.isWhitespace(ch)) {
                continue;
            }
            if (isIgnorablePunctuation(ch)) {
                continue;
            }
            builder.append(ch);
        }
        return builder.toString();
    }

    private static boolean isIgnorablePunctuation(char ch) {
        return ch == '.'
                || ch == '·'
                || ch == '•'
                || ch == '…'
                || ch == '‧'
                || ch == '°'
                || ch == ':'
                || ch == '：'
                || ch == '-'
                || ch == '—'
                || ch == '_'
                || ch == '．';
    }

    private String sanitize(String value) {
        if (value == null) {
            return null;
        }
        String trimmed = value.trim();
        return trimmed.isEmpty() ? null : trimmed;
    }

    private byte[] toByteArray(InputStream stream) throws IOException {
        ByteArrayOutputStream buffer = new ByteArrayOutputStream();
        byte[] data = new byte[8192];
        int n;
        while ((n = stream.read(data)) != -1) {
            buffer.write(data, 0, n);
        }
        return buffer.toByteArray();
    }
}
