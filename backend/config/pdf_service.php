<?php

return [
    'base_url' => env('PDF_SERVICE_BASE_URL', 'http://127.0.0.1:8080'),
    'timeout' => (float) env('PDF_SERVICE_TIMEOUT', 120),
    'enabled' => (bool) env('PDF_SERVICE_ENABLED', false),
    'organization_scope' => env('PDF_SIGNING_ORGANIZATION_SCOPE', 'default'),
    'hmac' => [
        'enabled' => (bool) env('PDF_SERVICE_HMAC_ENABLED', true),
        'active_key_id' => env('PDF_SERVICE_HMAC_ACTIVE_KEY_ID', 'primary'),
        'keys' => env('PDF_SERVICE_HMAC_KEYS', ''),
    ],
    'workflow' => [
        'source_max_bytes' => (int) env('PDF_WORKFLOW_SOURCE_MAX_BYTES', 20_971_520),
        'generated_revision_max_bytes' => (int) env('PDF_WORKFLOW_GENERATED_REVISION_MAX_BYTES', 33_554_432),
        'operation_lease_seconds' => (int) env('PDF_WORKFLOW_OPERATION_LEASE_SECONDS', 300),
    ],

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

        // How long the signing response's download link stays valid. The link
        // carries its own authorisation so a browser can fetch it without the
        // SPA's bearer token; it is used seconds after signing, so this only
        // has to cover an operator who steps away mid-batch.
        'download_link_ttl_minutes' => (int) env('PDF_SIGNING_DOWNLOAD_LINK_TTL_MINUTES', 30),

        // Perforation seal geometry, forwarded to the Java service verbatim.
        'group_size' => (int) env('PDF_SIGNING_GROUP_SIZE', 10),
        'stamp_total_height_mm' => (float) env('PDF_SIGNING_STAMP_TOTAL_HEIGHT_MM', 13.5),
        'signature_size_mm' => (float) env('PDF_SIGNING_SIGNATURE_SIZE_MM', 13.5),
        'signature_margin_mm' => (float) env('PDF_SIGNING_SIGNATURE_MARGIN_MM', 10),

        // How long a signing working directory may sit before it is treated as
        // abandoned. Per-job cleanup runs in a finally block, which a killed
        // process never reaches, so leftovers are swept on the next signing.
        // Must exceed the longest signing a report can legitimately take.
        'working_dir_ttl_seconds' => (int) env('PDF_SIGNING_WORKING_DIR_TTL', 21600),

        // Signing takes time proportional to page count times document size.
        // Jobs slower than this are logged at warning level with their page
        // count and byte sizes. Set to 0 to disable the warning.
        'slow_warning_seconds' => (int) env('PDF_SIGNING_SLOW_WARNING_SECONDS', 20),

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
