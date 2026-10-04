package com.luang.pdfsigner.service;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.springframework.test.web.servlet.request.MockMvcRequestBuilders.post;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.content;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.header;
import static org.springframework.test.web.servlet.result.MockMvcResultMatchers.status;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.node.ObjectNode;
import com.luang.pdfsigner.dto.LightingReportPayload;
import com.luang.pdfsigner.security.InMemoryHmacNonceStore;
import com.luang.pdfsigner.security.PdfHmacAuthenticationFilter;
import com.luang.pdfsigner.security.PdfHmacProperties;
import com.luang.pdfsigner.web.LightingReportController;
import com.luang.pdfsigner.web.PdfConcurrencyFilter;
import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Base64;
import java.util.List;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.text.PDFTextStripper;
import org.apache.pdfbox.text.TextPosition;
import org.junit.jupiter.api.Test;
import org.springframework.test.web.servlet.setup.MockMvcBuilders;

class LightingReportRendererTest {
    private final ObjectMapper mapper = new ObjectMapper();
    private final LightingReportRenderer renderer = new LightingReportRenderer();

    @Test
    void rendersFiveNativePagesWithInputValuesAndEmbeddedImages() throws Exception {
        byte[] pdf = renderer.render(payload(sample(true)));
        try (PDDocument document = Loader.loadPDF(pdf)) {
            assertThat(document.getNumberOfPages()).isEqualTo(5);
            assertThat(pageText(document, 1)).contains("XPD202609300021", "2026-09-09", "IES LM-79-19");
            assertThat(pageText(document, 3)).contains("2026-09-14", "Dingmin Zhang", "Xue Li", "Tom Wu");
            assertThat(pageText(document, 4)).contains("0.1832 A", "40.15 W", "4025 K", "0.0023", "TM-30 Rg");
            assertThat(pageText(document, 4)).doesNotContain("FORM", "GOS", "haas");
            assertThat(document.getPage(2).getResources().getXObjectNames()).hasSize(3);
            assertThat(document.getPage(4).getResources().getXObjectNames()).hasSize(2);
            for (var page : document.getPages()) {
                assertThat(page.getMediaBox().getWidth()).isCloseTo(595.28f, org.assertj.core.data.Offset.offset(0.1f));
                for (var name : page.getResources().getFontNames()) {
                    assertThat(page.getResources().getFont(name).isEmbedded()).isTrue();
                }
            }
        }
        String target = System.getProperty("lighting.report.preview");
        if (target != null) {
            Path path = Path.of(target);
            Files.createDirectories(path.toAbsolutePath().getParent());
            Files.write(path, pdf);
        }
    }

    @Test
    void matchesReferenceCoverFontSizes() throws Exception {
        try (PDDocument document = Loader.loadPDF(renderer.render(payload(sample(false))))) {
            List<TextPosition> cover = positions(document, 1).stream()
                    .filter(p -> p.getYDirAdj() > 315 && p.getYDirAdj() < 500).toList();
            assertThat(cover).isNotEmpty();
            assertThat(cover).allSatisfy(p -> {
                boolean standardValue = p.getXDirAdj() >= 200
                        && p.getYDirAdj() > 365 && p.getYDirAdj() < 395;
                assertThat(p.getFontSizeInPt()).as("Cover glyph %s at x=%s y=%s", p.getUnicode(),
                        p.getXDirAdj(), p.getYDirAdj()).isEqualTo(standardValue ? 12 : 11);
            });
        }
    }

    @Test
    void alignsInformationAndSignersAndUsesOnePaginationStyle() throws Exception {
        try (PDDocument document = Loader.loadPDF(renderer.render(payload(sample(true))))) {
            List<TextPosition> info = positions(document, 3);
            assertThat(info.stream().filter(p -> p.getUnicode().equals("申") || p.getUnicode().equals("审")
                    || p.getUnicode().equals("批")).map(TextPosition::getXDirAdj))
                    .allSatisfy(x -> assertThat(x).isCloseTo(65.75f, org.assertj.core.data.Offset.offset(0.01f)));
            List<TextPosition> cell = positions(document, 4).stream()
                    .filter(p -> p.getYDirAdj() > 110 && p.getYDirAdj() < 134
                            && p.getXDirAdj() > 56 && p.getXDirAdj() < 177).toList();
            float left = cell.stream().map(TextPosition::getXDirAdj).min(Float::compare).orElseThrow();
            float right = cell.stream().map(p -> p.getXDirAdj() + p.getWidthDirAdj()).max(Float::compare).orElseThrow();
            assertThat((left + right) / 2).isCloseTo(117f, org.assertj.core.data.Offset.offset(0.2f));
            for (int page = 1; page <= 5; page++) {
                List<TextPosition> footer = positions(document, page).stream()
                        .filter(p -> p.getYDirAdj() > 730 && p.getXDirAdj() > 440).toList();
                assertThat(footer).isNotEmpty();
                assertThat(footer).allSatisfy(p -> assertThat(p.getFontSizeInPt()).isEqualTo(9));
                assertThat(footer.stream().map(p -> p.getFont().getName()).distinct()).hasSize(1);
                assertThat(pageText(document, page)).contains("第 " + page + " 页  共 5 页");
            }
        }
    }

    @Test
    void paginatesLongParametersAndUpdatesContentsAndTotals() throws Exception {
        ObjectNode sample = sample(true);
        ((ObjectNode) sample.get("form")).put("serialNumber", "LOT-0001 ".repeat(65));
        try (PDDocument document = Loader.loadPDF(renderer.render(payload(sample)))) {
            assertThat(document.getNumberOfPages()).isGreaterThan(5);
            int photoPage = document.getNumberOfPages();
            assertThat(pageText(document, 2)).contains("Photos of Sample", String.valueOf(photoPage));
            assertThat(pageText(document, photoPage)).contains("共 " + photoPage + " 页");
        }
    }

