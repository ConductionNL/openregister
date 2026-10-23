<?php

/**
 * The definition phases of an import run in one transaction, one savepoint per item.
 *
 * Nextcloud logs `dirty table reads: <sql>` with a full exception for every read
 * of a table the request already wrote, unless a transaction is active. The
 * schema passes read back what they write on almost every step, and `occ upgrade`
 * forces the log level to debug, so one app upgrade wrote an 83 GB nextcloud.log.
 * Running the schema, register and mapping passes in one transaction stops that.
 *
 * Every item gets its own savepoint, so a rejected item undoes only its own
 * writes and the import carries on exactly as before. These tests drive the real
 * importFromJson() against a connection double that keeps savepoint semantics: a
 * stack of pending writes, where commit folds a level into its parent and
 * rollBack throws it away.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use Error;
use Exception;
use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Transaction and savepoint behaviour of ImportHandler::importFromJson().
 */
class ImportHandlerTransactionTest extends TestCase {

	/**
	 * Pending writes per open transaction level; index 0 is the outermost.
	 *
	 * @var array<int, list<string>>
	 */
	private array $levels = [];

	/**
	 * Writes that reached the database for good.
	 *
	 * @var list<string>
	 */
	private array $committed = [];

	/**
	 * Transaction depth seen at each write, keyed by the write.
	 *
	 * @var array<string, int>
	 */
	private array $depthAtWrite = [];

	/**
	 * Counts of the connection calls.
	 *
	 * @var array{begin: int, commit: int, rollBack: int}
	 */
	private array $calls = ['begin' => 0, 'commit' => 0, 'rollBack' => 0];

	/**
	 * Slug of the schema that fails after writing, or null for none.
	 *
	 * @var string|null
	 */
	private ?string $failingSlug = null;

	/**
	 * Throw an \Error instead of an Exception for the failing schema.
	 *
	 * @var bool
	 */
	private bool $failWithError = false;

	private int $nextId = 100;

	private ImportHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$appConfig = $this->createMock(IAppConfig::class);

