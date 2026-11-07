<?php

/**
 * Golden test for the integrity passes OasService runs over a generated document.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Pins the repaired document and the report of validateOasIntegrity() on
 * documents with broken references, duplicate operation ids, orphan and unused
 * tags, non-standard methods and status codes, and relative server URLs.
 *
 * @covers \OCA\OpenRegister\Service\OasService
 * @uses \OCA\OpenRegister\Service\Oas\OasNames
 * @uses \OCA\OpenRegister\Service\Oas\OasPropertySanitizer
 * @uses \OCA\OpenRegister\Service\Oas\OasReferenceRepair
 * @uses   \OCA\OpenRegister\Service\Oas\OasValidationReport
 * @uses   \OCA\OpenRegister\Service\Oas\OasRbacAnnotator
 * @uses   \OCA\OpenRegister\Service\Rbac\EffectiveAuthorization
 */
class OasServiceIntegrityGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/oas-service-integrity-golden.json';

	private static function documents(): array {
		$schema = fn (string $ref) => ['content' => ['application/json' => ['schema' => ['$ref' => $ref]]]];
		return [
			'empty' => [],
			'no paths' => ['tags' => [['name' => 'lonely']], 'servers' => 'none'],
			'messy' => [
				'servers' => [['url' => '/apps/openregister/api'], ['url' => 'https://ok.example/api'], ['description' => 'no url']],
				'tags' => [['name' => 'used'], ['name' => 'unused'], 'not a tag', ['description' => 'no name']],
				'components' => ['schemas' => [
					'A' => ['allOf' => [[], ['$ref' => '#/components/schemas/B'], ['type' => 'object'], ['properties' => ['p' => ['type' => 'string']]]]],
					'B' => ['type' => 'object', 'properties' => ['q' => ['$ref' => '#/components/schemas/Missing'], 'r' => ['items' => ['$ref' => 'B']]]],
					'C' => ['$ref' => '#/components/schemas/Gone'],
					'D' => 'not an array',
					'E' => ['allOf' => [[]]],
					'F' => ['$ref' => 'https://elsewhere.example/schema.json'],
					'G' => ['oneOf' => [['$ref' => '#/components/schemas/b'], 'x'], 'anyOf' => [['$ref' => '#/components/schemas/Nope', 'type' => 'integer', 'description' => 'kept']]],
					'H' => ['$ref' => '', 'allOf' => 'x', 'items' => 'not a schema', 'properties' => ['s' => 'not a schema', 't' => ['$ref' => 7]]],
				]],
				'paths' => [
					'/a' => [
						'parameters' => [['name' => 'id']],
						'summary' => 'path summary',
						'get' => ['operationId' => 'dup', 'tags' => ['used', 'orphan'], 'responses' => ['200' => $schema('#/components/schemas/Missing'), '299' => $schema('#/components/schemas/B'), 'default' => []]],
						'post' => ['operationId' => 'dup', 'tags' => ['orphan', '', 7], 'responses' => ['201' => []]],
						'put' => ['operationId' => 'dup', 'responses' => ['200' => $schema('#/components/schemas/A')]],
						'delete' => ['operationId' => '', 'responses' => []],
					],
					'/b' => [
						'get' => ['operationId' => 'dup_2', 'tags' => ['second-orphan']],
						'TRACE' => ['operationId' => 'trace'],
						'Patch' => ['operationId' => 'dup', 'responses' => ['600' => []]],
						'head' => 'not an operation',
					],
				],
			],
		];
	}//end documents()

	public function testIntegrityPassesMatchGolden(): void {
		$service = new OasService($this->createMock(RegisterMapper::class), $this->createMock(SchemaMapper::class), $this->createMock(IURLGenerator::class));
		$oas = new ReflectionProperty(OasService::class, 'oas');
		$report = new ReflectionProperty(OasService::class, 'report');
		$actual = [];
		foreach (self::documents() as $name => $document) {
			$oas->setValue($service, $document);
			$report->setValue($service, new OasValidationReport());
			(new \ReflectionMethod(OasService::class, 'validateOasIntegrity'))->invoke($service);
			$actual[$name] = ['oas' => $oas->getValue($service), 'issues' => $report->getValue($service)->getIssues()];
		}

		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}//end testIntegrityPassesMatchGolden()
}//end class
