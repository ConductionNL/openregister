<?php

/**
 * Characterisation tests for how a nominated record's archiefactiedatum
 * follows its source date.
 *
 * Pins `ActionDateFollower::follow()` for every branch: a transferred or
 * destroyed record (each alias) left alone without a calculation; a missing,
 * empty or non-string retention period left alone; an unchanged date; a date
 * that can no longer be calculated (cleared, audited and logged); and a new
 * date (stored, other retention keys kept, logged with old and new date).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Archival\ActionDateFollower;
use OCA\OpenRegister\Service\Archival\ArchiveActionDateCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden tests for follow().
 */
class ActionDateFollowerGoldenTest extends TestCase {

	private ArchiveActionDateCalculator&MockObject $calculator;

	private AuditTrailMapperInterface&MockObject $audit;

	/**
	 * Info lines logged, in order.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	private ActionDateFollower $follower;

	protected function setUp(): void {
		$this->logged     = [];
		$this->calculator = $this->createMock(ArchiveActionDateCalculator::class);
		$this->audit      = $this->createMock(AuditTrailMapperInterface::class);
		$logger           = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message): void {
				$this->logged[] = $message;
			}
		);
		$this->follower = new ActionDateFollower($this->calculator, $this->audit, $logger);
	}//end setUp()

	/**
	 * A record with this retention block.
	 *
	 * @param array<string, mixed>|null $retention The block.
	 *
	 * @return ObjectEntity
	 */
	private function record(?array $retention): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRetention($retention);

		return $object;
	}//end record()

	/**
	 * Frozen records and records without a usable period are never recalculated.
	 *
	 * @return void
	 */
	public function testFrozenOrPeriodlessRecordsAreLeftAlone(): void {
		$this->calculator->expects($this->never())->method('calculate');
		$this->audit->expects($this->never())->method('createAuditTrailEntry');

		$blocks = [
			['archiefstatus' => 'transferred', 'bewaartermijn' => 'P5Y'],
			['archiefstatus' => 'overgebracht', 'bewaartermijn' => 'P5Y'],
			['archiefstatus' => 'destroyed', 'bewaartermijn' => 'P5Y'],
			['archiefstatus' => 'vernietigd', 'bewaartermijn' => 'P5Y'],
			['archiefstatus' => 'nog_te_archiveren'],
			['bewaartermijn' => ''],
			['bewaartermijn' => 5],
			null,
		];
		foreach ($blocks as $block) {
			$object = $this->record($block);
			$this->assertFalse($this->follower->follow($object, new Schema()), json_encode($block));
			$this->assertSame(($block ?? []), $object->getRetention());
		}

		$this->assertSame([], $this->logged);
	}//end testFrozenOrPeriodlessRecordsAreLeftAlone()

	/**
	 * An unchanged date changes nothing.
	 *
	 * @return void
	 */
	public function testAnUnchangedDateChangesNothing(): void {
		$block  = ['archiefstatus' => 'nog_te_archiveren', 'bewaartermijn' => 'P5Y', 'archiefactiedatum' => '2031-01-01'];
		$object = $this->record($block);
		$this->calculator->expects($this->once())->method('calculate')->with($object, $this->anything(), 'P5Y')->willReturn('2031-01-01');

		$this->assertFalse($this->follower->follow($object, new Schema()));
		$this->assertSame($block, $object->getRetention());
		$this->assertSame([], $this->logged);

		$this->setUp();
		$object = $this->record(['bewaartermijn' => 'P5Y']);
		$this->calculator->method('calculate')->willReturn(null);
		$this->audit->expects($this->never())->method('createAuditTrailEntry');
		$this->assertFalse($this->follower->follow($object, new Schema()), 'no date before, none now');
	}//end testAnUnchangedDateChangesNothing()

	/**
	 * A date that can no longer be calculated is cleared, audited and logged.
	 *
	 * @return void
	 */
	public function testALostSourceDateClearsTheDate(): void {
		$object = $this->record(['bewaartermijn' => 'P5Y', 'archiefactiedatum' => '2031-01-01', 'other' => 'kept']);
		$this->calculator->method('calculate')->willReturn(null);
		$this->audit->expects($this->once())->method('createAuditTrailEntry')->with(
			$object,
			'archival.destruction_date_removed',
			[
				'archiefactiedatum' => ['old' => '2031-01-01', 'new' => null],
				'reason' => 'The source date of the destruction date was cleared.',
			]
		);

		$this->assertTrue($this->follower->follow($object, new Schema()));
		$this->assertSame(['bewaartermijn' => 'P5Y', 'other' => 'kept'], $object->getRetention());
		$this->assertSame(['[ActionDateFollower] Cleared archiefactiedatum for object obj-1 (was 2031-01-01)'], $this->logged);
	}//end testALostSourceDateClearsTheDate()

	/**
	 * A new date is stored beside the other retention keys and logged.
	 *
	 * @return void
	 */
	public function testANewDateIsStoredAndLogged(): void {
		$object = $this->record(['bewaartermijn' => 'P5Y', 'archiefactiedatum' => '2031-01-01', 'other' => 'kept']);
		$this->calculator->method('calculate')->willReturn('2032-02-02');
		$this->audit->expects($this->never())->method('createAuditTrailEntry');

		$this->assertTrue($this->follower->follow($object, new Schema()));
		$this->assertSame(['bewaartermijn' => 'P5Y', 'archiefactiedatum' => '2032-02-02', 'other' => 'kept'], $object->getRetention());
		$this->assertSame(['[ActionDateFollower] Recalculated archiefactiedatum for object obj-1: 2031-01-01 -> 2032-02-02'], $this->logged);

		$this->setUp();
		$first = $this->record(['bewaartermijn' => 'P5Y']);
		$this->calculator->method('calculate')->willReturn('2032-02-02');
		$this->assertTrue($this->follower->follow($first, new Schema()));
		$this->assertSame(['[ActionDateFollower] Recalculated archiefactiedatum for object obj-1:  -> 2032-02-02'], $this->logged);
	}//end testANewDateIsStoredAndLogged()
}//end class
