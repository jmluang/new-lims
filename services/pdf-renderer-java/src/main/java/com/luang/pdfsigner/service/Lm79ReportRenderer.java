package com.luang.pdfsigner.service;

import com.luang.pdfsigner.dto.LightingReportPayload;
import com.luang.pdfsigner.dto.Lm79ReportPayload;
import com.luang.pdfsigner.dto.Lm79ReportPayload.Section;
import com.luang.pdfsigner.service.LightingReportRenderer.Canvas;
import java.awt.Color;
import java.io.IOException;
import java.io.OutputStream;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.io.RandomAccessReadBuffer;
import org.apache.pdfbox.multipdf.PDFMergerUtility;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDFont;
import org.springframework.stereotype.Service;

/** Uses the reviewed report style for all backend sections and retains original appendix pages. */
@Service
public final class Lm79ReportRenderer {
    private static final float WIDTH = PDRectangle.A4.getWidth();
    private static final float HEIGHT = PDRectangle.A4.getHeight();
    private static final float LEFT = 56.7f, TABLE_WIDTH = 482, TOP = 94.68f, BOTTOM = 706;
    private static final int TOC_ROWS = 22;
    private record Anchor(String kind, String title, int page) {}
    private record Entry(String title, int page, int level) {}

    public void render(Lm79ReportPayload p, OutputStream output) throws IOException {
        validate(p);
        var information = sections(p, "sample");
        var general = sections(p, "information");
        var signers = sections(p, "signatures");
        var standards = sections(p, "standards");
        var tables = new ArrayList<>(sections(p, "equipment"));
        tables.addAll(sections(p, "table"));
        var conditions = sections(p, "conditions");
        var parameters = new ArrayList<Section>();
        for (String kind : List.of("electrical", "photometric", "color", "parameters")) parameters.addAll(sections(p, kind));
        var distribution = sections(p, "distribution");
        var setup = sections(p, "setup");
        var uncertainty = sections(p, "uncertainty");
        var spectrumInfo = sections(p, "spectrum");
        int entryCount = (information.isEmpty() && general.isEmpty() && standards.isEmpty() && tables.isEmpty() ? 0 : 1)
                + (information.isEmpty() && general.isEmpty() ? 0 : 1) + standards.size() + tables.size()
                + (conditions.isEmpty() && setup.isEmpty() ? 0 : 1) + conditions.size() + setup.size()
                + (parameters.isEmpty() && uncertainty.isEmpty() && spectrumInfo.isEmpty() && !present(p.spectrum()) ? 0 : 1)
                + parameters.size() + (distribution.isEmpty() ? 0 : 1)
                + (present(p.photos()) ? 1 : 0)
                + (p.appendices() == null ? 0 : p.appendices().size());
        int tocPages = Math.max(1, (entryCount + TOC_ROWS - 1) / TOC_ROWS);
        try (var doc = new PDDocument(PdfFiles.streamCache())) {
            PDFont font = LightingReportRenderer.loadFont(doc);
            PDFont headingFont = LightingReportRenderer.loadHeadingFont(doc, font);
            List<Anchor> anchors = new ArrayList<>();
            drawCover(doc, font, p, standards);
            for (int index = 0; index < tocPages; index++) doc.addPage(new PDPage(PDRectangle.A4));
            try (var body = new Body(doc, font, anchors)) {
                if (!information.isEmpty() || !general.isEmpty()) {
                    body.ensure(20);
                    anchors.add(new Anchor("sample", "Product Information", doc.getNumberOfPages()));
                    for (var section : information) for (var row : section.rows()) body.information(row);
                    for (var section : general) for (var row : section.rows()) body.information(row);
                }
                for (var section : signers) body.signatures(section);
                if (!standards.isEmpty() || !tables.isEmpty()) body.nextPage();
                for (var section : standards) body.table(section, true);
                for (var section : tables) body.table(section, false);
                if (!conditions.isEmpty() || !setup.isEmpty()) body.nextPage();
                for (var section : conditions) body.parameters(section);
                for (var section : setup) body.parameters(section);
                if (present(p.photos())) body.photos(p.photos());
                if (!parameters.isEmpty()) body.nextPage();
                for (var section : parameters) body.parameters(section);
                if (!uncertainty.isEmpty()) body.nextPage();
                for (var section : uncertainty) body.parameters(section);
                if (present(p.spectrum())) body.spectrum(p.spectrum(), spectrumInfo);
                else if (!spectrumInfo.isEmpty()) {
                    body.nextPage();
                    for (var section : spectrumInfo) body.parameters(section);
                }
                for (var section : distribution) body.parameters(section);
            }
            int bodyPages = doc.getNumberOfPages();
            if (p.appendices() != null) {
                for (var appendix : p.appendices()) {
                    if (appendix == null || appendix.pdf() == null || appendix.pdf().length == 0) {
                        throw new IllegalArgumentException("Empty report appendix");
                    }
                    try (var extra = Loader.loadPDF(new RandomAccessReadBuffer(appendix.pdf()), PdfFiles.streamCache())) {
                        if (extra.isEncrypted() || !extra.getSignatureDictionaries().isEmpty() || extra.getNumberOfPages() == 0) {
                            throw new IllegalArgumentException("Appendices must be non-encrypted, unsigned PDFs with pages");
                        }
                        anchors.add(new Anchor("appendix", value(appendix.title()), doc.getNumberOfPages() + 1));
                        new PDFMergerUtility().appendDocument(doc, extra);
                    }
                }
            }
            drawContents(doc, font, headingFont, contents(anchors), tocPages);
            var metadata = p.documentInfo();
            var header = new LightingReportPayload.Header(p.labName(),
                    metadata == null || blank(metadata.fileNumber()) ? "FO-22-03-2402" : metadata.fileNumber(),
                    metadata == null || blank(metadata.version()) ? "V1.0" : metadata.version(), p.labAddress(),
                    metadata == null ? "" : metadata.website(), metadata == null ? "" : metadata.email());
            // Appendix evidence keeps its own size, orientation, resources, and printed content.
            for (int index = 0; index < bodyPages; index++) {
                try (var c = new Canvas(doc, doc.getPage(index), font)) {
                    LightingReportRenderer.drawHeaderFooter(c, header, index + 1, doc.getNumberOfPages());
                }
            }
            doc.getDocumentInformation().setTitle("LM-79 " + p.reportNumber());
            doc.getDocumentInformation().setCreator("New LIMS PDF Renderer");
            doc.save(output);
        }
    }

