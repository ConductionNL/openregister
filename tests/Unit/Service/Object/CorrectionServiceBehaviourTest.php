<?php

/**
 * CorrectionService behaviour: who may correct, what is corrected, and how the act is recorded.
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

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\CorrectionRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\CorrectionAuditing;
use OCA\OpenRegister\Service\Object\CorrectionService;
use OCA\OpenRegister\Service\Object\CorrectionTarget;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every branch of a correction.
 *
 * @covers \OCA\OpenRegister\Service\Object\CorrectionService
 * @uses \OCA\OpenRegister\Service\Object\CorrectionAuditing
 * @uses \OCA\OpenRegister\Service\Object\CorrectionTarget
 * @uses \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses \OCA\OpenRegister\Exception\CorrectionRefusedException
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\AuditTrail
 * @uses \OCA\OpenRegister\Db\AuditTrail\AuditedObject
 */
class CorrectionServiceBehaviourTest extends TestCase {

	/**
	 * The object operations.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface $objects;

	/**
	 * The action right check.
	 *
	 * @var OpenRegisterActionAuthService&MockObject
	 */
	private OpenRegisterActionAuthService $actionAuth;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * The audit trail mapper.
	 *
	 * @var AuditTrailMapperInterface&MockObject
	 */
	private AuditTrailMapperInterface $audit;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The signed-in user.
	 *
	 * @var IUser&MockObject
	 */
	private IUser $user;

	/**
	 * Build the boundaries with bob signed in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->actionAuth = $this->createMock(OpenRegisterActionAuthService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->audit = $this->createMock(AuditTrailMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->user = $this->createMock(IUser::class);
		$this->user->method('getUID')->willReturn('bob');
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return CorrectionService
	 */
	private function service(): CorrectionService {
		return new CorrectionService($this->objects, $this->actionAuth, $this->session, new CorrectionAuditing($this->audit, $this->logger));
	}//end service()

	/**
	 * The stored object and the saved one, with an optional last-log entry.
	 *
	 * @param array<string, mixed>|null $lastLog The saved object's last log.
	 *
	 * @return ObjectEntity The saved object.
	 */
	private function storedAndSaved(?array $lastLog): ObjectEntity {
		$this->session->method('getUser')->willReturn($this->user);
		$existing = new ObjectEntity();
		$existing->setUuid('u-1');
		$existing->setObject(['name' => 'Old', 'size' => 3]);
		$saved = new ObjectEntity();
		$saved->setUuid('u-1');
		$saved->setLastLog($lastLog);
		$this->objects->method('findSilent')->with(...FindCallShape::findSilentArgs('obj-1', [], false, 'reg', 'sch', false, false))->willReturn($existing);
		$this->objects->expects($this->once())->method('saveObject')
			->with(['name' => 'New', 'size' => 3, 'id' => 'u-1', 'colour' => 'red'], [], 'reg', 'sch', 'u-1')
			->willReturn($saved);

		return $saved;
	}//end storedAndSaved()

	/**
	 * Correct name and colour; the system keys are dropped before the save.
	 *
	 * @param string|null $reason The reason given.
	 *
	 * @return array{object: ObjectEntity, audit: AuditTrail|null}
	 */
	private function correct(?string $reason = '  typo in the name '): array {
		return $this->service()->correct(
			new CorrectionTarget(register: 'reg', schema: 'sch', id: 'obj-1'),
			['name' => 'New', 'colour' => 'red', '@self' => ['x'], '_hidden' => 1, 'id' => 9, 'uuid' => 'z', 'register' => 1, 'schema' => 2],
			$reason
		);
	}//end correct()

	/**
	 * The context the correction records.
	 *
	 * @return array<string, mixed>
	 */
	private function context(): array {
		return [
			'correction' => [
				'reason' => 'typo in the name',
				'right' => 'object.correct',
				'actor' => 'bob',
				'fields' => ['name' => ['old' => 'Old', 'new' => 'New'], 'colour' => ['old' => null, 'new' => 'red']],
			],
		];
	}//end context()

	/**
	 * Nobody signed in: refused before the right is checked.
	 *
	 * @return void
	 */
	public function testNobodySignedInIsRefused(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->actionAuth->expects($this->never())->method('requireAction');
		$this->objects->expects($this->never())->method('saveObject');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('Sign in to correct a value.');
		$this->correct();
	}//end testNobodySignedInIsRefused()

