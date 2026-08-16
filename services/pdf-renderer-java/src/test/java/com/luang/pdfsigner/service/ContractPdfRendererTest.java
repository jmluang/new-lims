package com.luang.pdfsigner.service;

import static org.assertj.core.api.Assertions.assertThat;

import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.text.PDFTextStripper;
import org.junit.jupiter.api.Test;

class ContractPdfRendererTest {

    private final ContractPdfRenderer renderer = new ContractPdfRenderer();

    @Test
    void renderSampleOmitsTheEmptyPhotoPageAndKeepsRequiredSections() throws Exception {
        byte[] pdf = renderer.render(ContractPdfPayload.sample());
        assertThat(pdf).isNotEmpty();

        try (PDDocument document = Loader.loadPDF(pdf)) {
            assertThat(document.getNumberOfPages()).isEqualTo(6);
            PDFTextStripper stripper = new PDFTextStripper();
            String text = stripper.getText(document);
            assertThat(text).contains("检测报告");
            assertThat(text).contains("实验仪器设备清单");
        }
    }
}
