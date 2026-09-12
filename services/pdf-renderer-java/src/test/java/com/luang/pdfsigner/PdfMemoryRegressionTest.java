package com.luang.pdfsigner;

import com.luang.pdfsigner.service.PdfCoverExtractor;
import com.luang.pdfsigner.service.SignerService;
import com.luang.pdfsigner.service.SimpleSignatureInterface;
import java.awt.Color;
import java.awt.image.BufferedImage;
import java.io.*;
import java.math.BigInteger;
import java.nio.file.*;
import java.security.*;
import java.security.cert.Certificate;
import java.time.Instant;
import java.util.Date;
import java.util.List;
import java.util.concurrent.TimeUnit;
import javax.imageio.ImageIO;
import org.apache.pdfbox.Loader;
import org.apache.pdfbox.cos.COSName;
import org.apache.pdfbox.io.IOUtils;
import org.apache.pdfbox.pdmodel.*;
import org.apache.pdfbox.pdmodel.font.*;
import org.bouncycastle.asn1.x500.X500Name;
import org.bouncycastle.cert.jcajce.*;
import org.bouncycastle.cms.*;
import org.bouncycastle.cms.jcajce.JcaSimpleSignerInfoVerifierBuilder;
import org.bouncycastle.operator.jcajce.JcaContentSignerBuilder;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;
import org.springframework.mock.web.MockMultipartFile;
import static org.assertj.core.api.Assertions.assertThat;

public class PdfMemoryRegressionTest {
    @TempDir Path temp;

    @Test
    void extractsLargeCoverIn128MiBHeap() throws Exception {
        Path source = temp.resolve("large.pdf");
        createPdf(source, 1, 64);
        Path log = temp.resolve("child.log");
        Process child = new ProcessBuilder(
                Path.of(System.getProperty("java.home"), "bin", "java").toString(),
                "-Xmx128m", "-XX:+UseSerialGC", "-Djava.awt.headless=true",
                "-cp", System.getProperty("surefire.test.class.path", System.getProperty("java.class.path")),
                PdfMemoryRegressionTest.class.getName(), source.toString())
                .redirectErrorStream(true).redirectOutput(log.toFile()).start();
        boolean finished = child.waitFor(45, TimeUnit.SECONDS);
        if (!finished) {
            child.destroyForcibly();
        }
        assertThat(finished).isTrue();
        assertThat(child.exitValue()).withFailMessage(Files.readString(log)).isZero();
    }

    public static void main(String[] args) throws Exception {
        try (InputStream input = Files.newInputStream(Path.of(args[0]))) {
            assertThat(new PdfCoverExtractor().extract(input).reportNumber()).isEqualTo("MEMORY-001");
        }
    }

    @Test
    void signsWithoutMaterializingTheInputAndProducesValidCms() throws Exception {
        KeyStore store = keyStore();
        byte[] content = "Streaming signature regression".getBytes(java.nio.charset.StandardCharsets.UTF_8);
        InputStream input = new ByteArrayInputStream(content) {
            @Override public byte[] readAllBytes() {
                throw new AssertionError("Signing must stream the content");
            }
        };
        byte[] signature = new SimpleSignatureInterface((PrivateKey) store.getKey("test", "test-pass".toCharArray()),
                store.getCertificateChain("test"), "SHA256", false, null).sign(input);
        CMSSignedData cms = new CMSSignedData(new CMSProcessableByteArray(content), signature);
        for (SignerInformation signer : cms.getSignerInfos().getSigners()) {
            assertThat(signer.verify(new JcaSimpleSignerInfoVerifierBuilder().build(store.getCertificate("test").getPublicKey()))).isTrue();
        }
    }

    @Test
    void readsUploadOnceAndClosesItsStream() throws Exception {
        Path source = temp.resolve("input.pdf");
        createPdf(source, 1, 0);
        int[] counts = new int[2];
        MockMultipartFile upload = new MockMultipartFile("pdf", "input.pdf", "application/pdf", Files.readAllBytes(source)) {
            @Override public InputStream getInputStream() throws IOException {
                counts[0]++;
                return new ByteArrayInputStream(getBytes()) {
                    @Override public void close() throws IOException {
                        counts[1]++;
                        super.close();
                    }
                };
            }
        };
        new SignerService().process(upload, null, null, "custom", null, null, null, null, "SHA256", false, null);
        assertThat(counts).containsExactly(1, 1);
    }