    private static void validate(Lm79ReportPayload p) {
        if (p == null || blank(p.reportNumber()) || blank(p.labName()) || p.sections() == null) {
            throw new IllegalArgumentException("Report number, laboratory and sections are required");
        }
        for (var s : p.sections()) {
            if (s == null || blank(s.title()) || s.headers() == null || s.headers().isEmpty()
                    || s.headers().size() > 6 || s.rows() == null) throw new IllegalArgumentException("Invalid report table");
            String kind = layout(s);
            if (!List.of("sample", "information", "parameters", "setup", "uncertainty", "spectrum", "standards", "table", "signatures", "equipment", "conditions", "electrical", "photometric", "color", "distribution").contains(kind)) {
                throw new IllegalArgumentException("Unknown report section layout");
            }
            if (!List.of("table", "equipment").contains(kind) && s.headers().size() != 2) throw new IllegalArgumentException("Invalid section column count");
            for (var row : s.rows()) {
                if (row == null || row.size() != s.headers().size()) throw new IllegalArgumentException("Invalid report table row");
            }
        }
    }

    private static void drawCover(PDDocument doc, PDFont font, Lm79ReportPayload p, List<Section> standards) throws IOException {
        var values = new ArrayList<String>();
        for (var s : standards) for (var row : s.rows()) if (!blank(row.get(1))) values.add(row.get(1));
        String reference = values.isEmpty() ? "—" : String.join("; ", values);
        // The full standards remain in the body when the summary cannot fit the fixed cover slot.
        if (font.getStringWidth(reference) / 1000 * 12 > 263 * 3) reference = "详见引用标准章节";
        String[][] fields = {{"报告编号：", p.reportNumber()}, {"申请人：", p.applicant()},
                {"参考标准：", reference}, {"产品名称：", p.productName()}, {"型号：", p.model()},
                {"接收日期：", p.receivedDate()}, {"签发日期：", p.issuedDate()}};
        LightingReportRenderer.drawCover(doc, font, p.labName(), fields);
    }

