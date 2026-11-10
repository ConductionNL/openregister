<?php

/**
 * Unit tests for the frame that names a background job as the actor of draft removals.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\RemovedDraft;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\DraftRemovalFrame;
use OCA\OpenRegister\Service\Object\DraftRemovalRecord;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Inside the frame a removal is attributed to the job; outside it, and after a throw, to nobody.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftRemovalFrame
 * @covers \OCA\OpenRegister\Service\Object\DraftRemovalRecord
 */
class DraftRemovalFrameTest extends TestCase {

	/**
	 * The actor ids the audit trail was handed, in order.
	 *
	 * @var list<string|null>
	 */
	private array $actors = [];

	/**
	 * A removal record over a recording audit trail.
	 *
	 * @return DraftRemovalRecord
	 */
	private function record(): DraftRemovalRecord {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context, ?string $actorId): AuditTrail {
				$this->actors[] = $actorId;
				return new AuditTrail();
			}
		);

		return new DraftRemovalRecord($trail, new NullLogger());

	}//end record()

	/**
	 * A removal inside the frame names the job; the frame returns what the operation returned.
	 *
	 * @return void
	 */
	public function testARemovalInsideTheFrameNamesTheJob(): void {
		$record = $this->record();
		$draft  = new ObjectEntity();

		$result = (new DraftRemovalFrame())->asJob(
			'draft-expiry',
			static fn (): string => $record->write(new RemovedDraft(draft: $draft, register: null, schema: null)) === true ? 'written' : 'not written'
		);
		$record->write(new RemovedDraft(draft: $draft, register: null, schema: null));

		$this->assertSame('written', $result);
		$this->assertSame([DraftRemovalRecord::JOB_ACTOR_PREFIX . 'draft-expiry', null], $this->actors);

	}//end testARemovalInsideTheFrameNamesTheJob()

	/**
	 * A nested frame names the inner job, and the outer job again once it ends.
	 *
	 * @return void
	 */
	public function testANestedFrameRestoresTheOuterJob(): void {
		$record = $this->record();
		$draft  = new ObjectEntity();
		$frame  = new DraftRemovalFrame();

		$frame->asJob(
			'outer',
			static function () use ($frame, $record, $draft): void {
				$frame->asJob('inner', static fn (): bool => $record->write(new RemovedDraft(draft: $draft, register: null, schema: null)));
				$record->write(new RemovedDraft(draft: $draft, register: null, schema: null));
			}
		);

		$this->assertSame(['job:inner', 'job:outer'], $this->actors);

	}//end testANestedFrameRestoresTheOuterJob()

	/**
	 * A throwing operation still ends the frame, and the throw reaches the caller.
	 *
	 * @return void
	 */
	public function testAThrowingOperationStillEndsTheFrame(): void {
		$record = $this->record();

		try {
			(new DraftRemovalFrame())->asJob(
				'failing',
				static function (): never {
					throw new RuntimeException('boom');
				}
			);
			$this->fail('the throw did not reach the caller');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$record->write(new RemovedDraft(draft: new ObjectEntity(), register: null, schema: null));
		$this->assertSame([null], $this->actors);

	}//end testAThrowingOperationStillEndsTheFrame()
}//end class
