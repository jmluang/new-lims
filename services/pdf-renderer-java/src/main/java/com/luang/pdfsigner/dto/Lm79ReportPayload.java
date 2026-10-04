package com.luang.pdfsigner.dto;

import java.util.List;

/** Presentation values supplied by Laravel; no paths or remote URLs are accepted. */
public record Lm79ReportPayload(String reportNumber, String labName, String labAddress,
        String productName, String model, String applicant, String receivedDate, String issuedDate,
        List<Section> sections, List<byte[]> photos, List<List<Double>> spectrum, List<Appendix> appendices) {
    public record Section(String title, List<String> headers, List<List<String>> rows) {}
    public record Appendix(String title, byte[] pdf) {}
}
