package com.luang.pdfsigner.service.renderer;

import com.luang.pdfsigner.service.ContractPdfAssets;
import com.luang.pdfsigner.service.ContractPdfPayload;
import com.luang.pdfsigner.service.ContractPdfPayload.PageFive.ImageSlot;
import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class PageFiveRenderer implements ContractPdfPageRenderer<ContractPdfPayload.PageFive> {

    private static final Logger log = LoggerFactory.getLogger(PageFiveRenderer.class);
    private static final float SLOT_LEFT = 60f;
    private static final float SLOT_WIDTH = ContractPdfAssets.PAGE_WIDTH - 120f;
    private static final float SLOT_HEIGHT = 240f;
    private static final float SLOT_GAP = 32f;
    private static final float CAPTION_FONT_SIZE = 12f;

    @Override
    public void render(PDDocument document,
                       ContractPdfPayload payload,
                       ContractPdfPayload.PageFive pageData,
                       ContractPdfPageContext pageContext,
                       ContractPdfDrawUtils utils) throws IOException {
        log.debug("Page Five renderer invoked for contract {}", payload != null && payload.meta() != null ? payload.meta().contractId() : null);
        List<ImageSlot> slots = new ArrayList<>();
        if (pageData != null && pageData.images() != null && !pageData.images().isEmpty()) {
            slots.addAll(pageData.images());
        }
        if (slots.isEmpty()) {
            slots.add(new ImageSlot(null, "暂无样品图片", 0, 1));
        }
        slots.sort(Comparator
                .comparing((ImageSlot slot) -> slot.pageIndex() == null ? 0 : slot.pageIndex())
                .thenComparing(slot -> slot.slot() == null ? 0 : slot.slot()));

        int index = 0;

        float headerBottomY = 780f - 45f;

        while (index < slots.size()) {
            ContractPdfPageContext.BodyCanvas canvas = pageContext.newPage();
            PDPageContentStream content = canvas.content();
            utils.drawCenteredText(content, utils.font(), 20f, ContractPdfAssets.PAGE_WIDTH / 2f, headerBottomY, "样品照片");

        float topY = headerBottomY - 20f;
            for (int row = 0; row < 2 && index < slots.size(); row++) {
                drawSingleImageRow(document, content, utils, slots.get(index), topY - row * (SLOT_HEIGHT + SLOT_GAP));
                index++;
            }

            content.close();
        }
    }

    private void drawSingleImageRow(PDDocument document,
                                    PDPageContentStream content,
                                    ContractPdfDrawUtils utils,
                                    ImageSlot slot,
                                    float topY) throws IOException {
        float rowBottom = topY - SLOT_HEIGHT;
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.addRect(SLOT_LEFT, rowBottom, SLOT_WIDTH, SLOT_HEIGHT);
        content.stroke();
        content.restoreGraphicsState();

        drawImageCell(document, content, utils, slot, SLOT_LEFT, rowBottom, SLOT_WIDTH, SLOT_HEIGHT);
    }

    private void drawImageCell(PDDocument document,
                               PDPageContentStream content,
                               ContractPdfDrawUtils utils,
                               ImageSlot slot,
                               float x,
                               float bottomY,
                               float cellWidth,
                               float cellHeight) throws IOException {
        boolean drawn = false;
        float padding = 8f;
        float captionHeight = 16f;
        float innerWidth = cellWidth - padding * 2;
        float innerHeight = cellHeight - padding * 2 - captionHeight;
        float imageBottom = bottomY + padding + captionHeight;
        float imageLeft = x + padding;

        if (slot.path() != null) {
            Path path = Path.of(slot.path());
            if (Files.exists(path)) {
                try {
                    PDImageXObject image = PDImageXObject.createFromFile(path.toString(), document);
                    float scale = Math.min(innerWidth / image.getWidth(), innerHeight / image.getHeight());
                    float width = image.getWidth() * scale;
                    float height = image.getHeight() * scale;
                    float offsetX = imageLeft + (innerWidth - width) / 2f;
                    float offsetY = imageBottom + (innerHeight - height) / 2f;
                    content.drawImage(image, offsetX, offsetY, width, height);
                    drawn = true;
                } catch (Exception ex) {
                    log.warn("Failed to draw image {}", slot.path(), ex);
                }
            }
        }

        if (!drawn) {
            // 如果是默认的"暂无样品图片"占位符，不显示"图片缺失"
            if (slot.caption() != null && slot.caption().equals("暂无样品图片")) {
                // 不绘制任何placeholder，直接显示标题
            } else {
                drawPlaceholder(content, utils, imageLeft, imageBottom, innerWidth, innerHeight, "图片缺失");
            }
        }

        String caption = slot.caption() == null ? "" : slot.caption();
        utils.drawCenteredText(content, utils.font(), CAPTION_FONT_SIZE, x + cellWidth / 2f, bottomY + padding, caption);
    }

    private void drawPlaceholder(PDPageContentStream content,
                                 ContractPdfDrawUtils utils,
                                 float x,
                                 float y,
                                 float width,
                                 float height,
                                 String label) throws IOException {
        content.saveGraphicsState();
        content.setLineWidth(0.5f);
        content.addRect(x, y, width, height);
        content.stroke();
        content.restoreGraphicsState();
        utils.drawCenteredText(content, utils.font(), CAPTION_FONT_SIZE, x + width / 2f, y + height / 2f, label);
    }
}
