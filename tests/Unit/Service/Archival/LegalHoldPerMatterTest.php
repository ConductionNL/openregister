<?php

/**
 * Two matters holding the same object keep their own hold (openregister#4172).
 *
 * With one slot per object, a second placement overwrote the first reason and
 * releasing either matter lifted the hold the other still needed. These tests
 * drive the real LegalHoldService over a real ObjectEntity, and the real
 * DestructionCheckJob predicate reads the result (`legalHold.active`).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/legal-hold-per-matter/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\LegalHoldService;
use OCP\BackgroundJob\IJobList;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Archival\LegalHoldService
 * @covers \OCA\OpenRegister\Service\Archival\LegalHoldLedger
 */
class LegalHoldPerMatterTest extends TestCase {

	private const LAWSUIT = 'filinq:legalHoldCase:11111111-1111-4111-8111-111111111111';

	private const AUDIT = 'filinq:legalHoldCase:22222222-2222-4222-8222-222222222222';

	private LegalHoldService $service;

	protected function setUp(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$mapper = $this->getMockBuilder(MagicMapper::class)->disableOriginalConstructor()->onlyMethods(['update'])->getMock();
		$mapper->method('update')->willReturnArgument(0);

		$this->service = new LegalHoldService(
			$mapper,
			$this->createMock(AuditTrailMapper::class),
			$session,
			$this->createMock(IJobList::class),
			new NullLogger()
		);
	}//end setUp()

	private function object(array $retention = []): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('33333333-3333-4333-8333-333333333333');
		$object->setRetention($retention);

		return $object;
	}//end object()

	/**
	 * A second matter adds its own hold; the first keeps its reason.
	 */
	public function testASecondMatterAddsItsOwnHold(): void {
		$object = $this->service->placeHold($this->object(), 'Lawsuit 2026-12', self::LAWSUIT);
		$object = $this->service->placeHold($object, 'Audit 2026', self::AUDIT);

		$holds = $object->getRetention()['legalHold']['holds'];
		$this->assertSame([self::LAWSUIT, self::AUDIT], array_column($holds, 'ownerKey'));
		$this->assertSame(['Lawsuit 2026-12', 'Audit 2026'], array_column($holds, 'reason'));
		$this->assertTrue($object->hasActiveLegalHold());
	}//end testASecondMatterAddsItsOwnHold()

	/**
	 * Releasing one matter keeps the object held by the other, and records only the released hold.
	 */
	public function testReleasingOneMatterKeepsTheOtherHold(): void {
		$object = $this->service->placeHold($this->object(), 'Lawsuit 2026-12', self::LAWSUIT);
		$object = $this->service->placeHold($object, 'Audit 2026', self::AUDIT);

		$object = $this->service->releaseHold($object, 'Audit closed', self::AUDIT);

		$legalHold = $object->getRetention()['legalHold'];
		$this->assertTrue($object->hasActiveLegalHold(), 'The lawsuit still needs the record frozen.');
		$this->assertSame([self::LAWSUIT], array_column($legalHold['holds'], 'ownerKey'));
		$this->assertSame('Lawsuit 2026-12', $legalHold['reason']);
		$this->assertCount(1, $legalHold['history']);
		$this->assertSame(self::AUDIT, $legalHold['history'][0]['ownerKey']);
		$this->assertSame('Audit closed', $legalHold['history'][0]['releaseReason']);

		$object = $this->service->releaseHold($object, 'Settled', self::LAWSUIT);
		$this->assertFalse($object->hasActiveLegalHold());
		$this->assertCount(2, $object->getRetention()['legalHold']['history']);
	}//end testReleasingOneMatterKeepsTheOtherHold()

	/**
	 * A stored single-slot hold reads as a list of one, and a matter's hold sits beside it.
	 */
	public function testAStoredSingleSlotHoldStaysValid(): void {
		$legacy = $this->object([
			'legalHold' => [
				'active' => true,
				'reason' => 'WOO-verzoek 2025-0142',
				'placedBy' => 'archivaris-1',
				'placedDate' => '2026-01-01T00:00:00+00:00',
				'history' => [],
			],
		]);

		$object = $this->service->placeHold($legacy, 'Lawsuit 2026-12', self::LAWSUIT);
		$object = $this->service->releaseHold($object, 'Settled', self::LAWSUIT);

		$legalHold = $object->getRetention()['legalHold'];
		$this->assertTrue($legalHold['active'], 'The stored hold was neither overwritten nor lifted.');
		$this->assertSame('WOO-verzoek 2025-0142', $legalHold['reason']);

		// A release that names no matter lifts every hold, as it always did.
		$object = $this->service->releaseHold($object, 'Handled');
		$this->assertFalse($object->hasActiveLegalHold());
	}//end testAStoredSingleSlotHoldStaysValid()
}//end class
