package com.luang.pdfsigner.service.renderer;

import com.luang.pdfsigner.service.ContractPdfAssets;
import com.luang.pdfsigner.service.ContractPdfPayload;
import com.luang.pdfsigner.service.ContractPdfPayload.TocEntry;
import java.io.IOException;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.PDPageContentStream;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class PageTwoRenderer implements ContractPdfPageRenderer<ContractPdfPayload.PageTwo> {

    private static final Logger log = LoggerFactory.getLogger(PageTwoRenderer.class);
    private static final float TITLE_FONT_SIZE = 32f;
    private static final float ENTRY_FONT_SIZE = 16f;
    private static final float LEFT_MARGIN = 113.4f;
    private static final float RIGHT_MARGIN = 480f;
    private static final float ENTRY_STEP = 36f;

    @Override
    public void render(PDDocument document,
                       ContractPdfPayload payload,
                       ContractPdfPayload.PageTwo pageData,
                       ContractPdfPageContext pageContext,
                       ContractPdfDrawUtils utils) throws IOException {
        log.debug("Page Two renderer invoked for contract {}", payload != null && payload.meta() != null ? payload.meta().contractId() : null);
        List<TocEntry> entries = resolveEntries(payload, pageData);

        ContractPdfPageContext.BodyCanvas canvas = pageContext.newPage();
        PDPageContentStream content = canvas.content();
        float cursorY = canvas.contentTop() - 50f;
        utils.drawCenteredText(content, utils.font(), TITLE_FONT_SIZE, ContractPdfAssets.PAGE_WIDTH / 2f, cursorY, "目录");
        cursorY -= 60f;

        for (TocEntry entry : entries) {
            if (cursorY < canvas.contentBottom() + 40f) {
                content.close();
                canvas = pageContext.newPage();
                content = canvas.content();
                cursorY = canvas.contentTop() - 50f;
                utils.drawCenteredText(content, utils.font(), TITLE_FONT_SIZE, ContractPdfAssets.PAGE_WIDTH / 2f, cursorY, "目录");
                cursorY -= 60f;
            }
            drawEntry(content, utils, entry, cursorY);
            cursorY -= ENTRY_STEP;
        }
        content.close();
    }

    private void drawEntry(PDPageContentStream content, ContractPdfDrawUtils utils, TocEntry entry, float y) throws IOException {
        float indent = entry.level() > 1 ? 20f * (entry.level() - 1) : 0f;
        float textX = LEFT_MARGIN + indent;
        utils.showText(content, utils.font(), ENTRY_FONT_SIZE, textX, y, entry.title());
        float leaderStart = textX + utils.measureText(utils.font(), ENTRY_FONT_SIZE, entry.title()) + 4f;
        float leaderEnd = RIGHT_MARGIN - 20f;
        if (leaderEnd > leaderStart) {
            utils.drawDottedLeader(content, leaderStart, leaderEnd, y + 6f);
        }
        String pageText = entry.page() <= 0 ? "-" : String.valueOf(entry.page());
        float pageWidth = utils.measureText(utils.font(), ENTRY_FONT_SIZE, pageText);
        float pageX = RIGHT_MARGIN - pageWidth;
        utils.showText(content, utils.font(), ENTRY_FONT_SIZE, pageX, y, pageText);
    }

    private List<TocEntry> resolveEntries(ContractPdfPayload payload, ContractPdfPayload.PageTwo pageData) {
        List<TocEntry> entries = new ArrayList<>();
        if (pageData != null && pageData.entries() != null && !pageData.entries().isEmpty()) {
            entries.addAll(pageData.entries());
        } else if (payload.toc() != null && !payload.toc().isEmpty()) {
            entries.addAll(payload.toc());
        } else {
            entries.addAll(ContractPdfPayload.sample().toc());
        }

        // 检查是否有真实的图片，如果没有则过滤掉"样品照片"条目
        if (!hasRealImages(payload)) {
            entries = entries.stream()
                    .filter(entry -> !"样品照片".equals(entry.title()))
                    .collect(java.util.stream.Collectors.toList());

            // 重新计算页面编号，确保连续性
            entries = recalculatePageNumbers(entries);
        }

        entries.sort(Comparator.comparingInt(TocEntry::page).thenComparingInt(TocEntry::level));
        return entries;
    }

    /**
     * 重新计算目录条目的页面编号，确保页面号连续
     */
    private List<TocEntry> recalculatePageNumbers(List<TocEntry> entries) {
        List<TocEntry> recalculatedEntries = new ArrayList<>();

        // 按原始页面号排序
        List<TocEntry> sortedEntries = entries.stream()
                .sorted(Comparator.comparingInt(TocEntry::page).thenComparingInt(TocEntry::level))
                .toList();

        int previousPage = 0;

        for (TocEntry entry : sortedEntries) {
            int newPage;

            // 如果是第1个条目或者页面号比前一个条目大，则保持原页面号（如果有间隙则填充间隙）
            if (previousPage == 0) {
                newPage = entry.page();
            } else if (entry.page() > previousPage) {
                // 如果有间隙，使用下一个连续的页面号
                newPage = previousPage + 1;
            } else {
                // 如果页面号不大于前一个，则使用前一个页面号+1
                newPage = previousPage + 1;
            }

            recalculatedEntries.add(new TocEntry(entry.title(), newPage, entry.level()));
            previousPage = newPage;
        }

        return recalculatedEntries;
    }

    /**
     * 检查是否有真实的图片（与ContractPdfRenderer中的逻辑保持一致）
     */
    private boolean hasRealImages(ContractPdfPayload payload) {
        if (payload == null || payload.page5() == null || payload.page5().images() == null || payload.page5().images().isEmpty()) {
            return false;
        }

        return payload.page5().images().stream()
                .anyMatch(imageSlot -> imageSlot.path() != null && !imageSlot.path().trim().isEmpty());
    }
}