    @Test
    void closesEveryTemporaryRevisionWithTheResult() throws Exception {
        Path source = temp.resolve("input.pdf");
        createPdf(source, 1, 0);
        var upload = new MockMultipartFile("pdf", "input.pdf", "application/pdf", Files.readAllBytes(source));
        Path directory;
        try (var result = new SignerService().processToFile(upload, null, null, List.of(), "custom",
                null, null, null, null, "SHA256", false, null, null, null)) {
            directory = result.pdfFile().toPath().getParent();
            assertThat(result.pdfFile()).exists();
            assertThat(result.coverFields().reportNumber()).isEqualTo("MEMORY-001");
        }
        assertThat(directory).doesNotExist();
    }

    @Test
    void successiveEqualLengthStampsKeepTheirOwnImage() throws Exception {
        Path source = temp.resolve("input.pdf");
        createPdf(source, 3, 0);
        byte[] red = solidStamp(Color.RED);
        byte[] blue = solidStamp(Color.BLUE);
        assertThat(red.length).isEqualTo(blue.length);
        for (byte[] stamp : List.of(red, blue)) {
            var upload = new MockMultipartFile("pdf", "input.pdf", "application/pdf", Files.readAllBytes(source));
            var image = new MockMultipartFile("perforation_image", "stamp.bmp", "image/bmp", stamp);
            byte[] result = new SignerService().process(upload, image, null, "stamp", null, null, null, null, "SHA256", false, null);
            try (PDDocument document = Loader.loadPDF(result)) {
                var resources = document.getPage(1).getResources();
                boolean found = false;
                for (COSName name : resources.getXObjectNames()) {
                    if (resources.getXObject(name) instanceof org.apache.pdfbox.pdmodel.graphics.image.PDImageXObject object) {
                        int actual = object.getImage().getRGB(0, 0) & 0xffffff;
                        assertThat(actual).isEqualTo((stamp == red ? Color.RED : Color.BLUE).getRGB() & 0xffffff);
                        found = true;
                    }
                }
                assertThat(found).isTrue();
            }
        }
    }

    private byte[] solidStamp(Color color) throws IOException {
        BufferedImage image = new BufferedImage(90, 90, BufferedImage.TYPE_INT_RGB);
        var graphics = image.createGraphics();
        graphics.setColor(color);
        graphics.fillRect(0, 0, 90, 90);
        graphics.dispose();
        ByteArrayOutputStream output = new ByteArrayOutputStream();
        ImageIO.write(image, "bmp", output);
        return output.toByteArray();
    }

    public static KeyStore keyStore() throws Exception {
        KeyPairGenerator generator = KeyPairGenerator.getInstance("RSA");
        generator.initialize(2048);
        KeyPair pair = generator.generateKeyPair();
        X500Name name = new X500Name("CN=PDF Integration Test");
        var certificate = new JcaX509CertificateConverter().getCertificate(new JcaX509v3CertificateBuilder(
                name, BigInteger.ONE, Date.from(Instant.parse("2020-01-01T00:00:00Z")),
                Date.from(Instant.parse("2040-01-01T00:00:00Z")), name, pair.getPublic())
                .build(new JcaContentSignerBuilder("SHA256withRSA").build(pair.getPrivate())));
        KeyStore store = KeyStore.getInstance("PKCS12");
        store.load(null, null);
        store.setKeyEntry("test", pair.getPrivate(), "test-pass".toCharArray(), new Certificate[]{certificate});
        return store;
    }

    public static void createPdf(Path file, int pages, int paddingMiB) throws Exception {
        try (PDDocument document = new PDDocument(IOUtils.createTempFileOnlyStreamCache())) {
            for (int i = 0; i < pages; i++) {
                PDPage page = new PDPage();
                document.addPage(page);
                try (PDPageContentStream stream = new PDPageContentStream(document, page)) {
                    stream.beginText();
                    stream.setFont(new PDType1Font(Standard14Fonts.FontName.HELVETICA), 12);
                    stream.newLineAtOffset(50, 750);
                    stream.showText("Report Number : MEMORY-001");
                    stream.endText();
                }
            }
            if (paddingMiB > 0) {
                var padding = document.getDocument().createCOSStream();
                document.getDocumentCatalog().getCOSObject().setItem(COSName.getPDFName("Fixture"), padding);
                try (OutputStream stream = padding.createRawOutputStream()) {
                    byte[] block = new byte[8192];
                    for (int i = 0; i < paddingMiB * 128; i++) {
                        stream.write(block);
                    }
                }
            }
            document.save(file.toFile());
        }
    }
}
