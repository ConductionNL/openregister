<?php

/**
 * Router for `php -S` that stands in for the OpenAI, Fireworks and Ollama chat APIs.
 *
 * Every request is appended to the file named by LLM_STUB_LOG as one JSON line.
 * The model `model-error` answers HTTP 500; any other model answers with one
 * assistant message naming the path it was asked on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string)file_get_contents('php://input'), true);
file_put_contents(
	(string)getenv('LLM_STUB_LOG'),
	json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'auth' => ($_SERVER['HTTP_AUTHORIZATION'] ?? null), 'body' => $body]) . "\n",
	FILE_APPEND
);

header('Content-Type: application/json');
$model = ($body['model'] ?? '');
if ($model === 'model-error') {
	http_response_code(500);
	echo json_encode(['error' => ['message' => 'stub failure', 'type' => 'server_error', 'code' => null]]);
	return;
}

$content = 'Answer from ' . $path;
if (str_ends_with((string)$path, '/api/chat') === true) {
	echo json_encode(
		[
			'model' => $model,
			'created_at' => '2026-01-01T00:00:00Z',
			'message' => ['role' => 'assistant', 'content' => $content],
			'done' => true,
			'prompt_eval_count' => 11,
			'eval_count' => 7,
			'total_duration' => 5000000,
		]
	);
	return;
}

echo json_encode(
	[
		'id' => 'chatcmpl-1',
		'object' => 'chat.completion',
		'created' => 1700000000,
		'model' => $model,
		'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop', 'logprobs' => null]],
		'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4, 'total_tokens' => 7],
	]
);
