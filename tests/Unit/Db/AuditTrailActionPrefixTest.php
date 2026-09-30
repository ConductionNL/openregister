<?php

/**
 * An app that writes its own audit actions can count and list them by prefix.
 *
 * portaliq writes its proof records into the audit trail as `portaliq.<verb>`
 * rows (DECISIONS row 5). `getActionCounts()` answers only the four object
 * actions, and the admin list filters on one exact action, so an app had to load
 * every row of a verb to count it. These tests run the mapper's SQL against the
 * table the app's own migrations create.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Db\AuditTrailMapper
 */
class AuditTrailActionPrefixTest extends TestCase {

	private MigratedSqliteDatabase $database;

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

		$actions = [
			'portaliq.login',
			'portaliq.login',
			'portaliq.logout',
			'portaliq.download',
			'create',
			'update',
			// An underscore is a LIKE wildcard: this must not count as `portaliq.`.
			'portal_q.login',
			'portaliqx.login',
		];
		foreach ($actions as $index => $action) {
			$id = $index + 1;
			$this->database->insert(
				'openregister_audit_trails',
				[
					'id' => $id,
					'uuid' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $id),
					'action' => $action,
					'created' => sprintf('2026-09-30 10:%02d:00', $id),
					'changed' => '{}',
				]
			);
		}
	}//end setUp()

	/**
	 * Counts per action, for the actions that start with the prefix only.
	 */
	public function testCountByActionPrefixCountsEachActionOfThePrefix(): void {
		$counts = $this->mapper->countByActionPrefix(prefix: 'portaliq.');
		ksort($counts);

		$this->assertSame(
			[
				'portaliq.download' => 1,
				'portaliq.login' => 2,
				'portaliq.logout' => 1,
			],
			$counts
		);
	}//end testCountByActionPrefixCountsEachActionOfThePrefix()

	/**
	 * A prefix with no rows answers an empty map, not an error.
	 */
	public function testAPrefixWithoutRowsCountsNothing(): void {
		$this->assertSame([], $this->mapper->countByActionPrefix(prefix: 'hermiq.'));
	}//end testAPrefixWithoutRowsCountsNothing()

	/**
	 * The list filter `action=portaliq.*` answers every row of the prefix.
	 */
	public function testFindAllFiltersOnAnActionPrefix(): void {
		$rows = $this->mapper->findAll(filters: ['action' => 'portaliq.*'], sort: ['id' => 'ASC']);

		$this->assertSame([1, 2, 3, 4], array_map(fn (AuditTrail $row): int => (int) $row->getId(), $rows));
	}//end testFindAllFiltersOnAnActionPrefix()

	/**
	 * An exact action still filters exactly.
	 */
	public function testAnExactActionStillFiltersExactly(): void {
		$rows = $this->mapper->findAll(filters: ['action' => 'portaliq.login'], sort: ['id' => 'ASC']);

		$this->assertSame([1, 2], array_map(fn (AuditTrail $row): int => (int) $row->getId(), $rows));
	}//end testAnExactActionStillFiltersExactly()
}//end class