	/**
	 * A user without the correction right gets a 403 naming the right, and nothing is saved.
	 *
	 * @return void
	 */
	public function testAUserWithoutTheRightIsRefused(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->actionAuth->expects($this->once())->method('requireAction')
			->with($this->user, 'object.correct')->willThrowException(new RuntimeException('no group'));
		$this->objects->expects($this->never())->method('saveObject');

		try {
			$this->correct();
			$this->fail('expected a refusal');
		} catch (NotAuthorizedException $e) {
			$this->assertSame(403, $e->getCode());
			$this->assertSame("Correcting a value needs the 'object.correct' right. no group", $e->getMessage());
		}
	}//end testAUserWithoutTheRightIsRefused()

	/**
	 * A blank reason and a set of only system keys are each refused before anything is read.
	 *
	 * @return void
	 */
	public function testABlankReasonOrNoCorrectableValueIsRefused(): void {
		$this->session->method('getUser')->willReturn($this->user);
		$this->objects->expects($this->never())->method('findSilent');
		$messages = [];
		foreach ([['   ', ['name' => 'x']], [null, ['name' => 'x']], ['why', ['@self' => [], 'id' => 1]]] as [$reason, $values]) {
			try {
				$this->service()->correct(new CorrectionTarget(register: 'reg', schema: 'sch', id: 'obj-1'), $values, $reason);
			} catch (CorrectionRefusedException $e) {
				$messages[] = $e->getMessage();
			}
		}

		$this->assertSame(
			[
				'A correction needs a reason. Say why the recorded value is wrong.',
				'A correction needs a reason. Say why the recorded value is wrong.',
				'A correction needs at least one value to correct.',
			],
			$messages
		);
	}//end testABlankReasonOrNoCorrectableValueIsRefused()

	/**
	 * With an unsealed last entry the save's own entry is relabelled as the correction.
	 *
	 * @return void
	 */
	public function testTheSavesUnsealedEntryIsRelabelled(): void {
		$saved = $this->storedAndSaved(lastLog: ['id' => '41']);
		$entry = new AuditTrail();
		$entry->setAction('update');
		$entry->setChanged(['name' => ['old' => 'Old', 'new' => 'New']]);
		$this->audit->method('find')->with(41)->willReturn($entry);
		$this->audit->expects($this->once())->method('update')->with($this->identicalTo($entry))->willReturnArgument(0);
		$this->audit->expects($this->never())->method('createAuditTrailEntry');

		$result = $this->correct();

		$this->assertSame($saved, $result['object']);
		$this->assertSame($entry, $result['audit']);
		$this->assertSame('correction', $entry->getAction());
		$this->assertSame(['name' => ['old' => 'Old', 'new' => 'New']] + $this->context(), $entry->getChanged());
	}//end testTheSavesUnsealedEntryIsRelabelled()

	/**
	 * A sealed entry, an unreadable one, or none at all: the correction gets its own entry under the actor.
	 *
	 * @return void
	 */
	public function testWithoutAnAmendableEntryTheCorrectionGetsItsOwn(): void {
		$sealed = new AuditTrail();
		$sealed->setHash('abc');
		$own = new AuditTrail();
		foreach ([['id' => 5], ['id' => 6], null] as $index => $lastLog) {
			$this->setUp();
			$saved = $this->storedAndSaved(lastLog: $lastLog);
			if ($index === 0) {
				$this->audit->method('find')->willReturn($sealed);
			} else {
				$this->audit->method('find')->willThrowException(new RuntimeException('missing'));
			}

			$this->audit->expects($this->never())->method('update');
			$this->audit->expects($this->once())->method('createAuditTrailEntry')
				->with($this->identicalTo($saved), 'correction', $this->context(), 'bob')->willReturn($own);

			$this->assertSame($own, $this->correct()['audit']);
		}
	}//end testWithoutAnAmendableEntryTheCorrectionGetsItsOwn()

	/**
	 * A relabel that fails is logged and falls back to its own entry; when that fails too the result has no audit entry and an error is logged.
	 *
	 * @return void
	 */
	public function testFailedWritesAreLoggedAndTheCorrectionStands(): void {
		$saved = $this->storedAndSaved(lastLog: ['id' => 7]);
		$this->audit->method('find')->willReturn(new AuditTrail());
		$this->audit->method('update')->willThrowException(new RuntimeException('locked'));
		$this->audit->method('createAuditTrailEntry')->willThrowException(new RuntimeException('disk full'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[CorrectionService] Could not relabel the audit entry as a correction',
			$this->callback(fn (array $c): bool => $c['auditId'] === 7 && $c['error'] === 'locked')
		);
		$this->logger->expects($this->once())->method('error')->with(
			'[CorrectionService] The correction landed and the audit entry did not',
			$this->callback(fn (array $c): bool => $c['uuid'] === 'u-1' && $c['error'] === 'disk full')
		);

		$result = $this->correct();

		$this->assertSame($saved, $result['object']);
		$this->assertNull($result['audit']);
	}//end testFailedWritesAreLoggedAndTheCorrectionStands()
}//end class
