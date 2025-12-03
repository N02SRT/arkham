<?php
return [
    'queue'      => 'barcodes',
    'chunk_size' => env('BARCODES_CHUNK_SIZE', 20),
    'gs_bin'     => env('GS_BIN', PHP_OS_FAMILY === 'Windows' ? 'gswin64c' : 'gs'),
    'enable_pdf'   => env('BARCODES_MAKE_PDF', true),
    'enable_eps'   => env('BARCODES_MAKE_EPS', true),
    'make_ean13' => env('BARCODES_MAKE_EAN13', true),
    'zip' => [
        'compression' => env('BARCODES_ZIP_COMPRESSION', 'deflate'), // 'copy' | 'deflate'
        'level'       => env('BARCODES_ZIP_LEVEL', 3),            // 0..9 (deflate only)
    ],
];
