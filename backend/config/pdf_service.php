<?php

return [
    'base_url' => env('PDF_SERVICE_BASE_URL', 'http://localhost:8080'),
    'timeout' => (float) env('PDF_SERVICE_TIMEOUT', 120),
    'enabled' => (bool) env('PDF_SERVICE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | PDF 防篡改签章
    |--------------------------------------------------------------------------
    |
    | The signing desk delegates stamping and PKCS#7 signing to the same Java
    | service as the renderer; only the request shape differs.
    |
    */
    'signing' => [
        // Master switch for the tamper-proof signing desk. Signing additionally
        // requires `enabled` above, since it runs through the Java service.
        'enabled' => (bool) env('PDF_SIGNING_ENABLED', true),

        // Largest PDF the signing desk accepts, in kilobytes.
        'max_upload_kb' => (int) env('PDF_SIGNING_MAX_UPLOAD_KB', 20480),

        // Digest the Java signer computes over the document.
        'hash_algo' => env('PDF_SIGNING_HASH_ALGO', 'SHA256'),

        // Perforation seal geometry, forwarded to the Java service verbatim.
        'group_size' => (int) env('PDF_SIGNING_GROUP_SIZE', 10),
        'stamp_total_height_mm' => (float) env('PDF_SIGNING_STAMP_TOTAL_HEIGHT_MM', 13.5),
        'signature_size_mm' => (float) env('PDF_SIGNING_SIGNATURE_SIZE_MM', 13.5),
        'signature_margin_mm' => (float) env('PDF_SIGNING_SIGNATURE_MARGIN_MM', 10),

        // RFC 3161 timestamping.
        'tsa_enabled' => (bool) env('PDF_SIGNING_TSA_ENABLED', true),
        'tsa_url' => env('PDF_SIGNING_TSA_URL', ''),

        /*
         | 处理光度数据后签名
         |
         | Ported from zs-lims but switched OFF: the pipeline stays in the code
         | base so it can be revived, and both the API and the UI refuse the
         | mode while this is false. Turning it on also requires the optional
         | composer packages listed in docs/plans (fpdi/tcpdf/pdfparser).
         */
        'photometric_removal_enabled' => (bool) env('PDF_SIGNING_PHOTOMETRIC_REMOVAL_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | 公开验证页
    |--------------------------------------------------------------------------
    |
    | The unauthenticated /verify page lets a report recipient check a PDF.
    | Disable it to keep verification behind the login.
    |
    */
    'public_verification' => [
        'enabled' => (bool) env('PDF_PUBLIC_VERIFICATION_ENABLED', true),

        // Keep a copy of every publicly verified file for later investigation.
        'store_uploads' => (bool) env('PDF_PUBLIC_VERIFICATION_STORE_UPLOADS', false),
    ],
];