    @Test
    void omitsPhotoSectionWhenNoImagesAreSuppliedAndNeverUsesSampleDefaults() throws Exception {
        ObjectNode sample = sample(false);
        ((ObjectNode) sample.get("cover")).put("reportNumber", "REPORT-002");
        ((ObjectNode) sample.get("form")).put("productName", "替换样品");
        try (PDDocument document = Loader.loadPDF(renderer.render(payload(sample)))) {
            assertThat(document.getNumberOfPages()).isEqualTo(4);
            assertThat(pageText(document, 1)).contains("REPORT-002", "替换样品").doesNotContain("XPD202609300021");
            assertThat(pageText(document, 2)).doesNotContain("Photos of Sample");
        }
        assertThatThrownBy(() -> renderer.render(payload(mapper.createObjectNode())))
                .isInstanceOf(IllegalArgumentException.class).hasMessageContaining("form");
    }

    @Test
    void exposesJsonPdfContractWithExistingAdmissionAndHmacProtection() throws Exception {
        var controller = new LightingReportController(renderer);
        var mvc = MockMvcBuilders.standaloneSetup(controller)
                .addFilters(new PdfConcurrencyFilter(new PdfWorkLimiter(1))).build();
        mvc.perform(post("/api/pdf/lighting-report").contentType("application/json")
                .content(mapper.writeValueAsBytes(sample(false))))
                .andExpect(status().isOk()).andExpect(content().contentType("application/pdf"))
                .andExpect(header().string("Content-Disposition", "attachment; filename=lighting-report.pdf"));
        mvc.perform(post("/api/pdf/lighting-report").contentType("application/json").content("{}"))
                .andExpect(status().isUnprocessableEntity());
        var protectedMvc = MockMvcBuilders.standaloneSetup(controller)
                .addFilters(new PdfHmacAuthenticationFilter(new PdfHmacProperties(true, "primary", "", 60),
                        new InMemoryHmacNonceStore())).build();
        protectedMvc.perform(post("/api/pdf/lighting-report").contentType("application/json").content("{}"))
                .andExpect(status().isUnauthorized());
    }

    @Test
    void firstParameterSectionContinuesWithoutInsertingAnEmptyPage() throws Exception {
        ObjectNode sample = sample(false);
        ((ObjectNode) sample.get("testSetup")).put("cInterval", "STEP ".repeat(180));
        ((ObjectNode) sample.get("testSetup")).put("gammaInterval", "STEP ".repeat(180));
        try (PDDocument document = Loader.loadPDF(renderer.render(payload(sample)))) {
            assertThat(document.getNumberOfPages()).isGreaterThan(4);
            assertThat(pageText(document, 4)).contains("测试设置", "C 角度间隔", "STEP");
        }
    }

    @Test
    void rejectsInvalidImagesWithoutReturningPdfBytes() throws Exception {
        ObjectNode sample = sample(false);
        sample.putArray("photos").add("AQI=");
        var mvc = MockMvcBuilders.standaloneSetup(new LightingReportController(renderer)).build();
        mvc.perform(post("/api/pdf/lighting-report").contentType("application/json")
                .content(mapper.writeValueAsBytes(sample)))
                .andExpect(status().isUnprocessableEntity()).andExpect(content().contentTypeCompatibleWith("application/json"));
    }

    @Test
    void placesAnOddPhotoOnItsOwnPageAndUpdatesTheContents() throws Exception {
        ObjectNode sample = sample(true);
        ((com.fasterxml.jackson.databind.node.ArrayNode) sample.get("photos")).add(sample.get("photos").get(0));
        try (PDDocument document = Loader.loadPDF(renderer.render(payload(sample)))) {
            assertThat(document.getNumberOfPages()).isEqualTo(6);
            assertThat(document.getPage(5).getResources().getXObjectNames()).hasSize(1);
            assertThat(pageText(document, 6)).contains("第 6 页  共 6 页");
        }
    }

    private ObjectNode sample(boolean images) throws IOException {
        ObjectNode node;
        try (var input = getClass().getResourceAsStream("/lighting-report/sample.json")) {
            node = (ObjectNode) mapper.readTree(input);
        }
        if (images) {
            ObjectNode signatures = (ObjectNode) node.get("signatures");
            for (String key : List.of("tested", "reviewed", "approved")) {
                signatures.put(key + "Image", Base64.getEncoder().encodeToString(asset(key + ".png")));
            }
            var photos = node.putArray("photos");
            String photo = Base64.getEncoder().encodeToString(asset("photo.png"));
            photos.add(photo); photos.add(photo);
        }
        return node;
    }

    private byte[] asset(String name) throws IOException {
        try (var input = getClass().getResourceAsStream("/lighting-report/" + name)) { return input.readAllBytes(); }
    }

    private LightingReportPayload payload(ObjectNode node) throws IOException {
        return mapper.treeToValue(node, LightingReportPayload.class);
    }

    private static String pageText(PDDocument document, int page) throws IOException {
        var stripper = new PDFTextStripper(); stripper.setStartPage(page); stripper.setEndPage(page);
        return stripper.getText(document);
    }

    private static List<TextPosition> positions(PDDocument document, int page) throws IOException {
        var positions = new ArrayList<TextPosition>();
        var stripper = new PDFTextStripper() {
            @Override protected void processTextPosition(TextPosition position) {
                positions.add(position); super.processTextPosition(position);
            }
        };
        stripper.setStartPage(page); stripper.setEndPage(page); stripper.getText(document);
        return positions;
    }
}
