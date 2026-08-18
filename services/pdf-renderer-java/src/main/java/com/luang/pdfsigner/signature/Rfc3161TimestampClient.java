package com.luang.pdfsigner.signature;

import org.bouncycastle.tsp.TimeStampToken;

public interface Rfc3161TimestampClient {
    /**
     * Fails when this client could not produce a timestamp for any input.
     *
     * PAdES-B-T needs a timestamp over a signature that already exists, so the
     * timestamp call necessarily comes after the private key has run. That made
     * a missing TSA URL — knowable at startup — cost a real signature and push
     * the document into manual review. Whatever can be judged before the key is
     * judged here instead.
     */
    default void requireReadyConfiguration() {
    }

    TimeStampToken timestamp(byte[] signatureValue) throws Exception;
}
