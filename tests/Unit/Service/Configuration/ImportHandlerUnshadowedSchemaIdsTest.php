<?php

/**
 * Characterisation of ImportHandler::unshadowedSchemaIds().
 *
 * Pins which listed schema ids an application register keeps: ids whose
 * schema cannot be loaded, whose slug no imported schema has, or whose slug
 * the imported schema with the same id has; and which it prunes (a different
 * imported schema with the same slug, compared case-insensitively), with the
 * debug line per pruned id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class ImportHandlerUnshadowedSchemaIdsTest extends TestCase {

	/** @var array<int, string> Every recorded call, in order. */
	private array $calls = [];

	private static function schema(?int $id, ?string $slug): Schema {
		$schema = new Schema();
		$schema->hydrate(['title' => 't', 'version' => '1.0.0', 'properties' => []]);
		$schema->setSlug($slug);
		if ($id !== null) {
			$schema->setId($id);
		}

		return $schema;
	}//end schema()

	private function unshadowed(array $listed, array $imported): array {
		$stored = [
			1 => self::schema(1, 'Page'),
			2 => self::schema(2, 'menu'),
			3 => self::schema(3, 'item'),
			5 => self::schema(5, 'news'),
			6 => self::schema(6, null),
		];
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id, ...$rest) use ($stored) {
				$this->calls[] = 'schema.find ' . json_encode([$id, $rest]);
				if (isset($stored[$id]) === false) {
					throw new DoesNotExistException('gone');
				}

				return $stored[$id];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->calls[] = 'debug ' . $message . ' ' . json_encode(array_keys($context));
			}
		);
		$handler = new ImportHandler(
			catalog: new \OCA\OpenRegister\Db\SchemaCatalog(
				registers: $this->createMock(RegisterMapper::class),
				schemas: $schemaMapper,
				tables: $this->createMock(MagicMapper::class)
			),
			records: new \OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new \OCA\OpenRegister\Service\Configuration\SchemaImportSteps(),
			logger:              $logger,
			appDataPath:         '/tmp',
			objectService:       $this->createMock(ObjectService::class)
		);
		$register = new Register();
		$register->setSchemas($listed);

		return (new ReflectionMethod(ImportHandler::class, 'unshadowedSchemaIds'))
			->invoke($handler, $register, ['slug' => 'zaken', 'schemas' => $imported]);
	}//end unshadowed()

	public function testShadowedIdsArePrunedAndTheRestKept(): void {
		$imported = [
			self::schema(10, 'page'),
			self::schema(2, 'MENU'),
			self::schema(11, 'item'),
			self::schema(null, 'news'),
			self::schema(12, null),
			'not-a-schema',
		];

		$this->assertSame([2, 4, 5, 6, 7], $this->unshadowed([1, '2', 3, 4, 5, 6, 7], $imported));
		$this->assertSame(
			[
				'schema.find [1,[[],true,false]]',
				"debug [ImportHandler] Auto-Register 'zaken': pruning shadowed schema id 1 (slug 'Page') in favour of app-owned id 10 [\"file\",\"line\"]",
				'schema.find [2,[[],true,false]]',
				'schema.find [3,[[],true,false]]',
				"debug [ImportHandler] Auto-Register 'zaken': pruning shadowed schema id 3 (slug 'item') in favour of app-owned id 11 [\"file\",\"line\"]",
				'schema.find [4,[[],true,false]]',
				'schema.find [5,[[],true,false]]',
				'schema.find [6,[[],true,false]]',
				'schema.find [7,[[],true,false]]',
			],
			$this->calls
		);
	}//end testShadowedIdsArePrunedAndTheRestKept()

	public function testARegisterWithoutSchemasKeepsNone(): void {
		$this->assertSame([], $this->unshadowed([], [self::schema(10, 'page')]));
		$this->assertSame([], $this->calls);
	}//end testARegisterWithoutSchemasKeepsNone()

	public function testWithoutImportedSchemasEveryIdIsKept(): void {
		$this->assertSame([1, 2], $this->unshadowed([1, 2], []));
	}//end testWithoutImportedSchemasEveryIdIsKept()
}//end class
