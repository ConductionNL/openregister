<?php

/**
 * Characterisation of the schema-scope lookups of ImportHandler.
 *
 * Pins resolveSchemaWithinRegisterScope() (in scope, the app's own unlinked
 * row, a same-slug row elsewhere, none) and resolveSchemaByApplication() (the
 * app's own schema, another app's, an unowned or same-app row, none) with
 * every mapper and log call.
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ConfigurationImportRecords;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaLookup;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\SchemaImportOptions;
use OCA\OpenRegister\Service\Configuration\SchemaImportSteps;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class ImportHandlerSchemaScopeLookupCharacterisationTest extends TestCase {

	/** @var array<int, string> Every recorded call, in order. */
	private array $calls = [];

	/** @var ImportHandler The handler under test. */
	private ImportHandler $handler;

	/** @var array<string, mixed> Mapper answers by method; a Throwable is thrown. */
	private array $answers = [];

	protected function setUp(): void {
		parent::setUp();
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['line'], $context['file']);
					$this->calls[] = $level . ' ' . $message . ' ' . json_encode($context);
				}
			);
		}

		$schemaMapper = $this->createMock(SchemaMapper::class);
		foreach (['findBySlugInIds', 'findBySlug', 'find', 'findByApplicationAndSlug'] as $method) {
			$schemaMapper->method($method)->willReturnCallback(fn (...$args) => $this->answer('schema.' . $method, $args));
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getAllRegisterIdsWithSchema')->willReturnCallback(
			fn (...$args) => $this->answer('register.getAllRegisterIdsWithSchema', $args)
		);

		$this->handler = new ImportHandler(
			catalog: new SchemaCatalog(registers: $registerMapper, schemas: $schemaMapper, tables: $this->createMock(MagicMapper::class)),
			records: new ConfigurationImportRecords(
				configurations: $this->createMock(ConfigurationMapper::class),
				mappings: $this->createMock(MappingMapper::class),
				appConfig: $this->createMock(IAppConfig::class)
			),
			schemaSteps: new SchemaImportSteps(),
			logger: $logger,
			appDataPath: '/tmp',
			objectService: $this->createMock(ObjectService::class)
		);
	}//end setUp()

	private function answer(string $method, array $args): mixed {
		$this->calls[] = $method . ' ' . json_encode($args);
		$answer = ($this->answers[$method] ?? null);
		if ($answer instanceof \Throwable) {
			throw $answer;
		}

		return $answer;
	}//end answer()

	private static function schema(?int $id, ?string $application): Schema {
		$schema = new Schema();
		$schema->hydrate(['slug' => 'page', 'title' => 'page', 'version' => '1.0.0', 'properties' => []]);
		$schema->setApplication($application);
		if ($id !== null) {
			$schema->setId($id);
		}

		return $schema;
	}//end schema()

	private function withinScope(?string $appId): ?Schema {
		$lookup = new ImportedSchemaLookup(
			data: ['slug' => 'page'],
			registerSchemaIds: [3, 4],
			options: new SchemaImportOptions(owner: null, appId: $appId, version: null, force: false)
		);
		return (new ReflectionMethod(ImportHandler::class, 'resolveSchemaWithinRegisterScope'))->invoke($this->handler, $lookup, [3, 4]);
	}//end withinScope()

	private function byApplication(): ?Schema {
		return (new ReflectionMethod(ImportHandler::class, 'resolveSchemaByApplication'))->invoke($this->handler, ['slug' => 'page'], 'zaakapp');
	}//end byApplication()

	public function testASchemaInTheRegisterScopeIsReused(): void {
		$inScope = self::schema(3, 'zaakapp');
		$this->answers['schema.findBySlugInIds'] = $inScope;

		$this->assertSame($inScope, $this->withinScope('zaakapp'));
		$this->assertSame(['schema.findBySlugInIds ["page",[3,4]]'], $this->calls);
	}//end testASchemaInTheRegisterScopeIsReused()

	public function testTheAppsOwnUnlinkedRowIsAdopted(): void {
		$own = self::schema(9, 'zaakapp');
		$this->answers['schema.findBySlug'] = [$own];
		$this->answers['register.getAllRegisterIdsWithSchema'] = [];

		$this->assertSame($own, $this->withinScope('zaakapp'));
		$this->assertSame(
			[
				'schema.findBySlugInIds ["page",[3,4]]',
				'schema.findBySlug ["page",{"limit":50,"offset":null,"filters":[],"sort":[],"search":null},false,true]',
				'register.getAllRegisterIdsWithSchema [9]',
			],
			$this->calls
		);
	}//end testTheAppsOwnUnlinkedRowIsAdopted()

	public function testASameSlugRowElsewhereIsOnlyNoted(): void {
		$this->answers['schema.find'] = self::schema(12, 'other');

		$this->assertNull($this->withinScope(null));
		$this->assertSame(
			[
				'schema.findBySlugInIds ["page",[3,4]]',
				'schema.find ["page",[],true,false]',
				"debug [ImportHandler] Schema slug 'page' already exists elsewhere (schema id 12) but not in the target register's schema set; "
				. 'creating this register\'s OWN schema. {"slug":"page"}',
			],
			$this->calls
		);
	}//end testASameSlugRowElsewhereIsOnlyNoted()

	public function testASameSlugRowWithoutIdIsNotNoted(): void {
		$this->answers['schema.find'] = self::schema(null, 'other');

		$this->assertNull($this->withinScope(null));
		$this->assertSame(['schema.findBySlugInIds ["page",[3,4]]', 'schema.find ["page",[],true,false]'], $this->calls);
	}//end testASameSlugRowWithoutIdIsNotNoted()

	public function testNoRowOutsideTheScopeIsLogged(): void {
		$this->answers['schema.find'] = new DoesNotExistException('none');

		$this->assertNull($this->withinScope(''));
		$this->assertSame(
			[
				'schema.findBySlugInIds ["page",[3,4]]',
				'schema.find ["page",[],true,false]',
				'debug [ImportHandler] No unambiguous pre-existing schema outside the register scope {"slug":"page","exception":"none"}',
			],
			$this->calls
		);
	}//end testNoRowOutsideTheScopeIsLogged()

	public function testTheAppsOwnSchemaIsReused(): void {
		$own = self::schema(3, 'zaakapp');
		$this->answers['schema.findByApplicationAndSlug'] = $own;

		$this->assertSame($own, $this->byApplication());
		$this->assertSame(['schema.findByApplicationAndSlug ["page","zaakapp"]'], $this->calls);
	}//end testTheAppsOwnSchemaIsReused()

	public function testAnotherAppsSchemaIsWarnedAbout(): void {
		$this->answers['schema.find'] = self::schema(12, 'stackiq');

		$this->assertNull($this->byApplication());
		$this->assertSame(
			[
				'schema.findByApplicationAndSlug ["page","zaakapp"]',
				'schema.find ["page",[],true,false]',
				"warning [ImportHandler] Schema slug 'page' is already owned by app 'stackiq'; "
				. "app 'zaakapp' will create its OWN schema to avoid a cross-app collision. {\"slug\":\"page\"}",
			],
			$this->calls
		);
	}//end testAnotherAppsSchemaIsWarnedAbout()

	public function testAnUnownedOrSameAppRowIsNotWarnedAbout(): void {
		foreach ([null, '', 'zaakapp'] as $application) {
			$this->calls = [];
			$this->answers['schema.find'] = self::schema(12, $application);

			$this->assertNull($this->byApplication());
			$this->assertSame(['schema.findByApplicationAndSlug ["page","zaakapp"]', 'schema.find ["page",[],true,false]'], $this->calls);
		}
	}//end testAnUnownedOrSameAppRowIsNotWarnedAbout()

	public function testNoRowToCheckIsLogged(): void {
		$this->answers['schema.find'] = new \RuntimeException('ambiguous');

		$this->assertNull($this->byApplication());
		$this->assertSame(
			[
				'schema.findByApplicationAndSlug ["page","zaakapp"]',
				'schema.find ["page",[],true,false]',
				'debug [ImportHandler] No unambiguous pre-existing schema to check the owning app of {"slug":"page","exception":"ambiguous"}',
			],
			$this->calls
		);
	}//end testNoRowToCheckIsLogged()
}//end class
