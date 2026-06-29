package com.luang.pdfsigner.dto;

import com.fasterxml.jackson.annotation.JsonAlias;
import com.fasterxml.jackson.annotation.JsonProperty;
import java.util.List;

public record EntrustOrderPayload(
        Base base,
        Party client,
        Party manufacturer,
        Party producer,
        Requirements requirements,
        Sample sample,
        List<Sample> samples,
        Logistics logistics,
        Signatures signatures,
        Meta meta
) {
    public List<Sample> effectiveSamples() {
        if (samples != null && !samples.isEmpty()) {
            return samples;
        }
        return sample == null ? List.of() : List.of(sample);
    }

    public record Base(
            @JsonProperty("entrust_date") String entrustDate,
            EnumValue urgency,
            @JsonProperty("urgency_options") List<EnumValue> urgencyOptions,
            @JsonProperty("planned_end_date") String plannedEndDate,
            @JsonProperty("entrust_number") String entrustNumber,
            @JsonProperty("contract_number") String contractNumber
    ) {}

    public record Party(
            @JsonProperty("company_name")
            @JsonAlias("name") String companyName,
            String contact,
            String phone,
            String address,
            String email
    ) {}

    public record Requirements(
            @JsonProperty("report_forms") List<EnumValue> reportForms,
            @JsonProperty("report_form_options") List<EnumValue> reportFormOptions,
            @JsonProperty("sample_return") EnumValue sampleReturn,
            @JsonProperty("sample_return_options") List<EnumValue> sampleReturnOptions,
            @JsonProperty("report_submission") EnumValue reportSubmission,
            @JsonProperty("report_submission_options") List<EnumValue> reportSubmissionOptions,
            @JsonProperty("allow_subcontract") EnumValue allowSubcontract,
            @JsonProperty("allow_subcontract_options") List<EnumValue> allowSubcontractOptions,
            String remarks,
            List<Standard> standards
    ) {}

    public record EnumValue(String value, String label) {}

    public record Standard(
            @JsonProperty("standard_code") String standardCode,
            @JsonProperty("qualification_requirement") String qualificationRequirement,
            @JsonProperty("report_language") String reportLanguage,
            String notes,
            Integer position
    ) {}

    public record Sample(
            String name,
            String model,
            String voltage,
            String current,
            String power,
            String frequency,
            Integer quantity,
            @JsonProperty("quantity_unit") String quantityUnit,
            EnumValue condition,
            @JsonProperty("condition_note") String conditionNote,
            String remarks
    ) {}

    public record Logistics(
            @JsonProperty("laboratory_name") String laboratoryName,
            @JsonProperty("laboratory_address") String laboratoryAddress,
            @JsonProperty("laboratory_contact") String laboratoryContact,
            @JsonProperty("laboratory_phone") String laboratoryPhone,
            @JsonProperty("shipping_notes") String shippingNotes
    ) {}

    public record Signatures(
            @JsonProperty("client_signature_name") String clientSignatureName,
            @JsonProperty("client_signed_at") String clientSignedAt,
            @JsonProperty("lab_resource_confirmed_by") String labResourceConfirmedBy,
            @JsonProperty("lab_resource_confirmed_at") String labResourceConfirmedAt,
            @JsonProperty("lab_reviewed_by") String labReviewedBy,
            @JsonProperty("lab_reviewed_at") String labReviewedAt
    ) {}

    public record Meta(
            EnumValue status,
            @JsonProperty("generated_at") String generatedAt
    ) {}
}
