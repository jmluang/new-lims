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
                assertThat(text).contains("A&B Lighting", "测试项目 79", "测量结果（续）", "APPENDIX-EVIDENCE", "光谱功率分布");
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