    private static void drawContents(PDDocument doc, PDFont font, PDFont headingFont,
                                     List<Entry> entries, int pages) throws IOException {
        for (int page = 0; page < pages; page++) {
            try (var c = new Canvas(doc, doc.getPage(1 + page), font)) {
                c.heading(page == 0 ? "CONTENTS" : "CONTENTS (continued)", 16, WIDTH / 2, 93.53f, headingFont, true);
                c.heading("Cover page", 12, 83.4f, 165.38f, headingFont, false);
                c.heading("Contents", 12, 83.4f, 187.03f, headingFont, false);
                float top = 220.68f;
                for (int index = page * TOC_ROWS; index < Math.min(entries.size(), (page + 1) * TOC_ROWS); index++) {
                    var e = entries.get(index);
                    String text = e.title();
                    float left = e.level() == 0 ? 68.25f : 83.4f;
                    List<String> lines = c.wrap(text, 12, 400);
                    if (lines.size() != 1) throw new IllegalArgumentException("Contents heading exceeds line capacity");
                    c.text(text, 12, left, top);
                    c.text(String.valueOf(e.page()), 12, 539 - c.measure(String.valueOf(e.page()), 12), top);
                    c.dotted(left + c.measure(text, 12) + 3, 531, top + 10);
                    top += 21.8f;
                }
            }
        }
    }

    private static List<Section> sections(Lm79ReportPayload p, String kind) {
        return p.sections().stream().filter(s -> layout(s).equals(kind) && !s.rows().isEmpty()).toList();
    }

    private static List<Entry> contents(List<Anchor> anchors) {
        var entries = new ArrayList<Entry>();
        addChapter(entries, anchors, "1   General information", List.of("sample", "standards", "equipment", "table"));
        addEntries(entries, anchors, "sample", "1.1   Product Information", 1, true);
        addEntries(entries, anchors, "standards", "1.2   Standards or methods", 1, false);
        addEntries(entries, anchors, "equipment", "1.3   Test Equipment", 1, false);
        addEntries(entries, anchors, "table", null, 1, false);
        addChapter(entries, anchors, "2   Test conducted and method", List.of("conditions", "setup"));
        addEntries(entries, anchors, "conditions", "2.1   Ambient Condition", 1, false);
        addEntries(entries, anchors, "setup", "2.7   Luminous Intensity Distribution Measurement Method", 1, false);
        addEntries(entries, anchors, "photos", "3   Photos of Sample", 0, true);
        addChapter(entries, anchors, "4   Summary of Test Result", List.of("electrical", "photometric", "color", "parameters", "uncertainty", "spectrum"));
        addEntries(entries, anchors, "electrical", "4.1   Electrical Property Parameters", 1, false);
        addEntries(entries, anchors, "photometric", "4.2   Photometric parameters", 1, false);
        addEntries(entries, anchors, "color", "4.3   Color Parameters", 1, false);
        addEntries(entries, anchors, "parameters", null, 1, false);
        addEntries(entries, anchors, "distribution", "5   Luminous Intensity Distribution test data", 0, true);
        addEntries(entries, anchors, "appendix", null, 0, false);
        return entries;
    }

