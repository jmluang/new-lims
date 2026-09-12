package com.luang.pdfsigner;

import com.fasterxml.jackson.databind.ObjectMapper;
import com.luang.pdfsigner.dto.EntrustOrderPayload;
import com.luang.pdfsigner.service.*;
import java.awt.Color;
import java.awt.image.BufferedImage;
import java.io.*;
import java.nio.ByteBuffer;
import java.nio.file.*;
import java.security.MessageDigest;
import java.util.*;
import javax.imageio.ImageIO;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.pdmodel.PDDocument;
import org.apache.pdfbox.pdmodel.interactive.annotation.PDAnnotationWidget;
import org.apache.pdfbox.rendering.PDFRenderer;
import org.apache.pdfbox.text.PDFTextStripper;
import org.bouncycastle.cms.*;
import org.bouncycastle.cms.jcajce.JcaSimpleSignerInfoVerifierBuilder;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import org.springframework.mock.web.MockMultipartFile;
import static org.assertj.core.api.Assertions.assertThat;

public class PdfBehaviorCompatibilityTest {
    @TempDir Path temp;

    @Test
    void preservesPagesAppearanceCoverFieldsAndCryptographicSignatures() throws Exception {
        run(Path.of(System.getProperty("pdf.output.directory", temp.toString())));
    }

    public static void main(String[] args) throws Exception {
        run(Path.of(args[0]));
    }

    private static void run(Path output) throws Exception {
        Files.createDirectories(output);
        var store = PdfMemoryRegressionTest.keyStore();
        Path certificate = output.resolve("test.pfx");
        try (OutputStream stream = Files.newOutputStream(certificate)) {
            store.store(stream, "test-pass".toCharArray());
        }
        Map<String, String> previous = new HashMap<>();
        for (String name : List.of("DEFAULT_PFX_PATH", "DEFAULT_PFX_PASS", "USE_OPTIMIZED_PERFORATION", "PERFORATION_MULTI_STATE_APPEARANCE")) {
            previous.put(name, System.getProperty(name));
        }
        System.setProperty("DEFAULT_PFX_PATH", certificate.toString());
        System.setProperty("DEFAULT_PFX_PASS", "test-pass");
        Map<String, Object> snapshots = new TreeMap<>();
        try {
            var stamp = new MockMultipartFile("image", "stamp.png", "image/png", stamp());
            Path source = output.resolve("input.pdf");
            PdfMemoryRegressionTest.createPdf(source, 3, 0);
            byte[] input = Files.readAllBytes(source);
            for (String mode : List.of("stamp", "sign", "custom")) {
                for (int mask = 0; mask < 8; mask++) {
                    var upload = new MockMultipartFile("pdf", "input.pdf", "application/pdf", input);
                    var result = new SignerService().process(upload,
                            (mask & 1) != 0 ? stamp : null,
                            (mask & 2) != 0 ? stamp : null,
                            (mask & 4) != 0 ? List.of(stamp, stamp) : List.of(),
                            mode, null, "contact", "location", "reason", "SHA256", false, null, null, null);
                    String name = mode + "-" + mask;
                    snapshots.put(name, inspect(result.getPdfBytes(), output.resolve(name + ".pdf")));
                    assertThat(result.getCoverFields().reportNumber()).isEqualTo("MEMORY-001");
                }
            }
            for (int pages : List.of(1, 10, 11)) {
                PdfMemoryRegressionTest.createPdf(source, pages, 0);
                for (boolean optimized : List.of(true, false)) {
                    System.setProperty("USE_OPTIMIZED_PERFORATION", Boolean.toString(optimized));
                    for (boolean multiState : List.of(true, false)) {
                        System.setProperty("PERFORATION_MULTI_STATE_APPEARANCE", Boolean.toString(multiState));
                        var upload = new MockMultipartFile("pdf", "input.pdf", "application/pdf", Files.readAllBytes(source));
                        var result = new SignerService().process(upload, stamp, stamp,
                                "stamp_and_sign", null, "contact", "location", "reason", "SHA256", false, null);
                        String name = "perforation-" + pages + "-" + optimized + "-" + multiState;
                        snapshots.put(name, inspect(result, output.resolve(name + ".pdf")));
                    }
                }
            }
            for (String sample : List.of("sample.pdf", "file.pdf")) {
                try (InputStream stream = PdfBehaviorCompatibilityTest.class.getResourceAsStream("/samples/" + sample)) {
                    var upload = new MockMultipartFile("pdf", sample, "application/pdf", stream);
                    var result = new SignerService().process(upload, stamp, stamp, List.of(stamp), "custom",
                            null, null, null, null, "SHA256", false, null, null, null);
                    snapshots.put("sample-" + sample, inspect(result.getPdfBytes(), output.resolve("sample-" + sample)));
                }
            }
            snapshots.put("contract", inspect(new ContractPdfRenderer().render(ContractPdfPayload.sample()), output.resolve("contract.pdf")));
            Path appendix = output.resolve("appendix.pdf");
            PdfMemoryRegressionTest.createPdf(appendix, 1, 0);
            Path photo = output.resolve("photo.png");
            Files.write(photo, stamp());
            for (String key : List.of("", "gb70001-2015-2")) {
                var sample = ContractPdfPayload.sample();
                var template = new ContractPdfPayload.Template("fixture", "Fixture", "pdf_append", key,
                        null, null, appendix.toString(), null, Map.of());
                var payload = new ContractPdfPayload(sample.meta(), sample.toc(), sample.page1(), sample.page2(),
                        sample.page3(), sample.page4(), new ContractPdfPayload.PageFive(List.of(
                                new ContractPdfPayload.PageFive.ImageSlot(photo.toString(), "Sample image", 0, 1))),
                        sample.page6(), sample.page7(), List.of(template), Map.of());
                String name = key.isEmpty() ? "contract-append-photo" : "contract-gb70001";
                snapshots.put(name, inspect(new ContractPdfRenderer().render(payload), output.resolve(name + ".pdf")));
            }
            try (InputStream json = PdfBehaviorCompatibilityTest.class.getResourceAsStream("/entrust-memory-payload.json")) {
                EntrustOrderPayload payload = new ObjectMapper().readValue(json, EntrustOrderPayload.class);
                snapshots.put("entrust", inspect(new EntrustOrderRenderer().render(payload), output.resolve("entrust.pdf")));
                var mapper = new ObjectMapper();
                var tree = mapper.valueToTree(payload);
                for (int count : List.of(1, 3, 12, 30)) {
                    var samples = ((com.fasterxml.jackson.databind.node.ObjectNode) tree).putArray("samples");
                    for (int index = 0; index < count; index++) {
                        var sample = tree.get("sample").deepCopy();
                        ((com.fasterxml.jackson.databind.node.ObjectNode) sample).put("name", "Sample " + index);
                        samples.add(sample);
                    }
                    String name = "entrust-samples-" + count;
                    snapshots.put(name, inspect(new EntrustOrderRenderer().render(
                            mapper.treeToValue(tree, EntrustOrderPayload.class)), output.resolve(name + ".pdf")));
                }
            }
            ObjectMapper mapper = new ObjectMapper();
            mapper.writerWithDefaultPrettyPrinter().writeValue(output.resolve("snapshot.json").toFile(), snapshots);
            String reference = System.getProperty("pdf.reference.directory");
            if (reference != null) {
                assertThat(mapper.readTree(output.resolve("snapshot.json").toFile()))
                        .isEqualTo(mapper.readTree(Path.of(reference, "snapshot.json").toFile()));
            }
        } finally {
            for (var property : previous.entrySet()) {
                if (property.getValue() == null) {
                    System.clearProperty(property.getKey());
                } else {
                    System.setProperty(property.getKey(), property.getValue());
                }
            }
            Files.deleteIfExists(certificate);
        }
    }

