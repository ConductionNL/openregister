<?php

/**
 * Golden tests for the register/schema pairs whose bulk-write events a chunk runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkRowRules;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\BulkWriteEvents;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Pins which "registerId/schemaId" keys bulkEventTargets returns, and which registers and schemas it loads.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\BulkRowRules
 * @uses   \OCA\OpenRegister\Service\Object\BulkWriteEvents
 */
class SaveObjectsBulkEventTargetsGoldenTest extends TestCase {

	/** @var list<string> */
	private array $loads = [];

	protected function setUp(): void {
		parent::setUp();
		$ref = new ReflectionClass(SaveObjects::class);
		foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $prop) {
			$ref->getProperty($prop)->setValue(null, []);
		}

		$this->loads = [];
	}//end setUp()

	/**
	 * A schema with an id and an explicit bulk-write events flag, or none.
	 *
	 * @param int       $id   The id.
	 * @param bool|null $fire The flag.
	 *
	 * @return Schema
	 */
	private function schema(int $id, ?bool $fire): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$config = [];
		if ($fire !== null) {
			$config[BulkWriteEvents::CONFIG_KEY] = $fire;
		}

		$schema->setConfiguration($config);
		return $schema;
	}//end schema()

	/**
	 * A register with an id and an explicit bulk-write events flag, or none.
	 *
	 * @param int       $id   The id.
	 * @param bool|null $fire The flag.
	 *
	 * @return Register
	 */
	private function register(int $id, ?bool $fire): Register {
		$register = new Register();
		$register->setId($id);
		$config = [];
		if ($fire !== null) {
			$config[BulkWriteEvents::CONFIG_KEY] = $fire;
		}

		$register->setConfiguration($config);
		return $register;
	}//end register()

	/**
	 * Run bulkEventTargets.
	 *
	 * @param array $rows      The chunk rows.
	 * @param array $context   The context.
	 * @param array $registers Loadable registers by id.
	 * @param array $schemas   Loadable schemas by id.
	 * @param bool  $withRules Whether the bulk-write events rule is wired.
	 *
	 * @return array The targets.
	 */
	private function targets(array $rows, array $context, array $registers = [], array $schemas = [], bool $withRules = true): array {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			function ($id) use ($registers) {
				$this->loads[] = 'register:' . $id;
				if (isset($registers[$id]) === false) {
					throw new RuntimeException('no register ' . $id);
				}

				return $registers[$id];
			}
		);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id) use ($schemas) {
				$this->loads[] = 'schema:' . $id;
				if (isset($schemas[$id]) === false) {
					throw new RuntimeException('no schema ' . $id);
				}

				return $schemas[$id];
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$rules = null;
		if ($withRules === true) {
			$rules = new BulkRowRules(null, null, new BulkWriteEvents($this->createMock(IEventDispatcher::class), $appConfig));
		}

		$handler = new SaveObjects(
			stores: new BulkSaveStores(objectEntityMapper: $this->createMock(MagicMapper::class), schemaMapper: $schemaMapper, registerMapper: $registerMapper, saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $this->createMock(IUserSession::class), organisationService: $this->createMock(OrganisationService::class), groupManager: null),
			reporting: new BulkSaveReporting(logger: $this->createMock(LoggerInterface::class), eventDispatcher: null, auditTrailMapper: null),
			checks: new BulkRowChecks(permissionHandler: null, validateHandler: null, rowRules: $rules)
		);

		$method = new ReflectionMethod(SaveObjects::class, 'bulkEventTargets');
		return $method->invoke($handler, $rows, $context + ['events' => false, 'schemas' => [], 'register' => null, 'schema' => null]);
	}//end targets()

	public function testNoRulesOrEventsOnEveryObjectNeedNoTargets(): void {
		$rows = [['register' => 1, 'schema' => 2]];
		$context = ['schema' => $this->schema(2, true), 'register' => $this->register(1, null)];

		$this->assertSame([], $this->targets($rows, $context, [], [], false));
		$this->assertSame([], $this->targets($rows, ['events' => true] + $context));
		$this->assertSame([], $this->loads);
	}//end testNoRulesOrEventsOnEveryObjectNeedNoTargets()

	public function testTheCallersRegisterAndSchemaWinOverTheRows(): void {
		$rows = [['register' => 8, 'schema' => 9], ['register' => 5, 'schema' => 6]];
		$context = ['schema' => $this->schema(2, true), 'register' => $this->register(1, null)];

		$this->assertSame(['1/2' => true], $this->targets($rows, $context));
		$this->assertSame([], $this->loads);
	}//end testTheCallersRegisterAndSchemaWinOverTheRows()

	public function testRowsFallBackToTheSchemaCacheAndTheirOwnRegister(): void {
		$rows = [
			['register' => 1, 'schema' => 10],
			['register' => 1, 'schema' => 11],
			['register' => 1, 'schema' => 10],
			['register' => 2, 'schema' => 12],
			['register' => 3, 'schema' => 10],
			['register' => 4, 'schema' => 99],
			['schema' => 11],
		];
		$context = ['schemas' => [10 => $this->schema(10, true), 11 => $this->schema(11, null), 12 => $this->schema(12, false)]];
		$registers = [1 => $this->register(1, null), 2 => $this->register(2, true)];

		$targets = $this->targets($rows, $context, $registers);

		$this->assertSame(['1/10' => true, '3/10' => true], $targets);
		$this->assertSame(['register:1', 'register:2', 'register:3', 'register:4'], $this->loads);
	}//end testRowsFallBackToTheSchemaCacheAndTheirOwnRegister()

	public function testTheRegisterDecidesWhenTheSchemaIsSilent(): void {
		$rows = [['register' => 2, 'schema' => 11], ['register' => 1, 'schema' => 11]];
		$context = ['schemas' => [11 => $this->schema(11, null)]];
		$registers = [1 => $this->register(1, false), 2 => $this->register(2, true)];

		$this->assertSame(['2/11' => true], $this->targets($rows, $context, $registers));
	}//end testTheRegisterDecidesWhenTheSchemaIsSilent()

	public function testTheCallerNamesTheRegisterAndSchemaById(): void {
		$rows = [['register' => 8, 'schema' => 9]];
		$context = ['register' => '1', 'schema' => 2];
		$registers = [1 => $this->register(1, null)];
		$schemas = [2 => $this->schema(2, true)];

		$this->assertSame(['1/2' => true], $this->targets($rows, $context, $registers, $schemas));
		$this->assertSame(['register:1', 'schema:2'], $this->loads);
	}//end testTheCallerNamesTheRegisterAndSchemaById()

	public function testEmptyOrUnloadableCallerIdsFallBackToTheRow(): void {
		$rows = [['register' => 1, 'schema' => 10]];
		$schemas = ['schemas' => [10 => $this->schema(10, true)]];
		$registers = [1 => $this->register(1, null)];

		foreach ([0, '0', '', null] as $empty) {
			$this->setUp();
			$this->assertSame(['1/10' => true], $this->targets($rows, $schemas + ['register' => $empty, 'schema' => $empty], $registers));
			$this->assertSame(['register:1'], $this->loads);
		}

		$this->setUp();
		$this->assertSame(['1/10' => true], $this->targets($rows, $schemas + ['register' => 77, 'schema' => 'gone'], $registers));
		$this->assertSame(['register:77', 'schema:gone', 'register:1'], $this->loads);

		$this->setUp();
		$this->assertSame(['1/10' => true], $this->targets($rows, $schemas + ['register' => $this->schema(5, true), 'schema' => $this->register(6, true)], $registers));
		$this->assertSame(['register:1'], $this->loads);
	}//end testEmptyOrUnloadableCallerIdsFallBackToTheRow()
}//end class
