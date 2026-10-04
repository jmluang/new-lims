package com.luang.pdfsigner.service;

import com.luang.pdfsigner.dto.Lm79ReportPayload;
import java.awt.Color;
import java.io.IOException;
import java.io.OutputStream;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.multipdf.PDFMergerUtility;
import org.apache.pdfbox.io.RandomAccessReadBuffer;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPage;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.PDPageContentStream.AppendMode;
import org.apache.pdfbox.pdmodel.common.PDRectangle;
import org.apache.pdfbox.pdmodel.font.PDFont;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.springframework.stereotype.Service;

/** Native, paginated LM-79 reports and physically merged PDF appendices. */
@Service
public final class Lm79ReportRenderer {
    private static final Color BLUE = new Color(26, 58, 107);
    private static final float LEFT = 40, WIDTH = 515, BOTTOM = 65;

    public void render(Lm79ReportPayload p, OutputStream output) throws IOException {
        if (p == null || p.reportNumber() == null || p.labName() == null || p.sections() == null) {
            throw new IllegalArgumentException("Report number, laboratory and sections are required");
        }
        try (var doc = new PDDocument(PdfFiles.streamCache())) {
            PDFont font = ContractPdfAssets.loadPrimaryFont(doc);
            var contents = new LinkedHashMap<String, Integer>();
            try (var page = new Page(doc, font)) {
                page.text("检 测 报 告", 30, 180, 650);
                page.text("TEST REPORT", 16, 200, 610);
                float y = 530;
                String[][] fields = {{"报告编号", p.reportNumber()}, {"产品名称", p.productName()},
                    {"型号", p.model()}, {"申请人", p.applicant()}, {"参考标准", "ANSI/IES LM-79-19"},
                    {"样品接收日期", p.receivedDate()}, {"签发日期", p.issuedDate()}};
                for (var field : fields) {
                    page.text(field[0], 11, 75, y);
                    var lines = page.wrap(field[1], 11, 340);
                    if (lines.size() > 6) throw new IllegalArgumentException("Cover value is too long");
                    for (var line : lines) { page.text(line, 11, 190, y); y -= 16; }
                    y -= 16;
                }
                if (y < 190) throw new IllegalArgumentException("Cover values exceed page capacity");
                var labLines = page.wrap(p.labName(), 14, 450);
                var addressLines = page.wrap(p.labAddress(), 10, 450);
                if (labLines.size() > 2 || addressLines.size() > 3) throw new IllegalArgumentException("Laboratory information exceeds cover capacity");
                float addressY = 150;
                for (String line : labLines) { page.text(line, 14, 80, addressY); addressY -= 20; }
                addressY -= 8;
                for (String line : addressLines) { page.text(line, 10, 80, addressY); addressY -= 14; }
            }
            doc.addPage(new PDPage(PDRectangle.A4));
            Page page = new Page(doc, font);
            try {
                for (var section : p.sections()) {
                    if (section == null || section.headers() == null || section.rows() == null
                            || section.headers().isEmpty() || section.headers().size() > 6) throw new IllegalArgumentException("Invalid report table");
                    if (page.y < 180) { page.close(); page = new Page(doc, font); }
                    contents.put(section.title(), doc.getNumberOfPages());
                    page.text(section.title(), 14, LEFT, page.y); page.y -= 28;
                    page.row(section.headers(), section.headers().size(), true);
                    for (var row : section.rows()) {
                        if (row.size() != section.headers().size()) throw new IllegalArgumentException("Invalid report table row");
                        float height = page.rowHeight(row);
                        if (height > 640) throw new IllegalArgumentException("Report cell exceeds page capacity");
                        if (page.y - height < BOTTOM) {
                            page.close(); page = new Page(doc, font);
                            page.text(section.title() + "（续）", 14, LEFT, page.y); page.y -= 28;
                            page.row(section.headers(), section.headers().size(), true);
                        }
                        page.row(row, row.size(), false);
                    }
                    page.y -= 24;
                }
                if (p.spectrum() != null && !p.spectrum().isEmpty()) {
                    page.close(); page = new Page(doc, font);
                    contents.put("光谱功率分布（SPD）", doc.getNumberOfPages());
                    page.text("光谱功率分布（SPD）", 14, LEFT, page.y);
                    drawSpectrum(page, p.spectrum());
                }
                if (p.photos() != null && !p.photos().isEmpty()) {
                    page.close(); page = new Page(doc, font);
                    contents.put("样品照片", doc.getNumberOfPages());
                    page.text("样品照片", 14, LEFT, page.y); page.y -= 35;
                    for (byte[] bytes : p.photos()) {
                        if (page.y < 370) { page.close(); page = new Page(doc, font); }
                        var image = PDImageXObject.createFromByteArray(doc, bytes, "sample-photo");
                        float scale = Math.min(WIDTH / image.getWidth(), 280f / image.getHeight());
                        float w = image.getWidth() * scale, h = image.getHeight() * scale;
                        page.stream.drawImage(image, LEFT + (WIDTH - w) / 2, page.y - h, w, h);
                        page.y -= 315;
                    }
                }
            } finally { page.close(); }
            int bodyPages = doc.getNumberOfPages();
            if (p.appendices() != null) {
                for (var appendix : p.appendices()) {
                    if (appendix.pdf() == null || appendix.pdf().length == 0) throw new IllegalArgumentException("Empty report appendix");
                    try (var extra = Loader.loadPDF(new RandomAccessReadBuffer(appendix.pdf()), PdfFiles.streamCache())) {
                        if (extra.isEncrypted() || !extra.getSignatureDictionaries().isEmpty() || extra.getNumberOfPages() == 0) {
                            throw new IllegalArgumentException("Appendices must be non-encrypted, unsigned PDFs with pages");
                        }
                        contents.put(appendix.title(), doc.getNumberOfPages() + 1);
                        new PDFMergerUtility().appendDocument(doc, extra);
                    }
                }
            }
            try (var toc = new Page(doc, font, doc.getPage(1))) {
                toc.text("目 录", 20, 240, 760);
                float y = 710;
                for (var entry : contents.entrySet()) {
                    if (y < BOTTOM) throw new IllegalArgumentException("Report contents exceed page capacity");
                    toc.text(entry.getKey(), 11, 60, y); toc.text(String.valueOf(entry.getValue()), 11, 510, y); y -= 28;
                }
            }
            // Imported appendix pages retain their original content and dimensions.
            for (int i = 0; i < bodyPages; i++) {
                try (var footer = new Page(doc, font, doc.getPage(i))) {
                    float footerY = 50;
                    for (var line : footer.wrap(p.reportNumber() + " | FO-22-03-2402 | V2.4", 8, 360)) {
                        footer.text(line, 8, LEFT, footerY); footerY -= 12;
                    }
                    footer.text("第 " + (i + 1) + " 页 / 共 " + doc.getNumberOfPages() + " 页", 8, 430, 38);
                }
            }
            doc.getDocumentInformation().setTitle("LM-79 " + p.reportNumber());
            doc.save(output);
        }
    }

