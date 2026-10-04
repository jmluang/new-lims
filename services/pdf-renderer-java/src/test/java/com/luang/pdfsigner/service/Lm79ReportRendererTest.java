package com.luang.pdfsigner.service;

import static org.assertj.core.api.Assertions.*;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.luang.pdfsigner.dto.Lm79ReportPayload;
import com.luang.pdfsigner.dto.Lm79ReportPayload.*;
import com.luang.pdfsigner.security.*;
import com.luang.pdfsigner.web.Lm79ReportController;
import java.io.ByteArrayOutputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.*;
import org.apache.pdfbox.pdmodel.font.PDType1Font;
import org.apache.pdfbox.pdmodel.font.Standard14Fonts;
import org.apache.pdfbox.text.PDFTextStripper;
import org.junit.jupiter.api.Test;
import org.springframework.test.web.servlet.setup.MockMvcBuilders;

class Lm79ReportRendererTest {
    private byte[] appendix() throws Exception {
        try (var doc = new PDDocument(); var out = new ByteArrayOutputStream()) {
            var page = new PDPage(); doc.addPage(page);
            try (var content = new PDPageContentStream(doc, page)) {
                content.beginText(); content.setFont(new PDType1Font(Standard14Fonts.FontName.HELVETICA), 12);
                content.newLineAtOffset(60, 700); content.showText("APPENDIX-EVIDENCE"); content.endText();
            }
            // Some instrument PDFs inherit fonts from their page-tree parent.
            doc.getDocumentCatalog().getPages().getCOSObject().setItem(org.apache.pdfbox.cos.COSName.RESOURCES, page.getResources());
            page.getCOSObject().removeItem(org.apache.pdfbox.cos.COSName.RESOURCES);
            doc.save(out); return out.toByteArray();
        }
    }

    private Lm79ReportPayload payload(List<Section> sections, List<Appendix> appendices) {
        return new Lm79ReportPayload("REPORT-001", "中山市鑫普达检测有限公司", "广东省中山市古镇镇东兴东路33号7栋1楼部分",
            "测试灯具", "L-30", "A&B Lighting", "2026-10-03", "2026-10-04", sections, List.of(),
            List.of(List.of(380d, .1d), List.of(450d, 1d), List.of(600d, .5d), List.of(780d, .1d)), appendices);
    }

    @Test void paginatesNativeTablesAndPhysicallyMergesAppendixPages() throws Exception {
        var rows = new ArrayList<List<String>>();
        for (int i = 0; i < 80; i++) rows.add(List.of("测试项目 " + i, "实测结果 " + i));
        var p = payload(List.of(new Section("测量结果", List.of("项目", "数据"), rows)), List.of(new Appendix("附录 A", appendix())));
        try (var out = new ByteArrayOutputStream()) {
            new Lm79ReportRenderer().render(p, out);
            byte[] bytes = out.toByteArray();
            try (var doc = Loader.loadPDF(bytes)) {
                String text = new PDFTextStripper().getText(doc);
                assertThat(text).contains("A&B Lighting", "测试项目 79", "测量结果（续）", "APPENDIX-EVIDENCE", "相对光谱功率");
                assertThat(text).doesNotContain("A&amp;B");
                assertThat(doc.getNumberOfPages()).isGreaterThan(5);
                var stripper = new PDFTextStripper(); stripper.setStartPage(doc.getNumberOfPages());
                assertThat(stripper.getText(doc)).contains("APPENDIX-EVIDENCE");
                assertThat(doc.getPage(doc.getNumberOfPages() - 1).getResources()).isNotNull();
            }
            String target = System.getProperty("lm79.preview");
            if (target != null) { Files.createDirectories(Path.of(target).getParent()); Files.write(Path.of(target), bytes); }
        }
    }

