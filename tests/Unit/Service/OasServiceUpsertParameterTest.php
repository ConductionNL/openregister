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
 * The generated OpenAPI document names `_upsertOn` with the schema's refuse constraints.
 *
 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
 */
class OasServiceUpsertParameterTest extends TestCase {

	/**
	 * The POST operation of the one schema in a generated document.
	 *
	 * @param array|null $configuration The schema's configuration.
	 *
	 * @return array<string, mixed> The operation.
	 */
	private function postOperation(?array $configuration): array {
		$register = new Register();
		$register->setTitle('Zaken');
		$register->setVersion('1.0');
		$schema = new Schema();
		$schema->setTitle('Zaak');
		$schema->setProperties(['zaaknummer' => ['type' => 'string'], 'gemeentecode' => ['type' => 'string']]);
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

		$oas = (new OasService(new OasSources($registerMapper, $schemaMapper, $urlGenerator)))->createOas('1');

		foreach ($oas['paths'] as $path) {
			if (($path['post']['operationId'] ?? null) === 'createZaak') {
				return $path['post'];
			}
		}

		$this->fail('no createZaak operation in the document');
	}//end postOperation()

	/**
	 * The `_upsertOn` parameter, or null.
	 *
	 * @param array<string, mixed> $operation The operation.
	 *
	 * @return array<string, mixed>|null The parameter.
	 */
	private function upsertParameter(array $operation): ?array {
		foreach ($operation['parameters'] ?? [] as $parameter) {
			if (($parameter['name'] ?? null) === '_upsertOn') {
				return $parameter;
			}
		}

		return null;
	}//end upsertParameter()

	public function testARefuseConstraintIsOfferedAsTheUpsertKey(): void {
		$operation = $this->postOperation(
			[
				'uniqueConstraints' => [
					'zaaksleutel' => ['properties' => ['gemeentecode', 'zaaknummer'], 'action' => 'refuse'],
					'titel' => ['properties' => ['zaaknummer'], 'action' => 'report'],
				],
			]
		);

		$parameter = $this->upsertParameter($operation);
		$this->assertNotNull($parameter);
		$this->assertSame('query', $parameter['in']);
		$this->assertSame(['zaaksleutel'], $parameter['schema']['enum']);
		foreach (['200', '201', '400', '401', '409', '503'] as $status) {
			$this->assertArrayHasKey($status, $operation['responses'], 'status '.$status);
		}
	}//end testARefuseConstraintIsOfferedAsTheUpsertKey()

	public function testTheLegacyUniqueKeyIsNamedByItsProperties(): void {
		$parameter = $this->upsertParameter($this->postOperation(['unique' => ['gemeentecode', 'zaaknummer']]));

		$this->assertSame(['gemeentecode+zaaknummer'], $parameter['schema']['enum'] ?? null);
	}//end testTheLegacyUniqueKeyIsNamedByItsProperties()

	public function testASchemaWithoutAKeyHasNoUpsertParameter(): void {
		$operation = $this->postOperation(null);

		$this->assertNull($this->upsertParameter($operation));
		$this->assertArrayNotHasKey('409', $operation['responses']);
	}//end testASchemaWithoutAKeyHasNoUpsertParameter()

	public function testTheUpsertParameterAndResponsesAreDocumentedInFull(): void {
		$operation = $this->postOperation(['unique' => ['zaaknummer']]);
		$error = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]];

		$this->assertSame(
			[
				'name' => '_upsertOn',
				'in' => 'query',
				'required' => false,
				'description' => 'Create or update by a key this schema declares unique: the record holding the key is updated (200), '
					.'or created when none does (201). Needs a signed-in caller; cannot be combined with _failIfExists.',
				'schema' => ['type' => 'string', 'enum' => ['zaaknummer']],
			],
			$this->upsertParameter($operation)
		);
		$this->assertSame([201, 400, 200, 401, 409, 503, 403], array_keys($operation['responses']));
		$this->assertSame(
			['description' => 'Upsert: the record holding the key was updated.', 'content' => $operation['responses'][201]['content']],
			$operation['responses'][200]
		);
		$this->assertSame(['description' => 'Upsert: the caller is not signed in.', 'content' => $error], $operation['responses'][401]);
		$this->assertSame(
			[
				'description' => 'Upsert: more than one record holds the key (the matches are listed), or a record you cannot change holds it.',
				'content' => $error,
			],
			$operation['responses'][409]
		);
		$this->assertSame(
			[
				'description' => 'Upsert: the key could not be looked up or locked; nothing was written. Retry after the Retry-After seconds.',
				'content' => $error,
			],
			$operation['responses'][503]
		);
	}//end testTheUpsertParameterAndResponsesAreDocumentedInFull()
}//end class
