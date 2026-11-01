<?php

/**
 * Router for the PHP built-in server that stands in for an Ollama instance.
 *
 * The first path segment names the answer; the rest of the path is ignored.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$answer = explode('/', trim($path, '/'))[0];
if (str_contains($path, '//') === true || str_ends_with($path, '/api/tags') === false) {
	http_response_code(404);
	return;
}

$models = [
	['name' => 'zephyr:7b', 'size' => 4109865159, 'details' => ['family' => 'llama'], 'modified_at' => '2026-01-02T03:04:05Z'],
	['name' => 'alpha', 'details' => ['family' => 'gemma']],
	['name' => 'mistral', 'size' => 'big', 'details' => ['family' => 'mistral']],
	['name' => 'beta', 'size' => '2048'],
	['size' => 0, 'details' => ['family' => '']],
	['name' => 'Capital', 'size' => null, 'modified_at' => null],
	['name' => 'delta', 'size' => 512, 'details' => ['family' => 'qwen']],
];

switch ($answer) {
	case 'ok':
		echo json_encode(['models' => $models]);
		break;
	case 'empty':
		echo json_encode(['models' => []]);
		break;
	case 'nomodels':
		echo json_encode(['other' => 1]);
		break;
	case 'notarray':
		echo json_encode(['models' => 'x']);
		break;
	case 'notjson':
		echo 'garbage';
		break;
	case 'redirect':
		header('Location: /ok/api/tags', true, 302);
		break;
	case 'fail':
		http_response_code(500);
		echo '{"models":[]}';
		break;
	default:
		http_response_code(404);
}
