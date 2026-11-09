<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCA\OpenRegister\Service\LinkedEntityIntegrations;
use OCA\OpenRegister\Service\LinkedEntityMappers;
use OCA\OpenRegister\Service\LinkedEntityService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for LinkedEntityService::reverseLookup().
 *
 * Records the results, the magic-table queries, the deep-link lookups and the
 * warnings, per case, in fixtures/linked-entity-reverse-lookup-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\LinkedEntityService
 * @covers \OCA\OpenRegister\Service\LinkedEntityLookup
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class LinkedEntityServiceReverseLookupGoldenTest extends TestCase {

	public static function cases(): array {
		return [
			'mail hit' => [['type' => 'mail', 'id' => 'm-1']],
			'mail other id' => [['type' => 'mail', 'id' => 'm-9']],
			'deck' => [['type' => 'deck', 'id' => 'd-1']],
			'magic table throws' => [['type' => 'mail', 'id' => 'm-1', 'throwSchema' => 11]],
			'mappers throw' => [['type' => 'mail', 'id' => 'm-1', 'throwMappers' => true]],
			'circuit breaker' => [['type' => 'mail', 'id' => 'm-1', 'many' => 52]],
		];
	}//end cases()

	/**
	 * An object answering the given getters.
	 *
	 * @param array<string, mixed> $values Getter name => return value.
	 *
	 * @return object
	 */
	private static function entity(array $values): object {
		return new class($values) {
			public function __construct(private array $values) {
			}

			public function __call(string $name, array $args): mixed {
				return $this->values[$name] ?? null;
			}
		};
	}

	/**
	 * A schema with an id, title, linked types and linked mail and deck ids.
	 *
	 * @return Schema
	 */
	private static function schema(int $id, string $title, array $linkedTypes, ?array $mail, ?array $deck): Schema {
		$schema = new Schema();
		$schema->setTitle($title);
		$schema->setUuid('s-' . $id);
		$schema->setIcon($id === 11 ? 'user' : null);
		$schema->setConfiguration(['linkedTypes' => $linkedTypes]);
		$schema->setMail($mail);
		$schema->setDeck($deck);
		$prop = (new \ReflectionClass($schema))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);
		return $schema;
	}

	/**
	 * An object with a uuid, name and register.
	 *
	 * @return ObjectEntity
	 */
	private static function object(string $uuid, ?string $name, ?string $register): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName($name);
		$object->setRegister($register);
		return $object;
	}

	#[DataProvider('cases')]
	public function testReverseLookupMatchesGolden(array $case): void {
		$log = [];
		$schemas = [
			self::schema(11, 'Persons', ['mail', 'deck'], ['m-1'], null),
			self::schema(12, 'Cases', ['mail'], null, ['d-1']),
			self::schema(13, 'Plain', [], null, null),
		];
		for ($i = 0; $i < ($case['many'] ?? 0); $i++) {
			$schemas[] = self::schema(100 + $i, 'T' . $i, ['mail'], null, null);
		}

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaCalls = 0;
		$schemaMapper->method('findAll')->willReturnCallback(
			function () use ($schemas, $case, &$schemaCalls) {
				$schemaCalls++;
				if (($case['throwMappers'] ?? false) === true && $schemaCalls === 2) {
					throw new Exception('schema table gone');
				}

				return $schemas;
			}
		);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$organisationMapper = $this->createMock(OrganisationMapper::class);
		if (($case['throwMappers'] ?? false) === true) {
			$registerMapper->method('findAll')->willThrowException(new Exception('register table gone'));
			$organisationMapper->method('findAll')->willThrowException(new Exception('org table gone'));
		} else {
			$registerMapper->method('findAll')->willReturn(
				[
					self::entity(['getUuid' => 'r-1', 'getTitle' => 'Reg one', 'getMail' => ['m-1', 'm-2'], 'getDeck' => []]),
					self::entity(['getUuid' => 'r-2', 'getTitle' => 'Reg two', 'getMail' => null]),
				]
			);
			$organisationMapper->method('findAll')->willReturn(
				[self::entity(['getUuid' => 'o-1', 'getName' => 'Org', 'getTitle' => 'not used', 'getMail' => ['m-1'], 'getDeck' => ['d-1']])]
			);
		}

		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findByLinkedEntity')->willReturnCallback(
			function ($schema, string $column, string $entityId) use (&$log, $case) {
				$log[] = ['magic', $schema->getId(), $column, $entityId];
				if ($schema->getId() === ($case['throwSchema'] ?? -1)) {
					throw new Exception('table missing');
				}

				if ($schema->getId() >= 100 || $entityId === 'm-9') {
					return [];
				}

				return [self::object('obj-' . $schema->getId(), 'Object of ' . $schema->getId(), '3'), self::object('obj-b', null, null)];
			}
		);
		$deepLinks = $this->createMock(DeepLinkRegistryService::class);
		$deepLinks->method('resolveUrl')->willReturnCallback(
			function (int $registerId, int $schemaId, array $objectData) use (&$log) {
				$log[] = ['deepLink', $registerId, $schemaId, $objectData];
				return $registerId === 3 ? '/apps/leaf/' . $objectData['uuid'] : null;
			}
		);
		$integrations = $this->createMock(IntegrationRegistry::class);
		$integrations->method('isValidIntegrationId')->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$log): void {
				$log[] = ['warning', $message, $context];
			}
		);

		$service = new LinkedEntityService(new LinkedEntityMappers($magic, $schemaMapper, $registerMapper, $organisationMapper), new LinkedEntityIntegrations($integrations, $this->createMock(PermissionHandler::class), $deepLinks), $logger);
		$actual = ['results' => $service->reverseLookup(type: $case['type'], entityId: $case['id']), 'log' => $log];
		$actual = json_decode((string)json_encode($actual, JSON_UNESCAPED_SLASHES), true);

		$file = __DIR__ . '/fixtures/linked-entity-reverse-lookup-golden.json';
		$name = $this->dataName();
		$golden = is_file($file) === true ? json_decode((string)file_get_contents($file), true) : [];
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents($file, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame($golden[$name], $actual);
	}//end testReverseLookupMatchesGolden()
}//end class
