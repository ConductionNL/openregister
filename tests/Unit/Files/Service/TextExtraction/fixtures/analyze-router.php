<?php

/**
 * Router for PHP's built-in server: a stand-in analyze endpoint.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string)file_get_contents('php://input'), true);
header('Content-Type: application/json');

switch (strtok(ltrim($path, '/'), '/')) {
	case 'ok':
		echo json_encode([
			['entity_type' => 'PERSON', 'start' => 0, 'end' => 4, 'score' => 0.9],
			['entity_type' => 'EMAIL_ADDRESS', 'start' => 0, 'end' => 4, 'score' => 0.1],
			['entity_type' => 'X_ECHO', 'text' => json_encode($body), 'score' => 0.99],
		]);
		break;
	case 'auth':
		echo json_encode(['pii_entities' => [['entity_type' => 'PERSON', 'text' => (string)($_SERVER['HTTP_AUTHORIZATION'] ?? 'none'), 'start' => 0, 'end' => 1]]]);
		break;
	case 'reject':
		http_response_code(422);
		echo 'entities: unknown name';
		break;
	case 'down':
		http_response_code(503);
		echo 'down';
		break;
	default:
		echo 'not json at all';
}
