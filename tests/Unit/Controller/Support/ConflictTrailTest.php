<?php

/**
 * ConflictTrail intervening-change tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use DateTime;
use OCA\OpenRegister\Controller\Support\ConflictTrail;
use OCA\OpenRegister\Controller\Support\VersionMismatch;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * The changes since the caller read are the dated audit records after that
 * moment; anything that is not an audit record, or has no date, is left out.
 *
 * @covers \OCA\OpenRegister\Controller\Support\ConflictTrail
 */
class ConflictTrailTest extends TestCase {

	/**
	 * Only the dated audit records after the read come back, in trail order.
	 *
	 * @return void
	 */
	public function testOnlyDatedAuditRecordsAfterTheReadAreIntervening(): void {
		$before  = self::entry(created: '2026-05-01T09:00:00+00:00');
		$after   = self::entry(created: '2026-05-01T11:00:00+00:00');
		$undated = new AuditTrail();

		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->expects($this->once())
			->method('findTrail')
			->with(
				$this->callback(
					static fn (ListQuery $query): bool => $query->limit === 25
						&& $query->filters === ['object_uuid' => 'obj-1']
						&& $query->sort === ['created' => 'DESC']
				)
			)
			->willReturn([$after, new stdClass(), $undated, $before]);

		$changes = (new ConflictTrail(auditTrail: $trail, logger: $this->createMock(LoggerInterface::class)))
			->interveningChanges(mismatch: self::mismatch(expected: '2026-05-01T10:00:00+00:00'));

		$this->assertSame(expected: [$after], actual: $changes);
	}//end testOnlyDatedAuditRecordsAfterTheReadAreIntervening()

	/**
	 * An unreadable claimed version gives no changes and reads no trail.
	 *
	 * @return void
	 */
	public function testAnUnreadableVersionGivesNoChanges(): void {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->expects($this->never())->method('findTrail');

		$changes = (new ConflictTrail(auditTrail: $trail, logger: $this->createMock(LoggerInterface::class)))
			->interveningChanges(mismatch: self::mismatch(expected: 'not a date'));

		$this->assertSame(expected: [], actual: $changes);
	}//end testAnUnreadableVersionGivesNoChanges()

	/**
	 * An audit trail entry created at the given moment.
	 *
	 * @param string $created The moment.
	 *
	 * @return AuditTrail
	 */
	private static function entry(string $created): AuditTrail {
		$entry = new AuditTrail();
		$entry->setCreated(new DateTime($created));

		return $entry;
	}//end entry()

	/**
	 * A version mismatch on object obj-1 for the given claimed version.
	 *
	 * @param string $expected The version the caller held.
	 *
	 * @return VersionMismatch
	 */
	private static function mismatch(string $expected): VersionMismatch {
		$stored = new ObjectEntity();
		$stored->setUuid('obj-1');

		return new VersionMismatch(stored: $stored, expected: $expected, current: '2026-05-01T12:00:00+00:00');
	}//end mismatch()
}//end class
