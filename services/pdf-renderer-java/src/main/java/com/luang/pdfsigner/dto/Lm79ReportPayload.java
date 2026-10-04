package com.luang.pdfsigner.dto;

import java.util.List;

/** Presentation values supplied by Laravel; no paths or remote URLs are accepted. */
public record Lm79ReportPayload(String reportNumber, String labName, String labAddress,
        String productName, String model, String applicant, String receivedDate, String issuedDate,
        List<Section> sections, List<byte[]> photos, List<List<Double>> spectrum, List<Appendix> appendices,
        DocumentInfo documentInfo) {
    public Lm79ReportPayload(String reportNumber, String labName, String labAddress,
            String productName, String model, String applicant, String receivedDate, String issuedDate,
            List<Section> sections, List<byte[]> photos, List<List<Double>> spectrum, List<Appendix> appendices) {
        this(reportNumber, labName, labAddress, productName, model, applicant, receivedDate, issuedDate,
                sections, photos, spectrum, appendices, null);
    }

    public record Section(String title, List<String> headers, List<List<String>> rows, String layout) {
        public Section(String title, List<String> headers, List<List<String>> rows) {
            this(title, headers, rows, "table");
        }
    }
    public record Appendix(String title, byte[] pdf) {}
    public record DocumentInfo(String fileNumber, String version, String website, String email) {}
}
