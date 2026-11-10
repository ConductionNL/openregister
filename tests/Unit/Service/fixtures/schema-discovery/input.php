<?php

/**
 * Inputs for SchemaServiceDiscoveryGoldenTest: strings, single values, numeric range pairs and object rows.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

return [
	'strings' => ['2024-02-29', '2024-02-30', '2024-01-15T10:20:30+0100', '2024-01-15T10:20:30+01:00', '2024-01-15T10:20:30',
		'550e8400-e29b-41d4-a716-446655440000', 'jan@example.org', 'https://example.org/a', '12:30:00', 'PT1H30M', 'P', '#a1B2c3', 'example.org',
		'10.0.0.1', '::1', 'fe80::1', '42', '4.2', '-3', 'true', 'No', '0', 'camelCase', 'PascalCase', 'Pascal', 'snake_case_x', 'SCREAMING_SNAKE',
		'A', 'report.pdf', 'dir/file.txt', 'C:\\temp', 'plain text', '', 'ON'],
	'values' => ['string' => 'hello.txt', 'empty string' => '', 'int' => 7, 'float' => 2.5, 'true' => true, 'false' => false, 'null' => null,
		'empty array' => [], 'list' => [1, 'a', [2]], 'assoc' => ['a' => 1, 'b' => [2]], 'object' => (object)['x' => 1, 'y' => 2], 'date' => '2024-02-29'],
	'ranges' => ['first' => [null, ['min' => 1, 'max' => 1, 'type' => 'integer']], 'same int' => [['min' => 1, 'max' => 5, 'type' => 'integer'], ['min' => -2, 'max' => 3, 'type' => 'integer']],
		'promote' => [['min' => 1, 'max' => 5, 'type' => 'integer'], ['min' => 0.5, 'max' => 9.5, 'type' => 'number']],
		'keep number' => [['min' => 1.5, 'max' => 5, 'type' => 'number'], ['min' => 7, 'max' => 7, 'type' => 'integer']],
		'weird existing' => [['min' => 1, 'max' => 5, 'type' => 'string'], ['min' => 0, 'max' => 9, 'type' => 'integer']],
		'weird equal' => [['min' => 1, 'max' => 5, 'type' => 'string'], ['min' => 0, 'max' => 9, 'type' => 'string']],
		'int then weird' => [['min' => 1, 'max' => 5, 'type' => 'integer'], ['min' => 0, 'max' => 9, 'type' => 'string']]],
	'objects' => [
		['@self' => ['id' => 1], 'name' => 'Alpha', 'age' => 30, 'score' => 1.5, 'email' => 'a@example.org', 'tags' => ['x', 'y'], 'meta' => ['k' => 1], 'when' => '2024-02-29', 'nothing' => null, 'blank' => '', 'flag' => true, 'status' => 'open'],
		['name' => 'Bo', 'age' => 41, 'score' => 3, 'email' => 'not-an-email', 'tags' => [], 'meta' => ['k' => 2, 'j' => 3], 'when' => '2024-01-15T10:20:30+01:00', 'flag' => false, 'status' => 'closed', 'extra' => (object)['z' => 1]],
		['name' => 'Charlotte-Long-Name', 'age' => 19, 'score' => -2, 'tags' => ['z'], 'meta' => 'scalar now', 'when' => '12:30:00', 'status' => 'open', 'n1' => 'a', 'extra' => (object)['q' => 2]],
		['name' => 'Dee', 'age' => '22', 'status' => 'pending', 'n1' => 'b'],
		['name' => 'Eve', 'status' => 'open', 'n1' => 'c'], ['name' => 'Fay', 'n1' => 'd'], ['name' => 'Gus', 'n1' => 'e'], ['name' => 'Hal', 'n1' => 'f'],
		['name' => 'Ivy', 'n1' => 'g'], ['name' => 'Jo', 'n1' => 'h'], ['name' => 'Kim', 'n1' => 'i'], ['name' => 'Alpha', 'n1' => 'a'],
	],
];
