<?php

/**
 * Foreign identifiers name their issuer: exact lookups, no ambiguity between senders.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ForeignIdentifier;
use OCA\OpenRegister\Db\ForeignIdentifierMapper;
use OCA\OpenRegister\Service\ForeignIdentifierConflictException;
use OCA\OpenRegister\Service\ForeignIdentifierRegistry;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The registry over an in-memory store that keeps the unique index's promise.
 */
#[CoversClass(ForeignIdentifierRegistry::class)]
#[UsesClass(ForeignIdentifier::class)]
#[UsesClass(ForeignIdentifierConflictException::class)]
#[UsesClass(ForeignIdentifierMapper::class)]
class ForeignIdentifierRegistryTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var ForeignIdentifierMapper
	 */
	private ForeignIdentifierMapper $store;

	/**
	 * The registry under test.
	 *
	 * @var ForeignIdentifierRegistry
	 */
	private ForeignIdentifierRegistry $registry;

	/**
	 * Build the registry over an empty store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store    = $this->memoryStore();
		$this->registry = new ForeignIdentifierRegistry($this->store);
	}//end setUp()

	/**
	 * A store keeping rows in memory, unique on issuer and value.
	 *
	 * @param DbException|null $insertFailure Thrown by the first insert, after storing `$sneakIn`.
	 * @param array|null       $sneakIn       [object, issuer, value] another writer stores first.
	 *
	 * @return ForeignIdentifierMapper
	 */
	private function memoryStore(?DbException $insertFailure = null, ?array $sneakIn = null): ForeignIdentifierMapper {
		$db = $this->createMock(IDBConnection::class);

		return new class($db, $insertFailure, $sneakIn) extends ForeignIdentifierMapper {
			/**
			 * The rows.
			 *
			 * @var ForeignIdentifier[]
			 */
			public array $rows = [];

			/**
			 * How many inserts were attempted.
			 *
			 * @var int
			 */
			public int $inserts = 0;

			/**
			 * Constructor.
			 *
			 * @param IDBConnection    $db            The unused connection.
			 * @param DbException|null $insertFailure The first insert's failure.
			 * @param array|null       $sneakIn       The concurrent writer's row.
			 */
			public function __construct(
				IDBConnection $db,
				private ?DbException $insertFailure,
				private ?array $sneakIn,
			) {
				parent::__construct($db);
			}

			/**
			 * Find by issuer and value.
			 *
			 * @param string $issuer The issuer.
			 * @param string $value  The value.
			 *
			 * @return ForeignIdentifier|null
			 */
			public function findByIssuerAndValue(string $issuer, string $value): ?ForeignIdentifier {
				foreach ($this->rows as $row) {
					if ($row->getIssuer() === $issuer && $row->getValue() === $value) {
						return $row;
					}
				}

				return null;
			}

			/**
			 * Every row of one object.
			 *
			 * @param string $objectUuid The object.
			 *
			 * @return ForeignIdentifier[]
			 */
			public function findForObject(string $objectUuid): array {
				return array_values(array_filter($this->rows, static fn (ForeignIdentifier $row): bool => $row->getObjectUuid() === $objectUuid));
			}

			/**
			 * Store a row.
			 *
			 * @param Entity $entity The row.
			 *
			 * @return Entity
			 */
			public function insert(Entity $entity): Entity {
				$this->inserts++;
				if ($this->insertFailure !== null) {
					$failure             = $this->insertFailure;
					$this->insertFailure = null;
					[$object, $issuer, $value] = $this->sneakIn;
					$other = new ForeignIdentifier();
					$other->setObjectUuid($object);
					$other->setIssuer($issuer);
					$other->setValue($value);
					$this->rows[] = $other;
					throw $failure;
				}

				$this->rows[] = $entity;
				return $entity;
			}
		};
	}//end memoryStore()

	/**
	 * A database failure with the given reason.
	 *
	 * @param int $reason The reason.
	 *
	 * @return DbException
	 */
	private function failure(int $reason): DbException {
		$failure = $this->getMockBuilder(DbException::class)->disableOriginalConstructor()->onlyMethods(['getReason'])->getMock();
		$failure->method('getReason')->willReturn($reason);

		return $failure;
	}//end failure()

	public function testAZgwMessageFindsItsZaakByTheSendersIdentifier(): void {
		$this->registry->record('zaak-1', 'zgw:gemeente-x', 'ZAAK-2026-0042');

		$this->assertSame('zaak-1', $this->registry->resolve('zgw:gemeente-x', 'ZAAK-2026-0042'));
		$this->assertNull($this->registry->resolve('zgw:gemeente-x', 'ZAAK-2026-0043'));
	}//end testAZgwMessageFindsItsZaakByTheSendersIdentifier()

	public function testTheSameNumberFromTwoSendersIsNotAmbiguous(): void {
		$this->registry->record('zaak-1', 'zgw:gemeente-x', '1001');
		$this->registry->record('zaak-2', 'stuf:belastingen', '1001');

		$this->assertSame('zaak-1', $this->registry->resolve('zgw:gemeente-x', '1001'));
		$this->assertSame('zaak-2', $this->registry->resolve('stuf:belastingen', '1001'));
	}//end testTheSameNumberFromTwoSendersIsNotAmbiguous()

	public function testRecordingTheSameIdentifierAgainChangesNothing(): void {
		$first  = $this->registry->record('zaak-1', 'zgw:gemeente-x', '1001');
		$second = $this->registry->record(' zaak-1 ', ' zgw:gemeente-x ', ' 1001 ');

		$this->assertSame($first, $second);
		$this->assertSame(1, $this->store->inserts);
	}//end testRecordingTheSameIdentifierAgainChangesNothing()

	public function testAnIssuersValueCannotIdentifyASecondObject(): void {
		$this->registry->record('zaak-1', 'zgw:gemeente-x', '1001');

		$this->expectException(ForeignIdentifierConflictException::class);
		$this->expectExceptionMessage('Issuer "zgw:gemeente-x" already uses value "1001" for another object.');
		$this->registry->record('zaak-2', 'zgw:gemeente-x', '1001');
	}//end testAnIssuersValueCannotIdentifyASecondObject()

	public function testAnObjectListsItsIdentifiersWithTheirIssuers(): void {
		$this->registry->record('zaak-1', 'zgw:gemeente-x', '1001');
		$this->registry->record('zaak-1', 'openregister', '2026-0042');
		$this->registry->record('zaak-2', 'zgw:gemeente-x', '1002');

		$listed = $this->registry->forObject('zaak-1');

		$this->assertSame(
			[['zgw:gemeente-x', '1001', 'zaak-1'], ['openregister', '2026-0042', 'zaak-1']],
			array_map(static fn (array $row): array => [$row['issuer'], $row['value'], $row['object']], $listed)
		);
		$this->assertNotNull($listed[0]['created']);
	}//end testAnObjectListsItsIdentifiersWithTheirIssuers()

	public function testAnEmptyIssuerIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A foreign identifier needs a non-empty issuer.');
		$this->registry->record('zaak-1', '  ', '1001');
	}//end testAnEmptyIssuerIsRefused()

	public function testAValueLongerThanTheIndexHoldsIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A foreign identifier value is at most 191 characters.');
		$this->registry->record('zaak-1', 'zgw:gemeente-x', str_repeat('9', 192));
	}//end testAValueLongerThanTheIndexHoldsIsRefused()

	public function testAnIdentifierWithoutAnObjectIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A foreign identifier needs the object it identifies.');
		$this->registry->record(' ', 'zgw:gemeente-x', '1001');
	}//end testAnIdentifierWithoutAnObjectIsRefused()

	public function testABlankLookupFindsNothing(): void {
		$this->registry->record('zaak-1', 'zgw:gemeente-x', '1001');

		$this->assertNull($this->registry->resolve('', '1001'));
		$this->assertNull($this->registry->resolve('zgw:gemeente-x', ' '));
	}//end testABlankLookupFindsNothing()

	public function testAConcurrentWriterOfTheSameIdentifierIsAnsweredWithItsRow(): void {
		$store    = $this->memoryStore($this->failure(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION), ['zaak-1', 'zgw:gemeente-x', '1001']);
		$registry = new ForeignIdentifierRegistry($store);

		$stored = $registry->record('zaak-1', 'zgw:gemeente-x', '1001');

		$this->assertSame('zaak-1', $stored->getObjectUuid());
		$this->assertCount(1, $store->rows);
	}//end testAConcurrentWriterOfTheSameIdentifierIsAnsweredWithItsRow()

	public function testAConcurrentWriterForAnotherObjectWinsAndTheLoserIsRefused(): void {
		$registry = new ForeignIdentifierRegistry(
			$this->memoryStore($this->failure(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION), ['zaak-9', 'zgw:gemeente-x', '1001'])
		);

		$this->expectException(ForeignIdentifierConflictException::class);
		$registry->record('zaak-1', 'zgw:gemeente-x', '1001');
	}//end testAConcurrentWriterForAnotherObjectWinsAndTheLoserIsRefused()

	public function testAnyOtherStoreFailureIsRethrown(): void {
		$failure  = $this->failure(DbException::REASON_LOCK_WAIT_TIMEOUT);
		$registry = new ForeignIdentifierRegistry($this->memoryStore($failure, ['zaak-9', 'other', 'x']));

		$this->expectExceptionObject($failure);
		$registry->record('zaak-1', 'zgw:gemeente-x', '1001');
	}//end testAnyOtherStoreFailureIsRethrown()
}//end class