    @Test void usesTheReviewedTemplateTitleAndPaginationSizes() throws Exception {
        try (var out = new ByteArrayOutputStream()) {
            new Lm79ReportRenderer().render(payload(List.of(new Section("测量结果", List.of("项目", "结果"),
                    List.of(List.of("输入功率", "40.15 W")))), List.of()), out);
            try (var doc = Loader.loadPDF(out.toByteArray())) {
                var glyphs = new ArrayList<org.apache.pdfbox.text.TextPosition>();
                var stripper = new PDFTextStripper() {
                    @Override protected void processTextPosition(org.apache.pdfbox.text.TextPosition position) {
                        glyphs.add(position); super.processTextPosition(position);
                    }
                };
                stripper.setStartPage(1); stripper.setEndPage(1); stripper.getText(doc);
                var title = glyphs.stream().filter(g -> g.getUnicode().equals("检") && g.getFontSizeInPt() >= 30)
                        .findFirst().orElseThrow();
                assertThat(title.getFontSizeInPt()).as("Reviewed cover title size").isEqualTo(36);
                var footer = glyphs.stream().filter(g -> g.getXDirAdj() > 440 && g.getYDirAdj() > 730).toList();
                assertThat(footer).isNotEmpty().allSatisfy(g -> assertThat(g.getFontSizeInPt()).isEqualTo(9));
            }
        }
    }

    @Test void rendersTheCompleteBackendFixtureWithReviewedLayoutAndOriginalAppendix() throws Exception {
        var mapper = new ObjectMapper();
        Lm79ReportPayload source;
        try (var input = getClass().getResourceAsStream("/lm79-report/backend-payload.json")) {
            source = mapper.readValue(input, Lm79ReportPayload.class);
        }
        byte[] photo;
        try (var input = getClass().getResourceAsStream("/lighting-report/photo.png")) { photo = input.readAllBytes(); }
        var p = new Lm79ReportPayload(source.reportNumber(), source.labName(), source.labAddress(),
                source.productName(), source.model(), source.applicant(), source.receivedDate(), source.issuedDate(),
                source.sections(), List.of(photo, photo), source.spectrum(),
                List.of(new Appendix("附录 A. 光强分布测试报告", appendix())), source.documentInfo());
        try (var out = new ByteArrayOutputStream()) {
            new Lm79ReportRenderer().render(p, out);
            try (var doc = Loader.loadPDF(out.toByteArray())) {
                String text = new PDFTextStripper().getText(doc);
                // The bundled fallback font extracts the en-dash glyph as a figure dash.
                text = text.replace('\u2012', '\u2013');
                assertThat(text).contains("本次检测样品数量", "LED 驱动器型号", "LED 模组型号", "系统样品编号", "CRI R1–R15",
                        "标准灯/校准灯", "合成标准不确定度", "校准证书编号", "相对光谱功率", "APPENDIX-EVIDENCE");
                String normalized = text.replaceAll("\\s+", "");
                for (var section : source.sections()) {
                    for (var row : section.rows()) {
                        assertThat(normalized).contains(row.get(0).replaceAll("\\s+", ""));
                        assertThat(normalized).contains(row.get(1).replaceAll("\\s+", ""));
                    }
                }
                assertThat(text).doesNotContain("[可选]", "TEST REPORT", "V2.4");
                var cover = new PDFTextStripper(); cover.setStartPage(1); cover.setEndPage(1);
                assertThat(cover.getText(doc)).contains("IEC 60598-1:2024");
                assertThat(doc.getNumberOfPages()).isGreaterThan(7);
                var last = new PDFTextStripper(); last.setStartPage(doc.getNumberOfPages());
                assertThat(last.getText(doc)).contains("APPENDIX-EVIDENCE").doesNotContain("第 ");
                assertThat(doc.getPage(doc.getNumberOfPages() - 1).getResources()).isNotNull();
            }
            String target = System.getProperty("lm79.integrated.preview");
            if (target != null) {
                // The user-facing layout sample excludes the internal appendix evidence marker.
                var preview = new Lm79ReportPayload(p.reportNumber(), p.labName(), p.labAddress(), p.productName(),
                        p.model(), p.applicant(), p.receivedDate(), p.issuedDate(), p.sections(), p.photos(), p.spectrum(),
                        source.appendices(), p.documentInfo());
                try (var previewBytes = new ByteArrayOutputStream()) {
                    new Lm79ReportRenderer().render(preview, previewBytes);
                    Files.createDirectories(Path.of(target).getParent()); Files.write(Path.of(target), previewBytes.toByteArray());
                }
            }
        }
    }

