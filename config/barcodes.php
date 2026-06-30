<?php
return [
    'queue'      => 'barcodes',
    'chunk_size' => env('BARCODES_CHUNK_SIZE', 20),
    'gs_bin'     => env('GS_BIN', PHP_OS_FAMILY === 'Windows' ? 'gswin64c' : 'gs'),
    'enable_pdf'   => env('BARCODES_MAKE_PDF', true),
    'enable_eps'   => env('BARCODES_MAKE_EPS', true),
    'make_ean13' => env('BARCODES_MAKE_EAN13', true),
    'cache_days' => env('BARCODES_CACHE_DAYS', 7), // Number of days to cache generated barcode files
    // How long the finalize Redis lock is held. Must comfortably exceed the time
    // it takes to zip the largest expected package (100k+ barcodes = ~600k files).
    'finalize_lock_ttl' => env('BARCODES_FINALIZE_LOCK_TTL', 3600),
    // How long the /download endpoint will wait for the zip to appear before
    // returning "not ready". Absorbs the race where the caller redirects to the
    // download a moment before the finalizer writes the zip. Keep under PHP-FPM
    // request_terminate_timeout and the caller's HTTP timeout.
    'download_wait_seconds' => env('BARCODES_DOWNLOAD_WAIT_SECONDS', 25),
    'zip' => [
        'compression' => env('BARCODES_ZIP_COMPRESSION', 'deflate'), // 'copy' | 'deflate'
        'level'       => env('BARCODES_ZIP_LEVEL', 3),            // 0..9 (deflate only)
    ],
];
