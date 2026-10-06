package com.luang.pdfsigner;

import com.luang.pdfsigner.service.PdfCoverExtractor;
import com.luang.pdfsigner.service.SignerService;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.font.PDType0Font;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.ValueSource;
import org.springframework.mock.web.MockMultipartFile;
import static org.assertj.core.api.Assertions.assertThat;

class PdfReportNumberExtractionTest {
    @TempDir Path temp;

    @ParameterizedTest
    @ValueSource(strings = {"报告编号: XPD20261005-003", "报告编号： XPD20261005-003",
            "Report No.: XPD20261005-003", "Report Number: XPD20261005-003",
            "Reference No.: XPD20261005-003"})
    void recognizesInlineChineseAndEnglishLabels(String line) throws Exception {
        assertThat(new PdfCoverExtractor().extract(cover(line).toFile()).reportNumber()).isEqualTo("XPD20261005-003");
    }

    @Test
    void readsSeparatedCellsByTheirVisualPosition() throws Exception {
        Path path = temp.resolve("cells.pdf");
        try (var document = new PDDocument(); var input = getClass().getResourceAsStream("/fonts/LimsSongSC-Regular.ttf")) {
            document.addPage(new PDPage());
            var font = PDType0Font.load(document, input);
            try (var stream = new PDPageContentStream(document, document.getPage(0))) {
                text(stream, font, "报告编号:", 50, 700);
                text(stream, font, "申请人: Example Lighting Ltd", 50, 670);
                text(stream, font, "XPD20261005-003", 210, 700);
            }
            document.save(path.toFile());
        }
        assertThat(new PdfCoverExtractor().extract(path.toFile()).reportNumber()).isEqualTo("XPD20261005-003");
    }

    @Test
    void acceptsAnImmediatelyFollowingNumber() throws Exception {
        assertThat(new PdfCoverExtractor().extract(cover("Report Number:", "XPD20261005-003").toFile()).reportNumber())
                .isEqualTo("XPD20261005-003");
    }

    @Test
    void rejectsAnotherFieldInsteadOfSearchingFurtherForAGuess() throws Exception {
        var file = cover("报告编号:", "申请人: Example Lighting Ltd", "AB123");
        assertThat(new PdfCoverExtractor().extract(file.toFile()).reportNumber()).isNull();
    }

    @Test
    void rejectsConflictingBilingualNumbers() throws Exception {
        var file = cover("报告编号: XPD20261005-003", "Report Number: XPD20261005-004");
        assertThat(new PdfCoverExtractor().extract(file.toFile()).reportNumber()).isNull();
    }

    @Test
    void acceptsMatchingBilingualNumbers() throws Exception {
        var file = cover("报告编号: XPD20261005-003", "Report Number: XPD20261005-003");
        assertThat(new PdfCoverExtractor().extract(file.toFile()).reportNumber()).isEqualTo("XPD20261005-003");
    }

    @ParameterizedTest
    @ValueSource(strings = {"申请人: Example Lighting Ltd", "20261005", "XPD", "XPD 20261005"})
    void omitsTheQrCodeWithoutAValidExtractionOrManualNumber(String candidate) throws Exception {
        var source = cover("Report Number:", candidate);
        var upload = new MockMultipartFile("pdf", "report.pdf", "application/pdf", Files.readAllBytes(source));
        try (var result = new SignerService().processToFile(upload, null, null, List.of(), "custom",
                null, null, null, null, "SHA256", false, null, null, null)) {
            assertThat(result.coverFields().reportNumber()).isNull();
            try (var document = Loader.loadPDF(result.pdfFile())) {
                assertThat(document.getPage(0).getResources().getXObjectNames()).isEmpty();
            }
        }
    }

    private Path cover(String... lines) throws Exception {
        Path path = temp.resolve("cover.pdf");
        try (var document = new PDDocument(); var input = getClass().getResourceAsStream("/fonts/LimsSongSC-Regular.ttf")) {
            document.addPage(new PDPage());
            var font = PDType0Font.load(document, input);
            try (var stream = new PDPageContentStream(document, document.getPage(0))) {
                for (int i = 0; i < lines.length; i++) text(stream, font, lines[i], 50, 700 - i * 30);
            }
            document.save(path.toFile());
        }
        return path;
    }

    private void text(PDPageContentStream stream, PDType0Font font, String value, float x, float y) throws Exception {
        stream.beginText();
        stream.setFont(font, 12);
        stream.newLineAtOffset(x, y);
        stream.showText(value);
        stream.endText();
    }
}