    @Test void keepsReferenceContentsInEnglishWithNestedResultSections() throws Exception {
        Lm79ReportPayload source;
        try (var input = getClass().getResourceAsStream("/lm79-report/backend-payload.json")) {
            source = new ObjectMapper().readValue(input, Lm79ReportPayload.class);
        }
        try (var out = new ByteArrayOutputStream()) {
            new Lm79ReportRenderer().render(source, out);
            try (var doc = Loader.loadPDF(out.toByteArray())) {
                var toc = new PDFTextStripper(); toc.setStartPage(2); toc.setEndPage(2);
                String text = toc.getText(doc);
                assertThat(text).contains("1   General information", "1.1   Product Information",
                        "4   Summary of Test Result", "4.1   Electrical Property Parameters",
                        "4.2   Photometric parameters", "4.3   Color Parameters",
                        "5   Luminous Intensity Distribution test data");
                assertThat(text).doesNotContain("1   基础资料", "10   不确定度分量");
                var targetPage = new PDFTextStripper();
                for (var expected : List.of(
                        List.of("1.1   Product Information", "申请人"),
                        List.of("1.2   Standards or methods", "IEC 60598-1:2024"),
                        List.of("1.3   Test Equipment", "校准证书编号"),
                        List.of("4.1   Electrical Property Parameters", "电参数"),
                        List.of("4.2   Photometric parameters", "总光通量"),
                        List.of("4.3   Color Parameters", "色度测试"),
                        List.of("5   Luminous Intensity Distribution test data", "峰值光强"))) {
                    String line = text.lines().filter(l -> l.contains(expected.get(0))).findFirst().orElseThrow();
                    var pageNumber = java.util.regex.Pattern.compile("(\\d+)\\s*$").matcher(line);
                    assertThat(pageNumber.find()).as("Contents page number: %s", line).isTrue();
                    int page = Integer.parseInt(pageNumber.group(1));
                    targetPage.setStartPage(page); targetPage.setEndPage(page);
                    assertThat(targetPage.getText(doc)).contains(expected.get(1));
                }
            }
        }
    }

    @Test void usesOnlyReferenceBodyHeadingsAndOmitsDeclarations() throws Exception {
        Lm79ReportPayload source;
        try (var input = getClass().getResourceAsStream("/lm79-report/backend-payload.json")) {
            source = new ObjectMapper().readValue(input, Lm79ReportPayload.class);
        }
        byte[] photo;
        try (var input = getClass().getResourceAsStream("/lighting-report/photo.png")) { photo = input.readAllBytes(); }
        var p = new Lm79ReportPayload(source.reportNumber(), source.labName(), source.labAddress(),
                source.productName(), source.model(), source.applicant(), source.receivedDate(), source.issuedDate(),
                source.sections(), List.of(photo), source.spectrum(), source.appendices(), source.documentInfo());
        try (var out = new ByteArrayOutputStream()) {
            new Lm79ReportRenderer().render(p, out);
            try (var doc = Loader.loadPDF(out.toByteArray())) {
                String text = new PDFTextStripper().getText(doc);
                assertThat(text).contains("测试设置", "测试条件", "电参数", "色度测试", "申请人", "峰值光强");
                assertThat(text).doesNotContain("基础资料", "样品照片", "灯具图片", "声明", "Announcement",
                        "本报告部分复制无效", "Test Settings", "4.4   Measurement Uncertainty", "4.5   Spectral Power Distribution",
                        "引用标准", "测试设备及校准溯源", "不确定度分量", "不确定度计算结果", "光强分布测试数据");
                var information = new PDFTextStripper(); information.setStartPage(3); information.setEndPage(3);
                assertThat(information.getText(doc).stripLeading()).startsWith("申请人");
                assertThat(source.sections()).noneMatch(s -> "paragraphs".equals(s.layout()));
            }
        }
    }

    @Test void refusesInvalidPayloadAndUnauthenticatedRequests() throws Exception {
        var controller = new Lm79ReportController(new Lm79ReportRenderer());
        var mvc = MockMvcBuilders.standaloneSetup(controller).build();
        mvc.perform(post("/api/pdf/lm79-report").contentType("application/json").content("{}"))
            .andExpect(status().isUnprocessableEntity());
        var protectedMvc = MockMvcBuilders.standaloneSetup(controller)
            .addFilters(new PdfHmacAuthenticationFilter(new PdfHmacProperties(true, "primary", "", 60), new InMemoryHmacNonceStore())).build();
        protectedMvc.perform(post("/api/pdf/lm79-report").contentType("application/json").content(new ObjectMapper().writeValueAsBytes(payload(List.of(), List.of()))))
            .andExpect(status().isUnauthorized());
    }
}
