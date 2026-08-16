package com.luang.pdfsigner.security;

import jakarta.servlet.ReadListener;
import jakarta.servlet.ServletInputStream;
import jakarta.servlet.http.HttpServletRequest;
import jakarta.servlet.http.HttpServletRequestWrapper;
import java.io.BufferedReader;
import java.io.ByteArrayInputStream;
import java.io.InputStreamReader;
import java.nio.charset.StandardCharsets;

final class CachedBodyHttpServletRequest extends HttpServletRequestWrapper {
    private final byte[] body;

    CachedBodyHttpServletRequest(HttpServletRequest request, byte[] body) {
        super(request);
        this.body = body.clone();
    }

    @Override
    public ServletInputStream getInputStream() {
        ByteArrayInputStream stream = new ByteArrayInputStream(body);
        return new ServletInputStream() {
            @Override
            public boolean isFinished() {
                return stream.available() == 0;
            }

            @Override
            public boolean isReady() {
                return true;
            }

            @Override
            public void setReadListener(ReadListener readListener) {
                // Requests are consumed synchronously by Spring MVC.
            }

            @Override
            public int read() {
                return stream.read();
            }

            @Override
            public int read(byte[] bytes, int offset, int length) {
                return stream.read(bytes, offset, length);
            }
        };
    }

    @Override
    public BufferedReader getReader() {
        return new BufferedReader(new InputStreamReader(getInputStream(), StandardCharsets.UTF_8));
    }
}
