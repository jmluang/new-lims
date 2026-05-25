package com.luang.pdfsigner.dto;

import java.util.Objects;

public record CoverExtractionResponse(
        String reportNumber,
        String productName,
        String modelSpecification,
        String entrustCompany,
        String testItems,
        String reportDate
) {
    public boolean isEmpty() {
        return reportNumber == null
                && productName == null
                && modelSpecification == null
                && entrustCompany == null
                && testItems == null
                && reportDate == null;
    }

    public static CoverExtractionResponse empty() {
        return new CoverExtractionResponse(null, null, null, null, null, null);
    }

    public String extractionStatus() {
        long filled = Objects.requireNonNullElse(reportNumber, "").isEmpty() ? 0 : 1;
        filled += Objects.requireNonNullElse(productName, "").isEmpty() ? 0 : 1;
        filled += Objects.requireNonNullElse(modelSpecification, "").isEmpty() ? 0 : 1;
        filled += Objects.requireNonNullElse(entrustCompany, "").isEmpty() ? 0 : 1;
        filled += Objects.requireNonNullElse(testItems, "").isEmpty() ? 0 : 1;
        filled += Objects.requireNonNullElse(reportDate, "").isEmpty() ? 0 : 1;

        if (filled == 0) {
            return "failed";
        }
        if (filled == 6) {
            return "success";
        }
        return "partial";
    }
}
