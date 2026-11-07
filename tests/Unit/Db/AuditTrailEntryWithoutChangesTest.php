<?php

/**
 * An audit entry that records no change is written with an empty change list.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

final class AuditTrailEntryWithoutChangesTest extends TestCase {

	/**
	 * The migrated audit table, NOT NULL columns as on every database.
	 *
	 * @var MigratedSqliteDatabase
	 */
	private MigratedSqliteDatabase $database;

	/**
	 * The mapper under test.
	 *
	 * @var AuditTrailMapper
	 */
	private AuditTrailMapper $mapper;

	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase($this, ['openregister_audit_trails']);

		$this->mapper = new AuditTrailMapper(
			db: $this->database->idbConnection(),
			container: $this->createMock(ContainerInterface::class),
			userSession: $this->createMock(IUserSession::class),
			request: $this->createMock(IRequest::class),
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * The entries that record a refusal or a run rather than a change.
	 *
	 * @return array<string, array{0: callable(AuditTrailMapper): AuditTrail}>
	 */
	public static function entries(): array {
		return [
			'purpose refusal' => [static fn (AuditTrailMapper $mapper) => $mapper->createPurposeRefusalEntry(rule: 'purpose-missing', register: 1, schema: 2)],
			'party query refusal' => [static fn (AuditTrailMapper $mapper) => $mapper->createPartyQueryRefusalEntry(query: 'jansen', cap: 10, would: 40)],
			'shared master data refusal' => [
				static fn (AuditTrailMapper $mapper) => $mapper->createSharedMasterDataRefusalEntry(
					holderOrganisation: 'org-a',
					sourceOrganisation: 'org-b',
					resourceType: 'object'
				),
			],
			'hardening change' => [
				static fn (AuditTrailMapper $mapper) => $mapper->createHardeningChangeEntry(
					control: 'lockout',
					before: 5,
					after: 3,
					accepted: false,
					refusal: 'weaker'
				),
			],
			'export' => [static fn (AuditTrailMapper $mapper) => $mapper->createExportEntry(outcome: 'completed', summary: ['rows' => 3])],
		];
	}//end entries()

	#[DataProvider('entries')]
	public function testTheEntryIsWrittenWithAnEmptyChangeList(callable $write): void {
		$entry = $write($this->mapper);

		$rows = $this->database->connection()->fetchAllAssociative(
			'SELECT changed FROM openregister_audit_trails WHERE id = ?',
			[$entry->getId()]
		);

		$this->assertCount(1, $rows);
		$this->assertSame([], json_decode((string) $rows[0]['changed'], true));
	}//end testTheEntryIsWrittenWithAnEmptyChangeList()

	public function testASinkGapRecordIsWritten(): void {
		$unshipped = new AuditTrail();
		$unshipped->setUuid('u-1');

		(new \ReflectionMethod(AuditTrailMapper::class, 'recordSinkGap'))->invoke($this->mapper, $unshipped, 'sink down');

		$rows = $this->database->connection()->fetchAllAssociative('SELECT changed FROM openregister_audit_trails');
		$this->assertCount(1, $rows);
		$this->assertSame([], json_decode((string) $rows[0]['changed'], true));
	}//end testASinkGapRecordIsWritten()

	public function testAChangeListGivenIsKept(): void {
		$entry = new AuditTrail();
		$entry->setChanged(['title' => ['old' => 'a', 'new' => 'b']]);

		$kept = (new \ReflectionMethod(AuditTrailMapper::class, 'withChangeList'))->invoke(null, $entry);

		$this->assertSame(['title' => ['old' => 'a', 'new' => 'b']], $kept->getChanged());
	}//end testAChangeListGivenIsKept()
}//end class
