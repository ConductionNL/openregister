<?php

/**
 * Router for `php -S` that stands in for the Fireworks and Ollama embedding APIs.
 *
 * Every request is appended to the file named by EMBEDDING_STUB_LOG as one JSON line.
 * The first path segment picks the answer:
 * - /ok/...         HTTP 200 with one embedding [0.25, 0.5, 0.75]
 * - /fail/...       HTTP 500 with the body `stub failure`
 * - /badformat/...  HTTP 200 with a body that has no data[0].embedding
 * - /ollama/api/show  HTTP 200 with an Ollama model_info of embedding_length 512
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true);
file_put_contents(
	(string) getenv('EMBEDDING_STUB_LOG'),
	json_encode(
		[
			'method' => $_SERVER['REQUEST_METHOD'],
			'path' => $path,
			'auth' => ($_SERVER['HTTP_AUTHORIZATION'] ?? null),
			'contentType' => ($_SERVER['CONTENT_TYPE'] ?? null),
			'body' => $body,
		]
	) . "\n",
	FILE_APPEND
);

header('Content-Type: application/json');

if (str_starts_with($path, '/fail/') === true) {
	http_response_code(500);
	echo 'stub failure';
	return;
}

if (str_starts_with($path, '/badformat/') === true) {
	echo json_encode(['data' => []]);
	return;
}

if ($path === '/ollama/api/show') {
	echo json_encode(['model_info' => ['general.architecture' => 'bert', 'bert.embedding_length' => 512]]);
	return;
}

echo json_encode(['data' => [['embedding' => [0.25, 0.5, 0.75]]]]);
