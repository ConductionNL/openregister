<?php

/**
 * Golden record of OasReferenceRepair::repair(): the repaired schema and the report it leaves.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use OCA\OpenRegister\Service\Oas\OasReferenceRepair;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Oas\OasReferenceRepair
 * @uses \OCA\OpenRegister\Service\Oas\OasNames
 * @uses \OCA\OpenRegister\Service\Oas\OasPropertySanitizer
 * @uses \OCA\OpenRegister\Service\Oas\OasReferenceRepair
 * @uses \OCA\OpenRegister\Service\Oas\OasValidationReport
 *
 * @spec openspec/specs/oas-validation/spec.md
 * @uses \OCA\OpenRegister\Service\Oas\OasRbacAnnotator
 * @uses \OCA\OpenRegister\Service\Rbac\EffectiveAuthorization
 */
class OasServiceSchemaReferencesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/oas-schema-references-golden.json';

	public static function cases(): array {
		$adres = '#/components/schemas/Adres';
		$cases = [
			'empty' => [],
			'plain type' => ['type' => 'string'],
			'allOf scalar' => ['allOf' => 'x'],
			'allOf empty' => ['allOf' => []],
			'allOf all invalid' => ['allOf' => [[], 'x', ['description' => 'd']]],
			'allOf mixed' => ['allOf' => [['$ref' => $adres], [], ['type' => 'object'], ['properties' => ['a' => ['type' => 'string']]], ['$ref' => ''], ['$ref' => 5]]],
			'allOf with dangling ref' => ['allOf' => [['$ref' => '#/components/schemas/Missing']]],
			'allOf ref case mismatch' => ['allOf' => [['$ref' => '#/components/schemas/adres']]],
			'ref existing' => ['$ref' => $adres],
			'ref case mismatch' => ['$ref' => '#/components/schemas/ADRES'],
			'ref dangling' => ['$ref' => '#/components/schemas/Missing'],
			'ref dangling with type' => ['$ref' => '#/components/schemas/Missing', 'type' => 'object'],
			'ref dangling with description' => ['$ref' => '#/components/schemas/Missing', 'description' => 'kept'],
			'ref empty' => ['$ref' => ''],
			'ref non string' => ['$ref' => ['x']],
			'ref external' => ['$ref' => 'https://example.org/a.json'],
			'ref definitions' => ['$ref' => '#/definitions/Missing'],
			'properties nested' => ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/components/schemas/Gone'], 'b' => 'scalar', 'c' => ['properties' => ['d' => ['$ref' => '#/components/schemas/adres']]]]],
			'items nested' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Gone']],
			'items scalar' => ['type' => 'array', 'items' => 'x'],
			'oneOf nested' => ['oneOf' => [['$ref' => '#/components/schemas/Gone'], 'x', ['$ref' => $adres]]],
			'anyOf nested' => ['anyOf' => [['items' => ['$ref' => '#/components/schemas/gone']]]],
			'anyOf scalar' => ['anyOf' => 'x'],
			'deep mix' => [
				'allOf' => [['$ref' => '#/components/schemas/adres'], ['type' => 'object', 'properties' => ['p' => ['$ref' => '#/components/schemas/Nope']]]],
				'properties' => ['list' => ['type' => 'array', 'items' => ['oneOf' => [['$ref' => '#/components/schemas/Nope']]]]],
			],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	#[DataProvider('cases')]
	public function testValidateSchemaReferencesMatchesGolden(array $schema): void {
		$report = new OasValidationReport();
		$repair = new OasReferenceRepair(componentNames: ['Adres', 'Persoon'], report: $report);
		$repair->repair(schema: $schema, context: 'components.schemas.Test');

		$actual = json_decode((string)json_encode(['schema' => $schema, 'issues' => $report->getIssues()]), true);
		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testValidateSchemaReferencesMatchesGolden()
}//end class
