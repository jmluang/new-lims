package com.luang.pdfsigner.security;

import org.springframework.context.annotation.Configuration;
import org.springframework.web.servlet.config.annotation.InterceptorRegistry;
import org.springframework.web.servlet.config.annotation.WebMvcConfigurer;

@Configuration
public class PdfWebSecurityConfiguration implements WebMvcConfigurer {
    private final PdfMultipartHmacInterceptor multipartHmacInterceptor;

    public PdfWebSecurityConfiguration(PdfMultipartHmacInterceptor multipartHmacInterceptor) {
        this.multipartHmacInterceptor = multipartHmacInterceptor;
    }

    @Override
    public void addInterceptors(InterceptorRegistry registry) {
        registry.addInterceptor(multipartHmacInterceptor).addPathPatterns("/**");
    }
}
