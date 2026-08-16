package com.luang.pdfsigner.signature;

import org.bouncycastle.tsp.TimeStampToken;

public interface Rfc3161TimestampClient {
    TimeStampToken timestamp(byte[] signatureValue) throws Exception;
}
