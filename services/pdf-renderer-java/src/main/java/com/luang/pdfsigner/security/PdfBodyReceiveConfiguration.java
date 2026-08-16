package com.luang.pdfsigner.security;

import org.apache.coyote.http11.AbstractHttp11Protocol;
import org.springframework.boot.web.embedded.tomcat.TomcatServletWebServerFactory;
import org.springframework.boot.web.server.WebServerFactoryCustomizer;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

@Configuration
public class PdfBodyReceiveConfiguration {
    @Bean
    WebServerFactoryCustomizer<TomcatServletWebServerFactory> pdfBodyReceiveTimeoutCustomizer() {
        return factory -> factory.addConnectorCustomizers(connector -> {
            if (connector.getProtocolHandler() instanceof AbstractHttp11Protocol<?> protocol) {
                protocol.setDisableUploadTimeout(false);
                protocol.setConnectionUploadTimeout(PdfHmacAuthenticationFilter.BODY_RECEIVE_DEADLINE_SECONDS * 1000);
            }
        });
    }
}
