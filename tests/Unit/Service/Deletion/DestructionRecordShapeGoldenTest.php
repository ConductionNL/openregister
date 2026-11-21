<?php

/**
 * Golden test pinning the full shape of a destruction record and its refusal.
 *
 * Written on DestructionRecorder::record() before it became a facade over a
 * command object (ADR-011). The timestamp is only checked for its format, so
 * nothing here depends on the clock or the timezone.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.openregister.app
 */

declare(strict_types=1);

namespace Unit\Service\Deletion;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\DestructionScope;
use OCA\OpenRegister\Tests\Support\AuditTrailEvents;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use OCA\OpenRegister\Service\Deletion\RecordDestructionCommand;

final class DestructionRecordShapeGoldenTest extends TestCase {

	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn('Name of ' . $uid);
		}

		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('zaak-7');
		$object->setRegister('3');
		$object->setSchema('9');

		return $object;
	}//end object()

	public function testTheRecordCarriesEveryFieldAndTheContextWins(): void {
		$call   = [];
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->expects(self::once())
			->method('recordObjectEvent')
			->willReturnCallback(AuditTrailEvents::positional(
				function (...$args) use (&$call): AuditTrail {
					$call = $args;

					return new AuditTrail();
				}
			));

		$recorder = new DestructionRecorder($mapper, $this->session('rm-1'), new NullLogger());
		$record   = $recorder->recordDestruction(command: new RecordDestructionCommand(object: $this->object(), scope: ['scope' => ['notes'], 'destroyed' => ['notes' => 1], 'total' => 1, 'failed' => ['files']], rule: 'retention-sweep', context: ['rule' => 'overridden', 'window' => ['days' => 30]]));

		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $record['destroyedAt']);
		unset($record['destroyedAt']);
		self::assertSame(
			[
				'destroyedBy' => 'rm-1',
				'destroyedByName' => 'Name of rm-1',
				'objectUuid' => 'zaak-7',
				'register' => '3',
				'schema' => '9',
				'rule' => 'overridden',
				'scope' => ['notes'],
				'destroyed' => ['notes' => 1],
				'destroyedTotal' => 1,
				'failed' => ['files'],
				'window' => ['days' => 30],
			],
			$record
		);

		self::assertSame(DestructionScope::DESTRUCTION_ACTION, $call[1]);
		self::assertSame('rm-1', $call[3]);
		self::assertSame('Name of rm-1', $call[4]);
		self::assertSame('overridden', $call[2]['rule']);
	}//end testTheRecordCarriesEveryFieldAndTheContextWins()

	public function testAnEmptyScopeReportDefaultsAndTheSystemActs(): void {
		$mapper   = $this->createMock(AuditTrailMapper::class);
		$recorder = new DestructionRecorder($mapper, $this->session(null), new NullLogger());
		$record   = $recorder->recordDestruction(new RecordDestructionCommand($this->object(), [], 'destroy-right-granted'));

		self::assertSame('system', $record['destroyedBy']);
		self::assertSame('System', $record['destroyedByName']);
		self::assertSame([], $record['scope']);
		self::assertSame([], $record['destroyed']);
		self::assertSame(0, $record['destroyedTotal']);
		self::assertSame([], $record['failed']);
		self::assertSame('destroy-right-granted', $record['rule']);
	}//end testAnEmptyScopeReportDefaultsAndTheSystemActs()

	public function testAnUnwritableRecordRefusesWithTheCauseChained(): void {
		$cause  = new RuntimeException('db down');
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('recordObjectEvent')->willThrowException($cause);

		$recorder = new DestructionRecorder($mapper, $this->session('rm-1'), new NullLogger());
		try {
			$recorder->recordDestruction(new RecordDestructionCommand($this->object(), [], 'destroy-right-granted'));
			self::fail('An unrecorded destruction must refuse.');
		} catch (DestructionRefusedException $e) {
			self::assertSame('destruction-unrecordable', $e->getRule());
			self::assertSame(500, $e->getStatusCode());
			self::assertSame($cause, $e->getPrevious());
			self::assertSame('The destruction record could not be written, so nothing was destroyed.', $e->getMessage());
		}
	}//end testAnUnwritableRecordRefusesWithTheCauseChained()
}//end class