    private static void addChapter(List<Entry> entries, List<Anchor> anchors, String title, List<String> kinds) {
        anchors.stream().filter(a -> kinds.contains(a.kind())).findFirst()
                .ifPresent(a -> entries.add(new Entry(title, a.page(), 0)));
    }

    private static void addEntries(List<Entry> entries, List<Anchor> anchors, String kind,
                                   String title, int level, boolean firstOnly) {
        for (var anchor : anchors) {
            if (!anchor.kind().equals(kind)) continue;
            entries.add(new Entry(title == null ? anchor.title() : title, anchor.page(), level));
            if (firstOnly) return;
        }
    }

    private static String referenceTitle(Section section) {
        return switch (layout(section)) {
            case "conditions" -> "测试条件";
            case "setup" -> "测试设置";
            case "electrical" -> "电参数";
            case "color" -> "色度测试";
            case "parameters", "table" -> section.title();
            default -> null;
        };
    }

    private static boolean blank(String value) { return value == null || value.isBlank(); }
    private static boolean present(List<?> values) { return values != null && !values.isEmpty(); }
    private static String value(String text) { return blank(text) ? "—" : text; }
    private static String layout(Section s) { return blank(s.layout()) ? "table" : s.layout(); }

    private static final class Body implements AutoCloseable {
        private final PDDocument document;
        private final PDFont font;
        private final List<Anchor> entries;
        private Canvas c;
        private float top = TOP;

        Body(PDDocument document, PDFont font, List<Anchor> entries) {
            this.document = document; this.font = font; this.entries = entries;
        }

        void nextPage() throws IOException {
            if (c != null) { c.close(); c = null; }
            top = TOP;
        }

        private void ensure(float height) throws IOException {
            if (height > BOTTOM - TOP) throw new IllegalArgumentException("Report cell exceeds page capacity");
            if (c != null && top + height > BOTTOM) nextPage();
            if (c == null) c = new Canvas(document, font);
        }

        void heading(String title, float size) throws IOException {
            ensure(42);
            c.center(title, size, WIDTH / 2, top); top += 15.27f;
        }

        void information(List<String> row) throws IOException {
            ensure(20);
            var label = c.wrap(value(row.get(0)), 12, 122);
            var result = c.wrap(value(row.get(1)), 12, 300);
            float height = Math.max(18.5f, Math.max(label.size(), result.size()) * 15 + 3);
            if (top + height > BOTTOM) nextPage();
            ensure(height);
            float y = top + (height - (label.size() - 1) * 15 - 12) / 2;
            c.lines(label, 12, 65.75f, y, 15, false, 0);
            c.text(":", 12, 195.05f, top + (height - 12) / 2);
            c.lines(result, 12, 228.15f, top + (height - (result.size() - 1) * 15 - 12) / 2, 15, false, 0);
            top += height;
            c.rule(59.7f, 535.7f, top, 0.5f, new Color(217, 217, 217));
        }

        void signatures(Section section) throws IOException {
            if (section.rows().isEmpty()) return;
            ensure(20);
            List<List<String>> names = new ArrayList<>();
            float height = 48;
            for (var row : section.rows()) { names.add(c.wrap(value(row.get(1)), 12, 306)); height += Math.max(28, names.get(names.size() - 1).size() * 15 + 10); }
            if (top + height > BOTTOM) nextPage();
            ensure(height);
            top += 22;
            for (int index = 0; index < section.rows().size(); index++) {
                c.text(value(section.rows().get(index).get(0)), 12, 65.75f, top);
                c.text("........................", 12, 103, top);
                c.lines(names.get(index), 12, 228.15f, top, 15, false, 0);
                top += Math.max(28, names.get(index).size() * 15 + 10);
            }
            c.text("日期", 12, 65.75f, top); c.text("...............................", 12, 94, top);
            // Actual signing dates are supplied by the later signing workflow, not inferred from issueDate.
            top += 30;
        }

        private record GridRow(List<String> cells, float[] widths, float height) {}

