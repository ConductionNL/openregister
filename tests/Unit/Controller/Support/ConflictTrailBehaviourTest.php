<?php

/**
 * ConflictTrail behaviour tests.
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Intervening changes are the trail entries written after the version the
 * client read; a refused write is recorded, and a failure to record it is logged.
 */
class ConflictTrailBehaviourTest extends TestCase {

	/**
	 * The audit trail double.
	 *
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface&MockObject $auditTrail;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->auditTrail = $this->createMock(AuditTrailMapperInterface::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A mismatch on an object with a known uuid.
	 *
	 * @param string $expected The version the client read.
	 *
	 * @return VersionMismatch
	 */
	private function mismatch(string $expected): VersionMismatch {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		return new VersionMismatch($object, $expected, '2026-05-01T12:00:00+00:00');
	}//end mismatch()

	/**
	 * A trail entry created at a fixed UTC moment.
	 *
	 * @param string|null $created The moment, or null.
	 *
	 * @return AuditTrail
	 */
	private function entry(?string $created): AuditTrail {
		$entry = new AuditTrail();
		if ($created !== null) {
			$entry->setCreated(new DateTime($created));
		}

		return $entry;
	}//end entry()

	/**
	 * Only entries strictly after the read version are intervening.
	 *
	 * @return void
	 */
	public function testOnlyLaterEntriesIntervene(): void {
		$later  = $this->entry('2026-05-01T11:00:00+00:00');
		$same   = $this->entry('2026-05-01T10:00:00+00:00');
		$before = $this->entry('2026-05-01T09:00:00+00:00');
		$undated = $this->entry(null);
		$this->auditTrail->expects($this->once())
			->method('findAll')
			->with(25, null, ['object_uuid' => 'obj-1'])
			->willReturn([$later, $same, $before, $undated, 'not an entry']);

		$changes = (new ConflictTrail($this->auditTrail, $this->logger))
			->interveningChanges($this->mismatch('2026-05-01T10:00:00+00:00'));

		$this->assertSame([$later], $changes);
	}//end testOnlyLaterEntriesIntervene()

	/**
	 * An unparseable read version yields no changes and never reads the trail.
	 *
	 * @return void
	 */
	public function testUnparseableVersionYieldsNothing(): void {
		$this->auditTrail->expects($this->never())->method('findAll');

		$changes = (new ConflictTrail($this->auditTrail, $this->logger))
			->interveningChanges($this->mismatch('not a date'));

		$this->assertSame([], $changes);
	}//end testUnparseableVersionYieldsNothing()

	/**
	 * A refusal is recorded with both versions and the conflicting property names.
	 *
	 * @return void
	 */
	public function testRefusalIsRecordedWithItsContext(): void {
		$mismatch = $this->mismatch('2026-05-01T10:00:00+00:00');
		$this->auditTrail->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$mismatch->stored,
				'refused',
				[
					'reason' => 'version-conflict',
					'expectedUpdated' => '2026-05-01T10:00:00+00:00',
					'currentUpdated' => '2026-05-01T12:00:00+00:00',
					'properties' => ['title', 'status'],
				]
			)
			->willReturn(new AuditTrail());
		$this->logger->expects($this->never())->method('warning');

		(new ConflictTrail($this->auditTrail, $this->logger))
			->recordRefusal($mismatch, ['conflicts' => ['title' => [], 'status' => []]]);
	}//end testRefusalIsRecordedWithItsContext()

	/**
	 * A body without conflicts records an empty property list.
	 *
	 * @return void
	 */
	public function testRefusalWithoutConflictsRecordsNoProperties(): void {
		$recorded = null;
		$this->auditTrail->method('createAuditTrailEntry')->willReturnCallback(
			static function (ObjectEntity $object, string $action, array $context) use (&$recorded): AuditTrail {
				$recorded = $context;
				return new AuditTrail();
			}
		);

		(new ConflictTrail($this->auditTrail, $this->logger))
			->recordRefusal($this->mismatch('2026-05-01T10:00:00+00:00'), []);

		$this->assertSame([], $recorded['properties']);
	}//end testRefusalWithoutConflictsRecordsNoProperties()

	/**
	 * A failure to record the refusal is logged, not thrown.
	 *
	 * @return void
	 */
	public function testRecordingFailureIsLogged(): void {
		$this->auditTrail->method('createAuditTrailEntry')->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[ObjectsController] a refused write could not be recorded: db gone', $this->arrayHasKey('file'));

		(new ConflictTrail($this->auditTrail, $this->logger))
			->recordRefusal($this->mismatch('2026-05-01T10:00:00+00:00'), []);
	}//end testRecordingFailureIsLogged()
}//end class
