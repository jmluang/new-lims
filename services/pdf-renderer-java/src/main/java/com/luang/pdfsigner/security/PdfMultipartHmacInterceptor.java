package com.luang.pdfsigner.security;

import com.luang.pdfsigner.security.MultipartRequestDigestVerifier.BodyReceiveDeadlineExceededException;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletResponse;
import java.util.Locale;
import org.springframework.http.HttpStatus;
import org.springframework.http.MediaType;
import org.springframework.stereotype.Component;
import org.springframework.web.multipart.MultipartHttpServletRequest;
import org.springframework.web.server.ResponseStatusException;
import org.springframework.web.servlet.HandlerInterceptor;

@Component
public final class PdfMultipartHmacInterceptor implements HandlerInterceptor {
    private final PdfHmacProperties properties;
    private final MultipartRequestDigestVerifier verifier;

    public PdfMultipartHmacInterceptor(PdfHmacProperties properties, MultipartRequestDigestVerifier verifier) {
        this.properties = properties;
        this.verifier = verifier;
    }

    @Override
    public boolean preHandle(HttpServletRequest request, HttpServletResponse response, Object handler) {
        if (!properties.enabled() || !isMultipart(request)) {
            return true;
        }
        if (!(request instanceof MultipartHttpServletRequest multipart)) {
            throw new ResponseStatusException(HttpStatus.BAD_REQUEST, "PDF_MULTIPART_REQUIRED");
        }
        try {
            verifier.verify(multipart, request.getHeader(PdfHmacAuthenticationFilter.PART_MANIFEST_SHA256));
        } catch (BodyReceiveDeadlineExceededException exception) {
            throw new ResponseStatusException(HttpStatus.REQUEST_TIMEOUT, "PDF_HMAC_BODY_RECEIVE_TIMEOUT", exception);
        } catch (RuntimeException exception) {
            throw new ResponseStatusException(HttpStatus.UNAUTHORIZED, "PDF_HMAC_BODY_MISMATCH", exception);
        }
        return true;
    }

    private static boolean isMultipart(HttpServletRequest request) {
        String contentType = request.getContentType();
        return contentType != null
                && contentType.toLowerCase(Locale.ROOT).startsWith(MediaType.MULTIPART_FORM_DATA_VALUE);
    }
}
