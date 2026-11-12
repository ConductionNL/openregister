<?php

/**
 * DraftRemovalRecord on its own: the entry a removed draft leaves, and under whose name.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\RemovedDraft;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DraftRemovalRecord;
use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The record's facts, the job scope and the failure path.
 *
 * @covers \OCA\OpenRegister\Service\Object\DraftRemovalRecord
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 */
class DraftRemovalRecordBehaviourTest extends TestCase {

	/**
	 * A draft owned by erin.
	 *
	 * @return ObjectEntity
	 */
	private function draft(): ObjectEntity {
		$draft = new ObjectEntity();
		$draft->setUuid('d-1');
		$draft->setOwner('erin');

		return $draft;
	}//end draft()

	/**
	 * Outside a job the entry has no actor override and names register and schema by slug.
	 *
	 * @return void
	 */
	public function testOutsideAJobTheEntryCarriesTheFactsOnly(): void {
		$draft = $this->draft();
		$register = new Register();
		$register->setSlug('service');
		$schema = new Schema();
		$schema->setSlug('ticket');
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->expects($this->once())->method('recordObjectEvent')->with(AuditTrailEvents::matching([
			$this->identicalTo($draft),
			'draft.removed',
			['status' => 'draft', 'permanent' => true, 'job' => null, 'register' => 'service', 'schema' => 'ticket', 'owner' => 'erin'],
			null,
			null
		]))->willReturn(new AuditTrail());

		$this->assertTrue((new DraftRemovalRecord($audit, $this->createMock(LoggerInterface::class)))->write(
			new RemovedDraft(draft: $draft, register: $register, schema: $schema)
		));
	}//end testOutsideAJobTheEntryCarriesTheFactsOnly()

	/**
	 * Inside asJob the entry is written under the job, nested jobs restore the outer one, and the scope ends with the operation.
	 *
	 * @return void
	 */
	public function testInsideAJobTheEntryNamesTheJobAndTheScopeIsRestored(): void {
		$actors = [];
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			function (ObjectEntity $object, string $action, array $context, ?string $actorId, ?string $actorName) use (&$actors): AuditTrail {
				$actors[] = [$context['job'], $actorId, $actorName, $context['register'], $context['schema']];
				return new AuditTrail();
			}
		));
		$record = new DraftRemovalRecord($audit, $this->createMock(LoggerInterface::class));
		$draft = $this->draft();

		$answer = DraftRemovalRecord::asJob(
			'outer',
			function () use ($record, $draft): string {
				$record->write(new RemovedDraft(draft: $draft, register: null, schema: null));
				DraftRemovalRecord::asJob('inner', fn (): bool => $record->write(new RemovedDraft(draft: $draft, register: null, schema: null)));
				$record->write(new RemovedDraft(draft: $draft, register: null, schema: null));
				return 'done';
			}
		);
		$record->write(new RemovedDraft(draft: $draft, register: null, schema: null));

		$this->assertSame('done', $answer);
		$this->assertSame(
			[
				['outer', 'job:outer', 'outer', null, null],
				['inner', 'job:inner', 'inner', null, null],
				['outer', 'job:outer', 'outer', null, null],
				[null, null, null, null, null],
			],
			$actors
		);
	}//end testInsideAJobTheEntryNamesTheJobAndTheScopeIsRestored()

	/**
	 * A job that throws still ends its scope.
	 *
	 * @return void
	 */
	public function testAThrowingJobEndsItsScope(): void {
		$jobs = [];
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('recordObjectEvent')->willReturnCallback(AuditTrailEvents::positional(
			function (ObjectEntity $object, string $action, array $context) use (&$jobs): AuditTrail {
				$jobs[] = $context['job'];
				return new AuditTrail();
			}
		));
		$record = new DraftRemovalRecord($audit, $this->createMock(LoggerInterface::class));
		try {
			DraftRemovalRecord::asJob('sweep', function (): void {
				throw new RuntimeException('boom');
			});
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$record->write(new RemovedDraft(draft: $this->draft(), register: null, schema: null));

		$this->assertSame([null], $jobs);
	}//end testAThrowingJobEndsItsScope()

	/**
	 * An entry that cannot be written is logged with the facts and uuid, never the content, and reported as false.
	 *
	 * @return void
	 */
	public function testAFailedEntryIsLoggedAndReported(): void {
		$draft = $this->draft();
		$draft->setObject(['secret' => 'content']);
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->method('recordObjectEvent')->willThrowException(new RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[DraftRemovalRecord] A draft was removed and its audit-trail entry could not be written',
			['status' => 'draft', 'permanent' => true, 'job' => null, 'register' => null, 'schema' => null, 'owner' => 'erin', 'uuid' => 'd-1', 'exception' => 'db down']
		);

		$this->assertFalse((new DraftRemovalRecord($audit, $logger))->write(new RemovedDraft(draft: $draft, register: null, schema: null)));
	}//end testAFailedEntryIsLoggedAndReported()
}//end class
