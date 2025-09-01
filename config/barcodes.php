<?php
return [
    'queue'      => 'barcodes',
    'chunk_size' => env('BARCODES_CHUNK_SIZE', 20),
    'gs_bin'     => env('GS_BIN', PHP_OS_FAMILY === 'Windows' ? 'gswin64c' : 'gs'),
    'make_pdf'   => env('BARCODES_MAKE_PDF', true),
    'make_eps'   => env('BARCODES_MAKE_EPS', true),
];
