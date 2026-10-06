package com.luang.pdfsigner;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.luang.pdfsigner.dto.CoverExtractionResponse;
import com.luang.pdfsigner.service.PdfCoverExtractor;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.stream.Stream;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.font.PDType0Font;
import org.junit.jupiter.api.Assumptions;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.Arguments;
import org.junit.jupiter.params.provider.MethodSource;
import org.assertj.core.api.SoftAssertions;
import static org.assertj.core.api.Assertions.assertThat;

class PdfCoverCorpusTest {
    @TempDir Path temp;

    static Stream<Arguments> covers() {
        return Stream.of(
                Arguments.of("Chinese formal cover", new String[]{
                        "报告编号：RPT20261006-001", "产品名称：LED 面板灯", "型号规格：MODEL001",
                        "委托单位：Example Lighting Ltd", "检测项目：委托测试", "报告日期：2026/10/6"},
                        new CoverExtractionResponse("RPT20261006-001", "LED 面板灯", "MODEL001", "Example Lighting Ltd", "委托测试", "2026/10/6")),
                Arguments.of("Chinese alternative prefix", new String[]{
                        "报告编号：LAB20261006-002", "产品名称：柔性洗墙灯", "型号规格：MODEL002",
                        "委托单位：Example Lighting Ltd", "检测项目：委托测试", "报告日期：2026/10/6"},
                        new CoverExtractionResponse("LAB20261006-002", "柔性洗墙灯", "MODEL002", "Example Lighting Ltd", "委托测试", "2026/10/6")),
                Arguments.of("LM79 cover labels", new String[]{
                        "报告编号：RPT20261006-003", "申请人：Example Lighting Ltd", "参考标准：LM79",
                        "产品名称：Backlit panel light", "型号：600*600", "接收日期：2026-09-29", "签发日期：2026-10-06"},
                        new CoverExtractionResponse("RPT20261006-003", "Backlit panel light", "600*600", "Example Lighting Ltd", null, "2026-10-06")),
                Arguments.of("Empty fields must not consume later labels", new String[]{
                        "报告编号：RPT20261006-004", "申请人：", "地址：", "制造商名称：", "产品名称：", "型号：",
                        "接收日期：", "签发日期：", "测试人员：", "审核人员：", "Example Laboratory 第 1 页 共 1 页"},
                        new CoverExtractionResponse("RPT20261006-004", null, null, null, null, null)),
                Arguments.of("English dotted leaders", new String[]{
                        "Report No............: RPT20261006-005", "Product Name: Indoor panel", "Model No.: MODEL005",
                        "Applicant's name: Example Lighting Ltd", "Test Items: Photometry", "Date of issue: 2026-10-06"},
                        new CoverExtractionResponse("RPT20261006-005", "Indoor panel", "MODEL005", "Example Lighting Ltd", "Photometry", "2026-10-06")),
                Arguments.of("Adjacent fields on the same row", new String[]{
                        "Report Number: RPT20261006-006", "Product Name: Panel light   Model No.: MODEL006",
                        "Applicant: Example Lighting Ltd   Test Items: Photometry", "Report Date: 2026-10-06"},
                        new CoverExtractionResponse("RPT20261006-006", "Panel light", "MODEL006", "Example Lighting Ltd", "Photometry", "2026-10-06")),
                Arguments.of("Immediate next-line values", new String[]{
                        "Report Number:", "RPT20261006-007", "Product Name:", "Panel light", "Model Specification:", "MODEL007",
                        "Applicant:", "Example Lighting Ltd", "Test Items:", "Photometry", "Report Date:", "2026-10-06"},
                        new CoverExtractionResponse("RPT20261006-007", "Panel light", "MODEL007", "Example Lighting Ltd", "Photometry", "2026-10-06")),
                Arguments.of("Unknown heading cannot become a value", new String[]{
                        "Report Number: RPT20261006-008", "Product Name:", "Manufacturer's name: Example Factory",
                        "Product Information: This is a heading", "Model No.: MODEL008"},
                        new CoverExtractionResponse("RPT20261006-008", null, "MODEL008", null, null, null)),
                Arguments.of("Conflicting bilingual numbers on the same row", new String[]{
                        "报告编号: RPT20261006-009   Report Number: RPT20261006-010"},
                        CoverExtractionResponse.empty()),
                Arguments.of("Reference standard is a boundary, not a test item", new String[]{
                        "Report Number: RPT20261006-011", "Reference Standard: LM79",
                        "Applicant: Example Lighting Ltd   Reference Standard: LM79"},
                        new CoverExtractionResponse("RPT20261006-011", null, null, "Example Lighting Ltd", null, null)),
                Arguments.of("An alias inside an unknown label is not that field", new String[]{
                        "Report Number: RPT20261006-012", "Other Product Name: Unrelated heading", "Model No.: MODEL012"},
                        new CoverExtractionResponse("RPT20261006-012", null, "MODEL012", null, null, null)),
                Arguments.of("Ordinary colons in a bound value are preserved", new String[]{
                        "Report Number: RPT20261006-013", "Product Name: LED module: indoor", "Model No.: MODEL013"},
                        new CoverExtractionResponse("RPT20261006-013", "LED module: indoor", "MODEL013", null, null, null)),
                Arguments.of("Existing standalone Standard alias retains its mapping", new String[]{
                        "Report Number: RPT20261006-014", "Standard: LM79"},
                        new CoverExtractionResponse("RPT20261006-014", null, null, null, "LM79", null))
        );
    }

