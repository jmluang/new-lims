package com.luang.pdfsigner.dto;

import java.util.List;

/** Values are supplied by the caller; instrument-file parsing is outside PDF rendering. */
public record LightingReportPayload(
        Header header,
        Cover cover,
        Form form,
        TestSetup testSetup,
        Electrical electrical,
        Colorimetry colorimetry,
        Signatures signatures,
        List<byte[]> photos
) {
    public record Header(String company, String fileNumber, String version,
                         String address, String website, String email) {}

    public record Cover(String reportNumber, String receivedDate, String issuedDate) {}

    /** Display values originating from the FORM submission, including their units. */
    public record Form(
            String applicantName, String applicantAddress,
            String manufacturerName, String manufacturerAddress,
            String productName, String model, String referenceStandard,
            String brand, String sampleQuantity, String ratedVoltage, String ratedPower,
            String nominalLuminousFlux, String nominalCct,
            String laboratoryName, String laboratoryAddress, String testItem,
            String receivedDate, String testPeriod,
            String sampleDescription, String serialNumber,
            String ambientTemperature, String relativeHumidity, String airVelocity,
            String testDirection, String stabilizationTime,
            String currentThd, String voltageRegulation, String supplyThd
    ) {}

    public record TestSetup(String cInterval, String cRange, String gammaInterval,
                            String gammaRange, String distance, String angularAccuracy,
                            String cPlanes, String gammaPoints) {}

    /** Display values extracted from GOS measurements. */
    public record Electrical(String inputVoltage, String inputCurrent, String inputPower,
                             String powerFactor, String frequency) {}

    /** Display values extracted from HAAS measurements. */
    public record Colorimetry(String cct, String duv, String ra, String r9,
                             String xy, String uv, String rf, String rg) {}

    /** Images are encoded as Base64 in JSON; no local paths or remote URLs are accepted. */
    public record Signatures(String testedBy, byte[] testedImage,
                             String reviewedBy, byte[] reviewedImage,
                             String approvedBy, byte[] approvedImage, String date) {}
}
