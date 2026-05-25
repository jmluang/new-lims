import com.example.pdfsigner.service.SignerService;
import org.springframework.core.io.ByteArrayResource;
import org.springframework.http.ResponseEntity;
import org.springframework.mock.web.MockMultipartFile;
import java.nio.file.Files;
import java.nio.file.Paths;

public class TestSignature {
    public static void main(String[] args) throws Exception {
        SignerService signerService = new SignerService();
        
        // Read test files
        byte[] pdfBytes = Files.readAllBytes(Paths.get("src/test/resources/test-doc.pdf"));
        byte[] stampBytes = Files.readAllBytes(Paths.get("src/test/resources/stamp.png"));
        byte[] signatureBytes = Files.readAllBytes(Paths.get("src/test/resources/signature.png"));
        
        MockMultipartFile pdfFile = new MockMultipartFile("pdf", "test-doc.pdf", "application/pdf", pdfBytes);
        MockMultipartFile stampFile = new MockMultipartFile("perforation_image", "stamp.png", "image/png", stampBytes);
        MockMultipartFile signatureFile = new MockMultipartFile("signature_appearance_image", "signature.png", "image/png", signatureBytes);
        
        ResponseEntity<ByteArrayResource> response = signerService.processPdf(
            pdfFile,
            stampFile,
            signatureFile,
            "stamp_and_sign",
            null,
            "John Doe",
            "San Francisco",
            "Document Approval",
            "SHA256",
            false,
            null
        );
        
        if (response.getStatusCodeValue() == 200) {
            Files.write(Paths.get("output_test.pdf"), response.getBody().getByteArray());
            System.out.println("PDF generated successfully!");
        } else {
            System.out.println("Failed to generate PDF: " + response.getStatusCodeValue());
        }
    }
}