<?php

/**
 * Golden record of the whole document OasService::createOas() generates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\OasValidationException;
use OCA\OpenRegister\Service\OasService;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\OasService
 * @uses \OCA\OpenRegister\Service\Oas\OasNames
 * @uses \OCA\OpenRegister\Service\Oas\OasPropertySanitizer
 * @uses \OCA\OpenRegister\Service\Oas\OasReferenceRepair
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Oas\OasValidationReport
 * @uses \OCA\OpenRegister\Service\Oas\OasRbacAnnotator
 *
 * @spec openspec/specs/oas-generation/spec.md
 * @uses \OCA\OpenRegister\Exception\OasValidationException
 * @uses \OCA\OpenRegister\Service\Authorization\RbacGroupCollector
 * @uses \OCA\OpenRegister\Service\Rbac\AggregateVisibility
 * @uses \OCA\OpenRegister\Service\Rbac\EffectiveAuthorization
 * @uses \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 */
class OasServiceCreateOasGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/oas-create-oas-golden.json';

	private function entity(object $entity, int $id, array $fields): object {
		foreach ($fields as $name => $value) {
			$prop = new \ReflectionProperty($entity, $name);
			$prop->setValue($entity, $value);
		}

		(new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);
		return $entity;
	}//end entity()

	private function service(): OasService {
		$registers = [
			$this->entity(new Register(), 1, ['title' => 'Zaken', 'description' => 'Cases', 'version' => '2.1', 'slug' => 'zaken', 'schemas' => [10, 11, 99]]),
			$this->entity(new Register(), 2, ['title' => 'mijn klanten', 'description' => '', 'version' => '1.0', 'slug' => 'klanten', 'schemas' => [11, 12]]),
		];
		$schemas = [
			$this->entity(new Schema(), 10, ['title' => 'Zaak', 'slug' => 'zaak', 'description' => 'A case', 'properties' => [
				'title' => ['type' => 'string', 'description' => 'Title'],
				'klant' => ['$ref' => '#/components/schemas/klant'],
				'status' => ['type' => 'string', 'enum' => ['open', 'closed']],
			], 'authorization' => ['create' => ['behandelaar'], 'read' => ['public'], 'update' => ['behandelaar'], 'delete' => ['admin']]]),
			$this->entity(new Schema(), 11, ['title' => 'Klant', 'slug' => 'klant', 'properties' => [
				'naam' => ['type' => 'string'],
				'adres' => ['$ref' => '#/components/schemas/Missing'],
			]]),
			$this->entity(new Schema(), 12, ['title' => '', 'slug' => 'untitled', 'properties' => ['x' => ['type' => 'string']]]),
		];

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn($registers);
		$registerMapper->method('find')->willReturnCallback(fn ($id) => $registers[((int)$id) - 1]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findMultiple')->willReturnCallback(
			fn (array $ids) => array_values(array_filter($schemas, fn ($s) => in_array($s->getId(), $ids, true)))
		);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $path) => 'https://nc.example' . $path);
		$urlGenerator->method('getBaseUrl')->willReturn('https://nc.example');

		return new OasService($registerMapper, $schemaMapper, $urlGenerator);
	}//end service()

	public function testCreateOasMatchesGolden(): void {
		$service = $this->service();
		$actual = [];
		foreach (['all' => null, 'register 1' => '1', 'register 2' => '2'] as $name => $registerId) {
			$actual[$name] = ['oas' => $service->createOas($registerId), 'issues' => $service->getLastValidationReport()->getIssues()];
		}

		try {
			$service->createOas('1', true);
			$actual['strict'] = 'returned';
		} catch (OasValidationException $e) {
			$actual['strict'] = $e->getMessage();
		}

		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}//end testCreateOasMatchesGolden()
}//end class
