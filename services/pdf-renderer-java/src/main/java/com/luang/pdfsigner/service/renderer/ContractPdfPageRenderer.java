package com.luang.pdfsigner.service.renderer;

import com.luang.pdfsigner.service.ContractPdfPayload;
import java.io.IOException;
import org.apache.pdfbox.pdmodel.PDDocument;

public interface ContractPdfPageRenderer<T> {
    void render(PDDocument document,
                ContractPdfPayload payload,
                T pageData,
                ContractPdfPageContext pageContext,
                ContractPdfDrawUtils utils) throws IOException;
}
