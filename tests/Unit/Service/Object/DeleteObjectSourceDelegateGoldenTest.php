<?php

/**
 * Characterisation tests for how DeleteObject hands a delete to a writable
 * object-source provider.
 *
 * Pins allowed AND refused outcomes of the delegate, through the private
 * method on the code as it was before it was split: a writable, opted-in
 * source with a resolvable register is asked to find (for the audit) and
 * remove the row; a row that is not there is "not found"; the audit row is
 * written only for a found row and a failing audit write is logged, never
 * thrown; every way the source can be unwritable (no opt-in, a read-only
 * provider, no registry, a register that does not resolve) is refused as a
 * read-only projection before anything is removed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\AuditRecording;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\DeletionRequest;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Service\ObjectSource\WritableObjectSourceProvider;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden tests for the object-source delete delegate.
 */
class DeleteObjectSourceDelegateGoldenTest extends TestCase {

	private const REFUSAL = 'Schema "permits" is a read-only projection of object-source provider "dbal-source"; deletes are not allowed.';

	private AuditTrailMapper&MockObject $audit;

	private LoggerInterface&MockObject $logger;

	/**
	 * Every provider call, as "verb:id:registerId:schemaId".
	 *
	 * @var array<int, string>
	 */
	public array $calls = [];

	protected function setUp(): void {
		$this->calls  = [];
		$this->audit  = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A writable provider whose find and remove answer like this.
	 *
	 * @param ObjectEntity|null $found   What find() returns.
	 * @param bool              $removed What remove() returns.
	 *
	 * @return WritableObjectSourceProvider
	 */
	private function writable(?ObjectEntity $found, bool $removed): WritableObjectSourceProvider {
		$test = $this;
		return new class($test, $found, $removed) implements WritableObjectSourceProvider {
			public function __construct(private DeleteObjectSourceDelegateGoldenTest $test, private ?ObjectEntity $found, private bool $removed) {
			}

			public function getId(): string {
				return 'dbal-source';
			}

			public function isEnabled(): bool {
				return true;
			}

			public function find(SourcedTable $source, string $id): ?ObjectEntity {
				$this->test->calls[] = 'find:' . $id . ':' . $source->table->register->getId() . ':' . $source->table->schema->getId() . ':' . json_encode($source->config);
				return $this->found;
			}

			public function findAll(SourcedTable $source, array $query = []): array {
				return [];
			}

			public function count(SourcedTable $source, array $query = []): int {
				return 0;
			}

			public function insert(SourcedTable $source, array $data): ObjectEntity {
				return new ObjectEntity();
			}

			public function update(SourcedTable $source, string $id, array $data): ObjectEntity {
				return new ObjectEntity();
			}

			public function remove(SourcedTable $source, string $id): bool {
				$this->test->calls[] = 'remove:' . $id . ':' . $source->table->register->getId() . ':' . $source->table->schema->getId() . ':' . json_encode($source->config);
				return $this->removed;
			}
		};
	}//end writable()

	/**
	 * A handler over a registry holding this provider (or no registry at all).
	 *
	 * @param ObjectSourceProvider|null $provider  The provider, or null for no registry.
	 * @param RegisterMapper|null       $registers The register mapper.
	 *
	 * @return DeleteObject
	 */
	private function handler(?ObjectSourceProvider $provider, ?RegisterMapper $registers = null): DeleteObject {
		$registry = null;
		if ($provider !== null) {
			$registry = new ObjectSourceRegistry($this->createMock(LoggerInterface::class));
			$registry->addProvider($provider);
		}

		return new DeleteObject(
			objectEntityMapper: $this->createMock(MagicMapper::class),
			cacheHandler: $this->createMock(CacheHandler::class),
			userSession: $this->createMock(IUserSession::class),
			audit: new AuditRecording(trail: $this->audit, logger: $this->logger),
			settingsService: $this->createMock(SettingsService::class),
			integrityService: $this->createMock(ReferentialIntegrityService::class),
			db: $this->createMock(IDBConnection::class),
			organisationMapper: $this->createMock(OrganisationMapper::class),
			objectSourceRegistry: $registry,
			registerMapper: $registers
		);
	}//end handler()

	/**
	 * A schema served by `dbal-source` with this object-source block.
	 *
	 * @param array<string, mixed> $extra Extra keys of the block.
	 *
	 * @return Schema
	 */
	private function schema(array $extra): Schema {
		$schema = new Schema();
		$schema->setId(42);
		$schema->setSlug('permits');
		$schema->setConfiguration(['x-openregister-object-source' => ['provider' => 'dbal-source'] + $extra]);

		return $schema;
	}//end schema()

	/**
	 * The register entity the delete names.
	 *
	 * @return Register
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(11);

		return $register;
	}//end register()

	/**
	 * Run the private delegate.
	 *
	 * @param DeleteObject    $handler The handler.
	 * @param Schema          $schema  The schema.
	 * @param DeletionRequest $request The request.
	 *
	 * @return bool
	 */
	private function delegate(DeleteObject $handler, Schema $schema, DeletionRequest $request): bool {
		return (new ReflectionMethod(DeleteObject::class, 'delegateObjectSourceDelete'))->invoke($handler, $schema, $request);
	}//end delegate()

	/**
	 * Run the delegate and return the refusal message, or null when it did not refuse.
	 *
	 * @param DeleteObject    $handler The handler.
	 * @param Schema          $schema  The schema.
	 * @param DeletionRequest $request The request.
	 *
	 * @return string|null
	 */
	private function refusal(DeleteObject $handler, Schema $schema, DeletionRequest $request): ?string {
		try {
			$this->delegate($handler, $schema, $request);
		} catch (RuntimeException $e) {
			return get_class($e) . ': ' . $e->getMessage();
		}

		return null;
	}//end refusal()

	/**
	 * A writable source removes the row, pre-reading it for the audit record.
	 *
	 * @return void
	 */
	public function testAWritableSourceRemovesTheRowAndAuditsIt(): void {
		$old = new ObjectEntity();
		$this->audit->expects($this->once())->method('createAuditTrail')->with($old, null, 'delete')->willReturn(new AuditTrail());
		$handler = $this->handler($this->writable($old, true));

		$this->assertTrue($this->delegate($handler, $this->schema(['readOnly' => false, 'config' => ['table' => 't']]), new DeletionRequest(register: $this->register(), schema: 42, uuid: '2')));
		$this->assertSame(['find:2:11:42:{"table":"t"}', 'remove:2:11:42:{"table":"t"}'], $this->calls);
	}//end testAWritableSourceRemovesTheRowAndAuditsIt()

	/**
	 * Without a found row there is no audit record; a failing audit write is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAuditIsBestEffort(): void {
		$this->audit->expects($this->never())->method('createAuditTrail');
		$this->assertTrue($this->delegate($this->handler($this->writable(null, true)), $this->schema(['readOnly' => false]), new DeletionRequest(register: $this->register(), schema: 42, uuid: '2')));
		$this->assertSame(['find:2:11:42:[]', 'remove:2:11:42:[]'], $this->calls);

		$this->setUp();
		$this->audit->method('createAuditTrail')->willThrowException(new RuntimeException('audit down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[DeleteObject] audit trail for external delete on uuid 2 could not be recorded: audit down',
			$this->callback(static fn (array $context): bool => array_keys($context) === ['file', 'line'])
		);
		$this->assertTrue($this->delegate($this->handler($this->writable(new ObjectEntity(), true)), $this->schema(['readOnly' => false]), new DeletionRequest(register: $this->register(), schema: 42, uuid: '2')));
	}//end testAuditIsBestEffort()

	/**
	 * A row the source does not have is "not found", after the audit pre-read.
	 *
	 * @return void
	 */
	public function testAMissingRowIsNotFound(): void {
		$this->audit->expects($this->never())->method('createAuditTrail');
		try {
			$this->delegate($this->handler($this->writable(new ObjectEntity(), false)), $this->schema(['readOnly' => false]), new DeletionRequest(register: $this->register(), schema: 42, uuid: '9'));
			$this->fail('a missing row was reported deleted');
		} catch (DoesNotExistException $e) {
			$this->assertSame('No external row matches id 9', $e->getMessage());
		}

		$this->assertSame(['find:9:11:42:[]', 'remove:9:11:42:[]'], $this->calls);
	}//end testAMissingRowIsNotFound()

	/**
	 * Every unwritable source is refused as a read-only projection before anything is touched.
	 *
	 * @return void
	 */
	public function testUnwritableSourcesAreRefused(): void {
		$expected = 'RuntimeException: ' . self::REFUSAL;
		$request  = new DeletionRequest(register: $this->register(), schema: 42, uuid: '2');
		$readOnly = $this->createMock(ObjectSourceProvider::class);
		$readOnly->method('getId')->willReturn('dbal-source');

		$this->assertSame($expected, $this->refusal($this->handler($this->writable(null, true)), $this->schema([]), $request), 'no opt-in');
		$this->assertSame($expected, $this->refusal($this->handler($this->writable(null, true)), $this->schema(['readOnly' => 'false']), $request), 'opt-in not a bool');
		$this->assertSame($expected, $this->refusal($this->handler($readOnly), $this->schema(['readOnly' => false]), $request), 'read-only provider');
		$this->assertSame($expected, $this->refusal($this->handler(null), $this->schema(['readOnly' => false]), $request), 'no registry');
		$this->assertSame([], $this->calls);
	}//end testUnwritableSourcesAreRefused()

	/**
	 * A register given by id is resolved; one that does not resolve is refused.
	 *
	 * @return void
	 */
	public function testTheRegisterIsResolvedOrRefused(): void {
		$expected  = 'RuntimeException: ' . self::REFUSAL;
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturnCallback(
			fn (int|string $id): Register => ($id === '11') ? $this->register() : throw new DoesNotExistException('no')
		);
		$schema = $this->schema(['readOnly' => false]);

		$this->assertTrue($this->delegate($this->handler($this->writable(null, true), $registers), $schema, new DeletionRequest(register: '11', schema: 42, uuid: '2')));
		$this->assertSame(['find:2:11:42:[]', 'remove:2:11:42:[]'], $this->calls);

		$this->calls = [];
		$this->assertSame($expected, $this->refusal($this->handler($this->writable(null, true), $registers), $schema, new DeletionRequest(register: '99', schema: 42, uuid: '2')), 'unresolvable id');
		$this->assertSame($expected, $this->refusal($this->handler($this->writable(null, true), $registers), $schema, new DeletionRequest(register: null, schema: 42, uuid: '2')), 'no register');
		$this->assertSame($expected, $this->refusal($this->handler($this->writable(null, true)), $schema, new DeletionRequest(register: 11, schema: 42, uuid: '2')), 'no register mapper');
		$this->assertSame([], $this->calls);
	}//end testTheRegisterIsResolvedOrRefused()
}//end class