        private List<GridRow> parameterRows(Section section) throws IOException {
            var result = new ArrayList<GridRow>();
            for (int index = 0; index < section.rows().size();) {
                var first = section.rows().get(index);
                boolean wide = c.wrap(value(first.get(1)), 9, 112.5f).size() > 2;
                List<String> cells = new ArrayList<>(first);
                if (!wide) {
                    if (index + 1 < section.rows().size()
                            && c.wrap(value(section.rows().get(index + 1).get(1)), 9, 112.5f).size() <= 2) {
                        cells.addAll(section.rows().get(++index));
                    } else { cells.add(""); cells.add(""); }
                }
                float[] widths = wide ? new float[] {120.5f, 361.5f} : new float[] {120.5f, 120.5f, 120.5f, 120.5f};
                result.add(new GridRow(cells, widths, rowHeight(cells, widths))); index++;
            }
            return result;
        }

        private boolean hasValueContext(Section section) {
            return !section.headers().get(1).equals("结果 / 信息");
        }

        private void parameterHeading(Section section, boolean continued, boolean register) throws IOException {
            String title = referenceTitle(section);
            if (title != null) heading(title + (continued ? "（续）" : ""), 9);
            if (register) entries.add(new Anchor(layout(section), section.title(), document.getNumberOfPages()));
            if (hasValueContext(section)) {
                c.center(section.headers().get(1), 9, WIDTH / 2, top); top += 14;
            }
        }

        void parameters(Section section) throws IOException {
            if (section.rows().isEmpty()) return;
            ensure(0);
            var rows = parameterRows(section);
            float headerHeight = (referenceTitle(section) == null ? 0 : 15.27f) + (hasValueContext(section) ? 14 : 0);
            float height = headerHeight + 15.23f;
            for (var row : rows) height += row.height();
            if (height <= BOTTOM - TOP && top + height > BOTTOM) nextPage();
            ensure(42); parameterHeading(section, false, true);
            for (var row : rows) {
                if (row.height() > BOTTOM - TOP - headerHeight) throw new IllegalArgumentException("Parameter exceeds page capacity");
                if (top + row.height() > BOTTOM) { nextPage(); parameterHeading(section, true, false); }
                grid(row.cells(), row.widths(), row.height(), false, true);
            }
            top += 15.23f;
        }

        void table(Section section, boolean standard) throws IOException {
            if (section.rows().isEmpty()) return;
            float[] widths = widths(section.headers().size(), standard);
            ensure(70);
            String title = referenceTitle(section);
            if (title != null) heading(title, 12);
            entries.add(new Anchor(layout(section), section.title(), document.getNumberOfPages()));
            grid(section.headers(), widths, rowHeight(section.headers(), widths), false, true);
            for (var row : section.rows()) {
                float height = rowHeight(row, widths);
                if (top + height > BOTTOM) {
                    nextPage();
                    if (title != null) heading(title + "（续）", 12);
                    grid(section.headers(), widths, rowHeight(section.headers(), widths), false, true);
                }
                ensure(height); grid(row, widths, height, standard, !standard);
            }
            top += 18;
        }

        private static float[] widths(int count, boolean standard) {
            if (standard) return new float[] {36, 446};
            if (count == 5) return new float[] {133, 76, 112, 91, 70};
            var result = new float[count]; java.util.Arrays.fill(result, TABLE_WIDTH / count); return result;
        }

        private float rowHeight(List<String> row, float[] widths) throws IOException {
            ensure(0);
            int lines = 1;
            for (int index = 0; index < row.size(); index++) lines = Math.max(lines, c.wrap(row.get(index), 9, widths[index] - 8).size());
            return Math.max(24.5f, lines * 12 + 8);
        }

        private void grid(List<String> row, float[] widths, float height, boolean standard, boolean centered) throws IOException {
            float x = LEFT;
            for (int index = 0; index < row.size(); index++) {
                c.rect(x, top, widths[index], height, 0.5f);
                var lines = c.wrap(row.get(index), 9, widths[index] - 8);
                float y = top + (height - (lines.size() - 1) * 12 - 9) / 2 + 1.5f;
                boolean center = centered || (standard && index == 0);
                c.lines(lines, 9, center ? x : x + 4, y, 12, center, widths[index]); x += widths[index];
            }
            top += height;
        }

