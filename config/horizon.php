<?php
return [
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => [env('BARCODES_QUEUE','barcodes')],   // only our queue
            'balance' => 'auto',
            'maxProcesses' => 3,
            'memory' => 256,
            'tries' => 3,
        ],
    ],
];
