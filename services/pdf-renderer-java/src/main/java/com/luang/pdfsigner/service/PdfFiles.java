package com.luang.pdfsigner.service;

import java.io.File;
import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import org.apache.pdfbox.io.IOUtils;
import org.apache.pdfbox.io.MemoryUsageSetting;
import org.apache.pdfbox.io.RandomAccessStreamCache.StreamCacheCreateFunction;
import org.apache.pdfbox.io.ScratchFile;
import org.apache.pdfbox.pdmodel.PDDocument;

/** Owns the input and intermediate revisions of one PDF operation. */
public final class PdfFiles implements AutoCloseable {
    private final Path directory;

    public PdfFiles() throws IOException {
        directory = Files.createTempDirectory("pdf-operation-");
    }

    public File create() throws IOException {
        return Files.createTempFile(directory, "revision-", ".pdf").toFile();
    }

    public File save(PDDocument document) throws IOException {
        File output = create();
        document.save(output);
        return output;
    }

    public File saveIncremental(PDDocument document) throws IOException {
        File output = create();
        try (var stream = new java.io.BufferedOutputStream(Files.newOutputStream(output.toPath()), 64 * 1024)) {
            document.saveIncremental(stream);
        }
        return output;
    }

    public File sign(PDDocument document, SimpleSignatureInterface signer) throws IOException {
        File output = create();
        try (var stream = new java.io.BufferedOutputStream(Files.newOutputStream(output.toPath()), 64 * 1024)) {
            var signing = document.saveIncrementalForExternalSigning(stream);
            byte[] signature;
            try (var content = signing.getContent()) {
                signature = signer.sign(content);
            }
            signing.setSignature(signature);
        }
        return output;
    }

    public File replace(File previous, File next) throws IOException {
        if (!previous.equals(next)) {
            Files.deleteIfExists(previous.toPath());
        }
        return next;
    }

    public static StreamCacheCreateFunction streamCache() {
        String mode = setting("PDFBOX_MEMORY_MODE");
        if ("mixed".equalsIgnoreCase(mode)) {
            long limit = 64;
            try {
                String configured = setting("PDFBOX_MAX_MAIN_MEMORY_MB");
                if (configured != null) {
                    limit = Long.parseLong(configured);
                }
            } catch (NumberFormatException ignored) {
            }
            long bytes = Math.max(1, Math.min(limit, 1024)) * 1024L * 1024L;
            return () -> new ScratchFile(MemoryUsageSetting.setupMixed(bytes));
        }
        return IOUtils.createTempFileOnlyStreamCache();
    }

    private static String setting(String name) {
        String value = System.getenv(name);
        return value == null || value.isBlank() ? System.getProperty(name) : value;
    }

    @Override
    public void close() throws IOException {
        IOException failure = null;
        try (var paths = Files.newDirectoryStream(directory)) {
            for (Path path : paths) {
                try {
                    Files.deleteIfExists(path);
                } catch (IOException exception) {
                    if (failure == null) {
                        failure = exception;
                    } else {
                        failure.addSuppressed(exception);
                    }
                }
            }
        }
        if (failure != null) {
            throw failure;
        }
        Files.deleteIfExists(directory);
    }
}