    private static Map<String, Object> inspect(byte[] bytes, Path output) throws Exception {
        Files.write(output, bytes);
        try (PDDocument document = Loader.loadPDF(bytes)) {
            Map<String, Object> result = new TreeMap<>();
            result.put("pages", document.getNumberOfPages());
            result.put("text", new PDFTextStripper().getText(document));
            List<Object> signatures = new ArrayList<>();
            for (var signature : document.getSignatureDictionaries()) {
                String validity = "valid";
                try {
                    CMSSignedData cms = new CMSSignedData(new CMSProcessableByteArray(signature.getSignedContent(bytes)), signature.getContents(bytes));
                    assertThat(cms.getSignerInfos().getSigners()).hasSize(1);
                    for (SignerInformation signer : cms.getSignerInfos().getSigners()) {
                        var certificate = (org.bouncycastle.cert.X509CertificateHolder) cms.getCertificates().getMatches(signer.getSID()).iterator().next();
                        if (!signer.verify(new JcaSimpleSignerInfoVerifierBuilder().build(certificate))) {
                            validity = "invalid";
                        }
                    }
                } catch (Exception exception) {
                    validity = exception.getClass().getSimpleName();
                }
                signatures.add(Arrays.asList(signature.getFilter(), signature.getSubFilter(), signature.getName(),
                        signature.getContactInfo(), signature.getLocation(), signature.getReason(), validity));
            }
            result.put("signatures", signatures);
            List<Object> widgets = new ArrayList<>();
            List<String> pixels = new ArrayList<>();
            PDFRenderer renderer = new PDFRenderer(document);
            for (int page = 0; page < document.getNumberOfPages(); page++) {
                for (var annotation : document.getPage(page).getAnnotations()) {
                    if (annotation instanceof PDAnnotationWidget) {
                        widgets.add(List.of(page, annotation.getRectangle().toString(), annotation.getAnnotationFlags()));
                    }
                }
                BufferedImage image = renderer.renderImageWithDPI(page, 72);
                MessageDigest digest = MessageDigest.getInstance("SHA-256");
                ByteBuffer row = ByteBuffer.allocate(image.getWidth() * 4);
                for (int y = 0; y < image.getHeight(); y++) {
                    row.clear();
                    for (int x = 0; x < image.getWidth(); x++) {
                        row.putInt(image.getRGB(x, y));
                    }
                    digest.update(row.array());
                }
                pixels.add(HexFormat.of().formatHex(digest.digest()));
                image.flush();
            }
            result.put("widgets", widgets);
            result.put("pixels", pixels);
            if (output.getFileName().toString().matches("(stamp|sign|custom)-.*")) {
                for (Object signature : signatures) {
                    assertThat(((List<?>) signature).get(6)).isEqualTo("valid");
                }
            }
            return result;
        }
    }

    private static byte[] stamp() throws IOException {
        BufferedImage image = new BufferedImage(120, 120, BufferedImage.TYPE_INT_ARGB);
        var graphics = image.createGraphics();
        graphics.setColor(new Color(220, 30, 30, 180));
        graphics.drawOval(5, 5, 110, 110);
        graphics.fillRect(30, 50, 60, 20);
        graphics.dispose();
        ByteArrayOutputStream output = new ByteArrayOutputStream();
        ImageIO.write(image, "png", output);
        return output.toByteArray();
    }
}