        void photos(List<byte[]> images) throws IOException {
            nextPage();
            for (int start = 0; start < images.size(); start += 2) {
                if (start != 0) nextPage();
                ensure(42);
                if (start == 0) entries.add(new Anchor("photos", "Photos of Sample", document.getNumberOfPages()));
                int count = Math.min(2, images.size() - start);
                c.rect(54.7f, 114, 486, count * 283.95f, 0.5f);
                if (count == 2) c.rule(54.7f, 540.7f, 397.95f, 0.5f, Color.BLACK);
                for (int slot = 0; slot < count; slot++) {
                    byte[] bytes = images.get(start + slot);
                    if (bytes == null || bytes.length == 0) throw new IllegalArgumentException("Empty sample photo");
                    c.image(bytes, 109.7f, 114 + slot * 283.95f, 376.45f, 283.45f);
                }
            }
        }

        void spectrum(List<List<Double>> input, List<Section> metadata) throws IOException {
            if (input.size() < 2 || input.size() > 10000) throw new IllegalArgumentException("Invalid spectrum point count");
            var points = new ArrayList<List<Double>>(input);
            for (var point : points) {
                if (point == null || point.size() != 2 || point.get(0) == null || point.get(1) == null
                        || !Double.isFinite(point.get(0)) || !Double.isFinite(point.get(1)) || point.get(0) <= 0 || point.get(1) < 0) {
                    throw new IllegalArgumentException("Invalid spectrum data");
                }
            }
            points.sort(java.util.Comparator.comparingDouble(p -> p.get(0)));
            double low = points.get(0).get(0), high = points.get(points.size() - 1).get(0);
            double max = points.stream().mapToDouble(p -> p.get(1)).max().orElse(0);
            if (high <= low || max <= 0) throw new IllegalArgumentException("Invalid spectrum range");
            nextPage(); ensure(42);
            entries.add(new Anchor("spectrum", "光谱功率分布（SPD）", document.getNumberOfPages()));
            for (var section : metadata) {
                for (var row : parameterRows(section)) {
                    ensure(row.height()); grid(row.cells(), row.widths(), row.height(), false, true);
                }
            }
            if (top + 375 > BOTTOM) { nextPage(); ensure(42); }
            c.text("相对光谱功率（最大值归一化为 1）", 9, LEFT, top + 15);
            var stream = c.stream(); stream.saveGraphicsState();
            float x0 = 86, w = 440, plotTop = Math.max(200, top + 50), h = 280;
            for (int tick = 0; tick <= 5; tick++) {
                float y = plotTop + h - h * tick / 5;
                c.rule(x0, x0 + w, y, 0.5f, new Color(217, 217, 217));
                c.text(String.format(Locale.ROOT, "%.1f", tick / 5d), 9, 58, y - 4);
                c.center(String.format(Locale.ROOT, "%.0f", low + (high - low) * tick / 5), 9, x0 + w * tick / 5, plotTop + h + 8);
            }
            stream.setStrokingColor(new Color(26, 57, 107)); stream.setLineWidth(1);
            for (int index = 0; index < points.size(); index++) {
                float x = x0 + (float) ((points.get(index).get(0) - low) / (high - low) * w);
                float y = HEIGHT - plotTop - h + (float) (points.get(index).get(1) / max * h);
                if (index == 0) stream.moveTo(x, y); else stream.lineTo(x, y);
            }
            stream.stroke(); stream.restoreGraphicsState();
            c.center("波长 (nm)", 10, x0 + w / 2, plotTop + h + 30);
            top = plotTop + h + 48;
        }

        @Override public void close() throws IOException { if (c != null) c.close(); }
    }
}