    @ParameterizedTest(name = "{0}")
    @MethodSource("covers")
    void checksEveryFieldAcrossCoverFormats(String name, String[] lines, CoverExtractionResponse expected) throws Exception {
        Path file = temp.resolve("cover.pdf");
        try (var document = new PDDocument(); var input = getClass().getResourceAsStream("/fonts/LimsSongSC-Regular.ttf")) {
            document.addPage(new PDPage());
            var font = PDType0Font.load(document, input);
            try (var stream = new PDPageContentStream(document, document.getPage(0))) {
                for (int i = 0; i < lines.length; i++) {
                    stream.beginText();
                    stream.setFont(font, 10);
                    stream.newLineAtOffset(40, 740 - i * 24);
                    stream.showText(lines[i]);
                    stream.endText();
                }
            }
            document.save(file.toFile());
        }
        assertThat(new PdfCoverExtractor().extract(file.toFile())).as(name).isEqualTo(expected);
    }

    @Test
    void checksEveryPrivateRealSampleAgainstReviewedExpectedFields() throws Exception {
        String directory = System.getProperty("pdf.cover.corpus.directory");
        Assumptions.assumeTrue(directory != null, "Pass the private corpus directory to run the real-file batch.");
        Path root = Path.of(directory);
        var mapper = new ObjectMapper();
        var cases = mapper.readTree(root.resolve("expected.json").toFile());
        assertThat(cases.size()).isGreaterThanOrEqualTo(6);
        var extractor = new PdfCoverExtractor();
        var assertions = new SoftAssertions();
        for (var item : cases) {
            Path pdf = root.resolve(item.get("file").asText());
            assertThat(Files.isRegularFile(pdf)).isTrue();
            String sha256 = java.util.HexFormat.of().formatHex(java.security.MessageDigest.getInstance("SHA-256").digest(Files.readAllBytes(pdf)));
            assertThat(sha256).as(item.get("file").asText() + " reviewed bytes").isEqualTo(item.get("sha256").asText());
            var expected = mapper.treeToValue(item.get("expected"), CoverExtractionResponse.class);
            assertions.assertThat(extractor.extract(pdf.toFile())).as(item.get("file").asText()).isEqualTo(expected);
        }
        assertions.assertAll();
    }
}
