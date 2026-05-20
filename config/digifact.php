<?php

return [
    'env' => env('DIGIFACT_ENV', 'test'),
    'base_url' => env('DIGIFACT_ENV', 'test') === 'production'
        ? env('DIGIFACT_PROD_BASE_URL')
        : env('DIGIFACT_TEST_BASE_URL'),
    'test_base_url' => env('DIGIFACT_TEST_BASE_URL', 'https://testnucgt.digifact.com/api'),
    'production_base_url' => env('DIGIFACT_PROD_BASE_URL', 'https://nucgt.digifact.com/gt.com.apinuc/api'),
    'username' => env('DIGIFACT_USERNAME'),
    'password' => env('DIGIFACT_PASSWORD'),
    'token' => env('DIGIFACT_TOKEN'),
    'timeout' => (int) env('DIGIFACT_TIMEOUT', 10),
    'endpoints' => [
        'token' => env('DIGIFACT_TOKEN_PATH', 'login/get_token'),
        'shared' => env('DIGIFACT_SHARED_PATH', 'Shared'),
        'certify_invoice' => env('DIGIFACT_CERTIFY_INVOICE_PATH', 'v2/transform/nuc_json'),
        'cancel_invoice' => env('DIGIFACT_CANCEL_INVOICE_PATH', 'v2/transform/nuc_json'),
        'get_document' => env('DIGIFACT_GET_DOCUMENT_PATH', 'GetDocument'),
    ],
];