    private static void drawSpectrum(Page p, List<List<Double>> points) throws IOException {
        if (points.size() < 2 || points.size() > 10000) throw new IllegalArgumentException("Invalid spectrum point count");
        double low = points.get(0).get(0), high = points.get(points.size() - 1).get(0), max = 0;
        for (var point : points) {
            if (point.size() != 2 || !Double.isFinite(point.get(0)) || !Double.isFinite(point.get(1)) || point.get(1) < 0) throw new IllegalArgumentException("Invalid spectrum data");
            max = Math.max(max, point.get(1));
        }
        if (high <= low || max <= 0) throw new IllegalArgumentException("Invalid spectrum range");
        p.stream.setStrokingColor(new Color(220, 226, 233)); p.stream.setLineWidth(.5f);
        for (int i = 0; i <= 5; i++) {
            float y = 420 + 260f * i / 5;
            p.stream.moveTo(LEFT, y); p.stream.lineTo(LEFT + WIDTH, y); p.stream.stroke();
            p.text(String.format(java.util.Locale.ROOT, "%.1f", i / 5d), 8, LEFT - 23, y - 3);
        }
        p.stream.setStrokingColor(BLUE); p.stream.setLineWidth(1);
        for (int i = 0; i < points.size(); i++) {
            float x = LEFT + (float) ((points.get(i).get(0) - low) / (high - low) * WIDTH);
            float y = 420 + (float) (points.get(i).get(1) / max * 260);
            if (i == 0) p.stream.moveTo(x, y); else p.stream.lineTo(x, y);
        }
        p.stream.stroke();
        p.text("波长 (nm)", 10, 255, 390);
        for (int i = 0; i <= 5; i++) {
            p.text(String.format(java.util.Locale.ROOT, "%.0f", low + (high - low) * i / 5), 9, LEFT + WIDTH * i / 5 - 5, 405);
        }
        p.text("相对光谱功率（最大值归一化为 1）", 10, LEFT, 710);
    }

