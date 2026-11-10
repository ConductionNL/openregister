<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the bulk save writes to the log.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\BulkRowChecks;
use OCA\OpenRegister\Service\Object\BulkSaveReporting;
use OCA\OpenRegister\Service\Object\BulkSaveSession;
use OCA\OpenRegister\Service\Object\BulkSaveStores;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Stringable;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObjects
 */
final class SaveObjectsLogTest extends TestCase {

	private const NAME = 'Jan Jansen';

	private const LINK = 'https://example.com/persons/jan-jansen';

	/**
	 * Every log record of one run, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array}>
	 */
	private array $records = [];

	/**
	 * The handler under test.
	 *
	 * @var SaveObjects
	 */
	private SaveObjects $handler;

	/**
	 * Build the handler with a logger that keeps what it is given.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$records = &$this->records;
		$logger = new class($records) extends AbstractLogger {
			/**
			 * @param array $records Receives the records.
			 */
			public function __construct(private array &$records) {
			}

			/**
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array             $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				$this->records[] = [(string)$level, (string)$message, $context];
			}
		};

		$this->handler = new SaveObjects(
			stores: new BulkSaveStores(objectEntityMapper: $this->createMock(MagicMapper::class), schemaMapper: $this->createMock(SchemaMapper::class), registerMapper: $this->createMock(RegisterMapper::class), saveHandler: $this->createMock(SaveObject::class)),
			session: new BulkSaveSession(userSession: $this->createMock(IUserSession::class), organisationService: $this->createMock(OrganisationService::class)),
			reporting: new BulkSaveReporting(logger: $logger),
			checks: new BulkRowChecks()
		);
	}//end setUp()

	/**
	 * The structure of a bulk object is logged by its keys.
	 *
	 * @return void
	 */
	public function testTheObjectStructureLogHoldsKeysOnly(): void {
		$this->call('logBulkObjectStructure', [['name' => self::NAME]]);

		$this->assertLoggedWithoutValues();
		self::assertSame(['name'], $this->records[0][2]['available_keys']);
	}//end testTheObjectStructureLogHoldsKeysOnly()

	/**
	 * The relations found in preparation are logged by their number.
	 *
	 * @return void
	 */
	public function testTheRelationScanLogHoldsTheCountOnly(): void {
		$this->call('logBulkRelationScan', [['uuid' => 'u-1', 'relations' => ['link' => self::LINK]], ['link' => self::LINK]]);

		$this->assertLoggedWithoutValues();
		self::assertSame(1, $this->records[0][2]['relationCount']);
	}//end testTheRelationScanLogHoldsTheCountOnly()

	/**
	 * Transforming mixed-schema objects logs no value of them.
	 *
	 * @return void
	 */
	public function testTheMixedSchemaTransformLogsNoValues(): void {
		$schema = new Schema();
		$schema->setId(5);
		$objects = [['name' => self::NAME, 'link' => self::LINK, '@self' => ['register' => 3, 'schema' => 5]]];

		$method = new ReflectionMethod(SaveObjects::class, 'transformObjectsToDatabaseFormatInPlace');
		$method->invokeArgs($this->handler, [&$objects, [5 => $schema]]);

		$this->assertLoggedWithoutValues();
	}//end testTheMixedSchemaTransformLogsNoValues()

	/**
	 * The metadata removal log names keys, not business data.
	 *
	 * @return void
	 */
	public function testTheMetadataRemovalLogHoldsKeysOnly(): void {
		$this->call('extractBusinessData', [['fullName' => self::NAME, '@self' => []], null]);

		$this->assertLoggedWithoutValues();
	}//end testTheMetadataRemovalLogHoldsKeysOnly()

	/**
	 * An object lost its uuid: the error names its keys, not its data.
	 *
	 * @return void
	 */
	public function testAReconstructionFailureNamesKeysOnly(): void {
		$this->call('reconstructSavedObjects', [[['name' => self::NAME]], []]);

		$this->assertLoggedWithoutValues();
		self::assertSame('error', $this->records[0][0]);
		self::assertSame(['name'], $this->records[0][2]['objectKeys']);
	}//end testAReconstructionFailureNamesKeysOnly()

	/**
	 * Call a private method of the handler.
	 *
	 * @param string $name The method.
	 * @param array  $args Its arguments.
	 *
	 * @return mixed What it returned.
	 */
	private function call(string $name, array $args): mixed {
		return (new ReflectionMethod(SaveObjects::class, $name))->invokeArgs($this->handler, $args);
	}//end call()

	/**
	 * Something was logged, and no record holds an object value.
	 *
	 * @return void
	 */
	private function assertLoggedWithoutValues(): void {
		$logged = (string)json_encode($this->records, JSON_UNESCAPED_SLASHES);

		self::assertNotSame([], $this->records);
		self::assertStringNotContainsString(self::NAME, $logged);
		self::assertStringNotContainsString(self::LINK, $logged);
	}//end assertLoggedWithoutValues()
}//end class
