<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins how RelationCascadeHandler resolves schema and register references (id,
 * UUID, slug, path, URL, query string, failures) and which lookups it runs,
 * against fixtures/relation-cascade-reference-golden.json. UPDATE_GOLDEN=1 rewrites it.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Register
 */
class RelationCascadeReferenceResolutionGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/relation-cascade-reference-golden.json';

	private const UUID = '21aab6e0-2177-4920-beb0-391492fed04b';

	private const REFERENCES = [
		'', '5', '404', '7?x=1', self::UUID, 'person', 'PERSON?v=2', '#/components/schemas/Person',
		'https://h.example/api/registers/Demo', 'https://h.example/api/registers/5', 'demo', 'nothing', 'boom',
	];

	public function testReferencesMatchGolden(): void {
		$actual = [];
		foreach (self::REFERENCES as $reference) {
			$calls = [];
			$handler = $this->handler(calls: $calls, failing: false);
			$actual[$reference] = [
				'schema' => $handler->resolveSchemaReference($reference),
				'schemaSystem' => $handler->resolveSchemaReference($reference, ['_rbac' => false]),
				'register' => $handler->resolveRegisterReference($reference),
				'calls' => $calls,
			];
		}

		$calls = [];
		$handler = $this->handler(calls: $calls, failing: true);
		$actual['(findAll fails)'] = [
			'schema' => $handler->resolveSchemaReference('person'),
			'register' => $handler->resolveRegisterReference('demo'),
			'calls' => $calls,
		];

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), json_decode((string)json_encode($actual), true));
	}//end testReferencesMatchGolden()

	private function handler(array &$calls, bool $failing): RelationCascadeHandler {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setSlug('Person');
		$register = new Register();
		$register->setId(9);
		$register->setSlug('demo');

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(function (...$args) use (&$calls, $schema) {
			$calls[] = ['schemas.find', $args];
			if ((string)($args['id'] ?? $args[0] ?? '') === '5') {
				return $schema;
			}

			throw new DoesNotExistException('no');
		});
		$schemas->method('findAll')->willReturnCallback(function (...$args) use (&$calls, $schema, $failing) {
			$calls[] = ['schemas.findAll', $args];
			if ($failing === true) {
				throw new RuntimeException('db');
			}

			return [$schema];
		});

		$registers = $this->createMock(RegisterMapperInterface::class);
		$registers->method('find')->willReturnCallback(function (...$args) use (&$calls, $register) {
			$calls[] = ['registers.find', $args];
			if ((string)($args['id'] ?? $args[0] ?? '') === '5') {
				return $register;
			}

			throw new DoesNotExistException('no');
		});
		$registers->method('findAll')->willReturnCallback(function (...$args) use (&$calls, $register, $failing) {
			$calls[] = ['registers.findAll', $args];
			if ($failing === true) {
				throw new RuntimeException('db');
			}

			return [$register];
		});

		return new RelationCascadeHandler(catalog: new \OCA\OpenRegister\Db\SchemaCatalog(registers: $registers, schemas: $schemas, tables: $this->createMock(MagicMapperInterface::class)), logger: new NullLogger());
	}//end handler()
}//end class