    private static final class Page implements AutoCloseable {
        final PDPageContentStream stream;
        final PDFont font;
        float y = 760;
        Page(PDDocument doc, PDFont font) throws IOException {
            this(doc, font, addPage(doc));
        }
        Page(PDDocument doc, PDFont font, PDPage page) throws IOException {
            this.font = font; this.stream = new PDPageContentStream(doc, page, AppendMode.APPEND, true, true);
        }
        static PDPage addPage(PDDocument doc) { var page = new PDPage(PDRectangle.A4); doc.addPage(page); return page; }
        void text(String value, float size, float x, float y) throws IOException {
            stream.setNonStrokingColor(BLUE); stream.beginText(); stream.setFont(font, size);
            stream.newLineAtOffset(x, y); stream.showText(value == null || value.isBlank() ? "—" : value); stream.endText();
        }
        List<String> wrap(String value, float size, float width) throws IOException {
            var result = new ArrayList<String>();
            StringBuilder line = new StringBuilder();
            for (int code : (value == null || value.isBlank() ? "—" : value).replace("\r", "").codePoints().toArray()) {
                if (code == '\n') { result.add(line.toString()); line.setLength(0); continue; }
                String ch = new String(Character.toChars(code < 32 ? 32 : code));
                if (font.getStringWidth(line + ch) / 1000 * size > width && !line.isEmpty()) { result.add(line.toString()); line.setLength(0); }
                line.append(ch);
            }
            if (!line.isEmpty()) result.add(line.toString());
            return result.isEmpty() ? List.of("—") : result;
        }
        float columnWidth(int columns, int index) { return columns == 2 ? (index == 0 ? 175 : WIDTH - 175) : WIDTH / columns; }
        float rowHeight(List<String> row) throws IOException {
            int lines = 1;
            for (int i = 0; i < row.size(); i++) lines = Math.max(lines, wrap(row.get(i), 9, columnWidth(row.size(), i) - 12).size());
            return lines * 13 + 12;
        }
        void row(List<String> row, int columns, boolean heading) throws IOException {
            float height = rowHeight(row), x = LEFT;
            for (int i = 0; i < columns; i++) {
                float width = columnWidth(columns, i);
                if (heading) { stream.setNonStrokingColor(new Color(234, 240, 247)); stream.addRect(x, y - height, width, height); stream.fill(); }
                stream.setStrokingColor(new Color(185, 195, 210)); stream.setLineWidth(.5f);
                stream.addRect(x, y - height, width, height); stream.stroke();
                float lineY = y - 16;
                for (String line : wrap(row.get(i), 9, width - 12)) { text(line, 9, x + 6, lineY); lineY -= 13; }
                x += width;
            }
            y -= height;
        }
        @Override public void close() throws IOException { stream.close(); }
    }
}