		$appConfig->method('getValueString')->willReturn('');
		$schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$registerMapper->method('getSlugToIdMap')->willReturn([]);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);
		$registerMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$schemaMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$schemaMapper->method('findBySlugInIds')->willReturn(null);
		$schemaMapper->method('findByApplicationAndSlug')->willReturn(null);

		// A schema write lands in whatever transaction level is open. The
		// failing schema writes FIRST and then throws, the shape of a schema
		// whose row was inserted before a later step (a listener, a
		// validation) refused it. Only a savepoint can undo that row.
		$schemaMapper->method('createFromArray')
			->willReturnCallback(function (array $data): Schema {
				$this->write('schema:' . $data['slug']);
				if ($data['slug'] === $this->failingSlug) {
					if ($this->failWithError === true) {
						throw new Error('schema refused by a listener');
					}

					throw new Exception('schema refused by a listener');
				}

				return $this->makeSchema(slug: $data['slug']);
			});
		$schemaMapper->method('updateFromArray')
			->willReturnCallback(function (int $id, array $data): Schema {
				$this->write('schema:' . $data['slug']);
				return $this->makeSchema(slug: $data['slug'], id: $id);
			});
		$schemaMapper->method('update')->willReturnArgument(0);

		$registerMapper->method('createFromArray')
			->willReturnCallback(function (array $data): Register {
				$this->write('register:' . $data['slug']);
				$register = new Register();
				$register->hydrate(['slug' => $data['slug'], 'version' => '1.0.0']);
				$register->setId(1);
				return $register;
			});
		$registerMapper->method('update')->willReturnArgument(0);

		$this->handler = new ImportHandler(
			schemaMapper:        $schemaMapper,
			registerMapper:      $registerMapper,
			objectEntityMapper:  $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper:       $mappingMapper,
			client:              $this->createMock(Client::class),
			appConfig:           $appConfig,
			logger:              $this->createMock(LoggerInterface::class),
			appDataPath:         '/tmp',
			uploadHandler:       $this->createMock(UploadHandler::class),
			objectService:       $this->createMock(ObjectService::class),
			db:                  $this->connection()
		);
	}//end setUp()

	/**
	 * A connection double with savepoint semantics.
	 *
	 * @return IDBConnection
	 */
	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('inTransaction')->willReturnCallback(fn (): bool => $this->levels !== []);
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->calls['begin']++;
			$this->levels[] = [];
		});
		$db->method('commit')->willReturnCallback(function (): void {
			$this->calls['commit']++;
			$this->assertNotSame([], $this->levels, 'commit without an open transaction');
			$level = array_pop($this->levels);
			if ($this->levels === []) {
				$this->committed = array_merge($this->committed, $level);
				return;
			}

			$top = (count($this->levels) - 1);
			$this->levels[$top] = array_merge($this->levels[$top], $level);
		});
		$db->method('rollBack')->willReturnCallback(function (): void {
			$this->calls['rollBack']++;
			$this->assertNotSame([], $this->levels, 'rollBack without an open transaction');
			array_pop($this->levels);
		});

		return $db;
	}//end connection()

	/**
	 * Record one write in the innermost open level, or commit it at once.
	 *
	 * @param string $row The write.
	 *
	 * @return void
	 */
	private function write(string $row): void {
		$this->depthAtWrite[$row] = count($this->levels);
		if ($this->levels === []) {
			$this->committed[] = $row;
			return;
		}

		$this->levels[(count($this->levels) - 1)][] = $row;
	}//end write()

	/**
	 * Build a hydrated Schema entity.
	 *
	 * @param string   $slug The schema slug.
	 * @param int|null $id   An explicit id, or null to allocate one.
	 *
	 * @return Schema
	 */
	private function makeSchema(string $slug, ?int $id = null): Schema {
		if ($id === null) {
			$id = $this->nextId;
			$this->nextId++;
		}

		$schema = new Schema();
		$schema->hydrate(['slug' => $slug, 'title' => $slug, 'version' => '1.0.0', 'properties' => []]);
		$schema->setId($id);
		return $schema;
	}//end makeSchema()

	/**
	 * Three schemas and one register declaring them.
	 *
	 * @return array
	 */
	private function configuration(): array {
		$schemas = [];
		foreach (['alpha', 'beta', 'gamma'] as $slug) {
			$schemas[$slug] = [
				'slug' => $slug,
				'title' => $slug,
				'version' => '1.0.0',
				'properties' => ['name' => ['type' => 'string']],
			];
		}

		return [
			'appId' => 'txnapp',
			'version' => '1.0.0',
			'components' => [
				'schemas' => $schemas,
				'registers' => [
					'catalog' => ['slug' => 'catalog', 'version' => '1.0.0', 'schemas' => array_keys($schemas)],
				],
			],
		];
	}//end configuration()

	/**
	 * Run the import under test.
	 *
	 * @return array The import result.
	 */
	private function import(): array {
		return $this->handler->importFromJson(
			data: $this->configuration(),
			configuration: new Configuration(),
			appId: 'txnapp',
			version: '1.0.0'
		);
	}//end import()

	/**
	 * Every schema and register write happens inside the import's transaction,
	 * one savepoint deep, and the transaction is committed once.
	 *
	 * @return void
	 */
	public function testDefinitionPhasesRunInsideOneTransaction(): void {
		$result = $this->import();

		$this->assertCount(3, $result['schemas']);
		$this->assertSame([], $this->levels, 'the import must leave no transaction open');
		foreach (['schema:alpha', 'schema:beta', 'schema:gamma', 'register:catalog'] as $row) {
			$this->assertContains($row, $this->committed, $row . ' must be committed');
			$this->assertSame(
				2,
				$this->depthAtWrite[$row],
				$row . ' must be written inside the import transaction AND its own savepoint'
			);
		}

		$this->assertSame(
			$this->calls['begin'],
			($this->calls['commit'] + $this->calls['rollBack']),
			'every begin must be closed'
		);
		$this->assertSame(0, $this->calls['rollBack'], 'nothing failed, so nothing is rolled back');
	}//end testDefinitionPhasesRunInsideOneTransaction()

	/**
	 * A schema that wrote and then failed is rolled back to its own savepoint:
	 * its write is gone, its siblings and the register are committed, and the
	 * result reports it exactly as before.
	 *
	 * @return void
	 */
	public function testARejectedSchemaRollsBackOnlyItself(): void {
		$this->failingSlug = 'beta';

		$result = $this->import();

		$this->assertNotContains('schema:beta', $this->committed, 'the rejected schema must leave no row behind');
		$this->assertContains('schema:alpha', $this->committed, 'a schema before the failure must be committed');
		$this->assertContains('schema:gamma', $this->committed, 'a schema after the failure must be committed');
		$this->assertContains('register:catalog', $this->committed, 'the register must still be committed');
		$this->assertSame(1, $this->calls['rollBack'], 'exactly one savepoint is rolled back');
		$this->assertSame([], $this->levels, 'the import must leave no transaction open');

		// Reporting is unchanged: the rejected schema is counted and named.
		$this->assertSame(1, $result['skipped']['schemas']);
		$this->assertSame('beta', $result['failed']['schemas'][0]['slug']);
		$this->assertCount(2, $result['schemas']);
	}//end testARejectedSchemaRollsBackOnlyItself()

	/**
	 * A caller that already holds a transaction keeps it: the import nests its
	 * savepoints inside it and neither commits nor rolls back the caller's level.
	 *
	 * @return void
	 */
	public function testACallerTransactionIsNotOpenedOrClosedTwice(): void {
		// The caller's own transaction.
		$this->levels[] = [];
		$this->failingSlug = 'beta';

		$this->import();

		$this->assertCount(1, $this->levels, "the caller's transaction must still be open");
		$this->assertSame([], $this->committed, "nothing may reach the database before the caller commits");
		$this->assertContains('schema:alpha', $this->levels[0], "the schemas sit in the caller's transaction");
		$this->assertNotContains('schema:beta', $this->levels[0], 'the rejected schema is still rolled back');
		$this->assertSame(
			$this->calls['begin'],
			($this->calls['commit'] + $this->calls['rollBack']),
			'the import closes exactly the savepoints it opened'
		);
		$this->assertSame(1, $this->depthAtWrite['schema:alpha'] - 1, 'one savepoint inside the caller transaction');
	}//end testACallerTransactionIsNotOpenedOrClosedTwice()

	/**
	 * An \Error is not caught by the schema loop, so it escapes the import, as
	 * before. The schema before it stays committed, as it did when every write
	 * committed on its own, and no transaction is left open.
	 *
	 * @return void
	 */
	public function testAnEscapingErrorKeepsWhatCompletedAndClosesTheTransaction(): void {
		$this->failingSlug = 'beta';
		$this->failWithError = true;

		try {
			$this->import();
			$this->fail('the \Error must still escape the import');
		} catch (Error $e) {
			$this->assertSame('schema refused by a listener', $e->getMessage());
		}

		$this->assertSame([], $this->levels, 'no transaction may be left open after the escape');
		$this->assertContains('schema:alpha', $this->committed, 'the schema before the escape must be kept');
		$this->assertNotContains('schema:beta', $this->committed, 'the failing schema must be rolled back');
	}//end testAnEscapingErrorKeepsWhatCompletedAndClosesTheTransaction()
}//end class
