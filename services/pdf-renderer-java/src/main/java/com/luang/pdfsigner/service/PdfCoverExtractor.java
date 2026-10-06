package com.luang.pdfsigner.service;

import java.io.File;
import java.io.IOException;
import java.io.InputStream;
import java.text.Normalizer;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.HashSet;
import java.util.Set;
import java.util.TreeMap;
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

    private static final Pattern REPORT_NUMBER = Pattern.compile(
            "(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*");

    private static final Map<String, List<String>> FIELD_ALIASES = Map.ofEntries(
            Map.entry("reportNumber", List.of("报告编号", "Report No", "Report No.", "Report Number", "Reference No", "Reference No.", "Reference Number")),
            Map.entry("productName", List.of("产品名称", "Product", "Product Name", "Test item description")),
            Map.entry("modelSpecification", List.of("型号规格", "规格型号", "型号", "Model", "Model Specification", "Model No", "Model No.", "Model/Type reference")),
            Map.entry("entrustCompany", List.of("委托单位", "申请人", "Applicant", "Entrust Company", "Applicant's name")),
            Map.entry("testItems", List.of("检测项目", "Test Items", "Test Item", "Test specification", "Test Specification", "Standard")),
            Map.entry("reportDate", List.of("报告日期", "签发日期", "Report Date", "Date of Test", "Date of issue"))
    );

    // These labels bound values without assigning them to unrelated output fields.
    private static final List<String> BOUNDARY_ONLY_LABELS = List.of(
            "参考标准", "接收日期", "地址", "制造商名称", "测试人员", "审核人员", "检测机构", "文件编号", "文件版本",
            "Reference Standard", "Received Date", "Address", "Manufacturer", "Manufacturer's name", "Tested by", "Approved by");
    private static final Pattern LABEL_PREFIX = Pattern.compile("^([\\p{L}][\\p{L}\\p{N}\\s/'’()._-]*)[:：]\\s*");
    private static final Set<String> NORMALIZED_FIELD_ALIASES;
    private static final Map<String, Pattern> LABEL_PATTERNS;

    static {
        Set<String> aliasSet = new HashSet<>();
        Map<String, Pattern> patterns = new HashMap<>();
        FIELD_ALIASES.values().forEach(list -> list.forEach(alias -> {
            aliasSet.add(normalizeAlias(alias));
            patterns.put(alias, buildLabelPattern(alias));
        }));
        BOUNDARY_ONLY_LABELS.forEach(alias -> {
            aliasSet.add(normalizeAlias(alias));
            patterns.put(alias, buildLabelPattern(alias));
        });
        NORMALIZED_FIELD_ALIASES = Set.copyOf(aliasSet);
        LABEL_PATTERNS = Map.copyOf(patterns);
    }

    /**
     * Extract cover information from PDF first page.
     *
     * @param stream PDF input stream
     * @return structured response
     * @throws IOException when PDF cannot be parsed
     */
    public CoverExtractionResponse extract(InputStream stream) throws IOException {
        try (PdfFiles files = new PdfFiles()) {
            File file = files.create();
            java.nio.file.Files.copy(stream, file.toPath(), java.nio.file.StandardCopyOption.REPLACE_EXISTING);
            return extract(file);
        }
    }

    public CoverExtractionResponse extract(File file) throws IOException {
        try (PDDocument document = Loader.loadPDF(file, PdfFiles.streamCache())) {
            if (document.getNumberOfPages() == 0) {
                log.warn("PDF contains no pages, skip cover extraction");
                return CoverExtractionResponse.empty();
            }

            PDFTextStripper stripper = new PDFTextStripper();
            stripper.setStartPage(1);
            stripper.setEndPage(1);
            stripper.setSortByPosition(true);
            String rawText = stripper.getText(document);
            String normalized = normalize(rawText);
            List<String> lines = normalized.lines()
                    .map(String::trim)
                    .filter(line -> !line.isEmpty())
                    .toList();

            Map<String, String> results = new HashMap<>();
            for (Map.Entry<String, List<String>> entry : FIELD_ALIASES.entrySet()) {
                String value = entry.getKey().equals("reportNumber")
                        ? findReportNumber(lines)
                        : findValue(lines, entry.getValue());
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
            List<String> inlineValues = extractInlineValues(line, aliases);
            if (!inlineValues.isEmpty()) return inlineValues.get(0);

            if (isBareLabel(line, aliases)) {
                String nextLineValue = extractFromNextLine(lines, i);
                if (nextLineValue != null) return nextLineValue;
            }
        }

        return null;
    }

    private String findReportNumber(List<String> lines) {
        List<String> aliases = FIELD_ALIASES.get("reportNumber");
        Set<String> candidates = new HashSet<>();
        for (int i = 0; i < lines.size(); i++) {
            String line = lines.get(i);
            for (String candidate : extractInlineValues(line, aliases)) {
                if (REPORT_NUMBER.matcher(candidate).matches()) candidates.add(candidate);
            }
            if (isBareLabel(line, aliases)) {
                String candidate = extractFromNextLine(lines, i);
                if (candidate != null && REPORT_NUMBER.matcher(candidate).matches()) candidates.add(candidate);
            }
        }
        // Conflicting Chinese and English labels do not establish one identity.
        return candidates.size() == 1 ? candidates.iterator().next() : null;
    }

    private boolean isBareLabel(String line, List<String> aliases) {
        return aliases.stream().anyMatch(alias -> normalizeAlias(line).equals(normalizeAlias(alias)));
    }

    private List<String> extractInlineValues(String line, List<String> aliases) {
        Map<Integer, String> values = new TreeMap<>();
        for (String alias : aliases) {
            Matcher matcher = LABEL_PATTERNS.get(alias).matcher(line);
            while (matcher.find()) {
                if (isNestedLabel(line, alias, matcher.start(), matcher.end())) continue;
                String remaining = line.substring(matcher.end());
                int end = remaining.length();
                for (Pattern boundary : LABEL_PATTERNS.values()) {
                    Matcher next = boundary.matcher(remaining);
                    if (next.find()) end = Math.min(end, next.start());
                }
                String value = sanitize(remaining.substring(0, end));
                if (value != null) values.putIfAbsent(matcher.start(), value);
            }
        }
        return List.copyOf(values.values());
    }

    private boolean isNestedLabel(String line, String alias, int start, int end) {
        for (Pattern pattern : LABEL_PATTERNS.values()) {
            Matcher outer = pattern.matcher(line);
            while (outer.find()) {
                if (outer.start() < start && outer.end() >= end) return true;
            }
        }
        // A known word inside an unknown heading is not a standalone label.
        Matcher heading = LABEL_PREFIX.matcher(line);
        return start > 0 && heading.find() && end <= heading.end()
                && !normalizeAlias(heading.group(1)).equals(normalizeAlias(alias));
    }

    private static Pattern buildLabelPattern(String alias) {
        String aliasPattern = aliasToPattern(alias);
        String regex = "(?i)(?:^|\\s+)" + aliasPattern + "[\\s.·•…‧°．_—-]*[:：]\\s*";
        return Pattern.compile(regex);
    }

    private static String aliasToPattern(String alias) {
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
            if (isFieldBoundary(candidate)) {
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

    private boolean isFieldBoundary(String line) {
        return NORMALIZED_FIELD_ALIASES.contains(normalizeAlias(line)) || LABEL_PREFIX.matcher(line).find();
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

}
