<?php
/**
 * A minimal Ollama-compatible endpoint for the Newman job.
 *
 * Answers `/api/chat` with one fixed assistant message, as a single JSON body or,
 * when the request asks for `stream: true`, as Ollama's newline-delimited frames.
 * `/api/tags` lists the one model. Run with `php -S 127.0.0.1:11434 ollama-stub.php`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

const STUB_MODEL = 'newman-stub';
const STUB_REPLY = 'Stub reply from the Newman LLM endpoint.';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true);
if (is_array($body) === false) {
    $body = [];
}

header('Content-Type: application/json');

if (str_ends_with($path, '/api/tags') === true) {
    echo json_encode(['models' => [['name' => STUB_MODEL, 'model' => STUB_MODEL]]]);
    return;
}

if (str_ends_with($path, '/api/chat') === false) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    return;
}

$done = [
    'model' => STUB_MODEL,
    'done' => true,
    'prompt_eval_count' => 1,
    'eval_count' => 1,
    'total_duration' => 1000000,
];

if (($body['stream'] ?? false) === true) {
    header('Content-Type: application/x-ndjson');
    echo json_encode(['model' => STUB_MODEL, 'message' => ['role' => 'assistant', 'content' => STUB_REPLY], 'done' => false]) . "\n";
    echo json_encode($done + ['message' => ['role' => 'assistant', 'content' => '']]) . "\n";
    return;
}

echo json_encode($done + ['message' => ['role' => 'assistant', 'content' => STUB_REPLY]]);
