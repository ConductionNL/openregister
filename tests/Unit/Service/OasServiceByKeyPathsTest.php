<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\OasSources;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The generated OpenAPI document describes the by-key paths of a schema with an identity.
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */
class OasServiceByKeyPathsTest extends TestCase {

	private const IDENTITY = [
		'uniqueConstraints' => [
			'zaaksleutel' => ['properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse', 'identity' => true],
		],
	];

	/**
	 * The paths of a generated document with one schema.
	 *
	 * @param array|null $configuration The schema's configuration.
	 *
	 * @return array<string, mixed> The paths.
	 */
	private function paths(?array $configuration): array {
		$register = new Register();
		$register->setTitle('Zaken');
		$register->setVersion('1.0');
		$schema = new Schema();
		$schema->setTitle('Zaak');
		$schema->setProperties(['zaaknummer' => ['type' => 'string'], 'gemeentecode' => ['type' => 'integer']]);
		$schema->setConfiguration($configuration);
		foreach ([[$register, 1], [$schema, 2]] as [$entity, $id]) {
			$prop = (new \ReflectionClass($entity))->getProperty('id');
			$prop->setAccessible(true);
			$prop->setValue($entity, $id);
		}

		$prop = (new \ReflectionClass($register))->getProperty('schemas');
		$prop->setAccessible(true);
		$prop->setValue($register, [2]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findMultiple')->willReturn([$schema]);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturn('http://localhost/api');

		return (new OasService(new OasSources($registerMapper, $schemaMapper, $urlGenerator)))->createOas('1')['paths'];
	}//end paths()

	/**
	 * The by-key path item, or null.
	 *
	 * @param array<string, mixed> $paths The paths.
	 *
	 * @return array<string, mixed>|null The path item.
	 */
	private function byKey(array $paths): ?array {
		foreach ($paths as $name => $item) {
			if (str_ends_with((string) $name, '/by-key') === true) {
				return $item;
			}
		}

		return null;
	}//end byKey()

	public function testIdentityAddsByKeyOperations(): void {
		$item = $this->byKey($this->paths(self::IDENTITY));

		$this->assertNotNull($item);
		$this->assertSame(['get', 'put', 'patch', 'delete'], array_keys($item));
		foreach ($item as $operation) {
			$this->assertStringEndsWith('ByKey', $operation['operationId']);
			$this->assertArrayHasKey('400', $operation['responses']);
			$this->assertArrayHasKey('404', $operation['responses']);
			$this->assertArrayHasKey('409', $operation['responses']);
			foreach ($operation['parameters'] as $parameter) {
				$this->assertNotSame('path', $parameter['in'] ?? null);
			}

			$this->assertSame('gemeentecode', $operation['parameters'][0]['name']);
			$this->assertSame('query', $operation['parameters'][0]['in']);
			$this->assertTrue($operation['parameters'][0]['required']);
			$this->assertSame('integer', $operation['parameters'][0]['schema']['type']);
			$this->assertSame('zaaknummer', $operation['parameters'][1]['name']);
			$this->assertSame('string', $operation['parameters'][1]['schema']['type']);
		}
	}//end testIdentityAddsByKeyOperations()

	public function testOperationIdsAreDistinctFromTheIdOperations(): void {
		$paths = $this->paths(self::IDENTITY);
		$ids   = [];
		foreach ($paths as $item) {
			foreach ($item as $operation) {
				if (is_array($operation) === true && isset($operation['operationId']) === true) {
					$ids[] = $operation['operationId'];
				}
			}
		}

		$this->assertSame(count($ids), count(array_unique($ids)));
	}//end testOperationIdsAreDistinctFromTheIdOperations()

	public function testNoIdentityNoByKeyPath(): void {
		$this->assertNull($this->byKey($this->paths(null)));
		$refuseOnly = ['uniqueConstraints' => ['k' => ['properties' => ['zaaknummer'], 'action' => 'refuse']]];
		$this->assertNull($this->byKey($this->paths($refuseOnly)));
	}//end testNoIdentityNoByKeyPath()
}//end class
