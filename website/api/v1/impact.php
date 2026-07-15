<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

const RECEIPT_WIDTH_INCHES = 3.125;
const AVERAGE_RECEIPT_LENGTH_INCHES = 6.0;
const CO2_PER_RECEIPT_GRAMS = 2.5;

try {
    $jobs = (int)database()->query(
        'SELECT COALESCE(SUM(print_job_count), 0) FROM installations'
    )->fetchColumn();

    $paperFeet = ($jobs * AVERAGE_RECEIPT_LENGTH_INCHES) / 12;
    $paperSquareFeet = ($jobs * RECEIPT_WIDTH_INCHES * AVERAGE_RECEIPT_LENGTH_INCHES) / 144;
    $response = [
        'receiptsAvoided' => $jobs,
        'paperFeetAvoided' => round($paperFeet, 1),
        'paperSquareFeetAvoided' => round($paperSquareFeet, 1),
        'co2GramsAvoided' => round($jobs * CO2_PER_RECEIPT_GRAMS, 1),
        'updatedAt' => gmdate(DATE_ATOM),
        'assumptions' => [
            'receiptWidthInches' => RECEIPT_WIDTH_INCHES,
            'averageReceiptLengthInches' => AVERAGE_RECEIPT_LENGTH_INCHES,
            'co2PerReceiptGrams' => CO2_PER_RECEIPT_GRAMS,
        ],
    ];

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300, stale-while-revalidate=3600');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['error' => 'Impact totals are temporarily unavailable.']);
}
