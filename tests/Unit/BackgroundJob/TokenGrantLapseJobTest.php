<?php
/**
 * The holder of a Consumer token is warned before its grant lapses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\BackgroundJob\TokenGrantLapseJob;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapperInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\TokenGrantLapseJob
 * @uses   \OCA\OpenRegister\Db\Consumer
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrant
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantReach
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantTerms
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantValidator
 */
class TokenGrantLapseJobTest extends TestCase {

	/**
	 * The sweep's moment.
	 *
	 * @var string
	 */
	private const NOW = '2026-11-15T12:00:00+00:00';

	/** @var ConsumerMapperInterface&MockObject */
	private ConsumerMapperInterface $consumers;

	/** @var IManager&MockObject */
	private IManager $notifications;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var array<int, array{user: string, object: array{string, string}, subject: string, parameters: array<string, mixed>}> */
	private array $sent = [];

	/** @var array<int, string> The users whose earlier warning was replaced. */
	private array $replaced = [];

	/**
	 * The job under test.
	 *
	 * @var TokenGrantLapseJob
	 */
	private TokenGrantLapseJob $job;

	/**
	 * A job over a fixed clock, recording what it sends.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->consumers     = $this->createMock(ConsumerMapperInterface::class);
		$this->notifications = $this->createMock(IManager::class);
		$this->logger        = $this->createMock(LoggerInterface::class);

		$this->notifications->method('createNotification')->willReturnCallback(fn (): INotification => $this->recordingNotification());
		$this->notifications->method('markProcessed')->willReturnCallback(
			function (INotification $notification): void {
				$this->replaced[] = $notification->getUser();
			}
		);
		$this->notifications->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = [
					'user' => $notification->getUser(),
					'object' => [$notification->getObjectType(), $notification->getObjectId()],
					'subject' => $notification->getSubject(),
					'parameters' => $notification->getSubjectParameters(),
				];
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): ?IUser {
				if ($uid !== 'alice') {
					return null;
				}

				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn('alice');
				return $user;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime(self::NOW));

		$this->job = new TokenGrantLapseJob(
			time: $time,
			logger: $this->logger,
			consumers: $this->consumers,
			validator: new TokenGrantValidator(catalogue: $this->createMock(PermissionCatalogueInterface::class)),
			notifications: $this->notifications,
			users: $users
		);
	}//end setUp()

	/**
	 * A notification double that keeps what is set on it.
	 *
	 * @return INotification The notification.
	 */
	private function recordingNotification(): INotification {
		$state        = ['user' => '', 'type' => '', 'id' => '', 'subject' => '', 'parameters' => []];
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setUser')->willReturnCallback(
			function (string $user) use (&$state, $notification): INotification {
				$state['user'] = $user;
				return $notification;
			}
		);
		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $id) use (&$state, $notification): INotification {
				$state['type'] = $type;
				$state['id']   = $id;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use (&$state, $notification): INotification {
				$state['subject']    = $subject;
				$state['parameters'] = $parameters;
				return $notification;
			}
		);
		$notification->method('getUser')->willReturnCallback(static function () use (&$state): string {
			return $state['user'];
		});
		$notification->method('getObjectType')->willReturnCallback(static function () use (&$state): string {
			return $state['type'];
		});
		$notification->method('getObjectId')->willReturnCallback(static function () use (&$state): string {
			return $state['id'];
		});
		$notification->method('getSubject')->willReturnCallback(static function () use (&$state): string {
			return $state['subject'];
		});
		$notification->method('getSubjectParameters')->willReturnCallback(static function () use (&$state): array {
			return $state['parameters'];
		});

		return $notification;
	}//end recordingNotification()

	/**
	 * A Consumer acting as a user, with a grant ending at a given moment.
	 *
	 * @param string      $uuid      The Consumer uuid.
	 * @param string|null $expiresAt The grant's end date; null for a grant without one.
	 * @param string|null $userId    The user the Consumer acts as.
	 * @param bool        $withGrant Whether the Consumer carries a grant at all.
	 *
	 * @return Consumer The Consumer.
	 */
	private function consumer(string $uuid, ?string $expiresAt, ?string $userId = 'alice', bool $withGrant = true): Consumer {
		$consumer = new Consumer();
		$consumer->setId(7);
		$consumer->setUuid($uuid);
		$consumer->setName('Koppeling ' . $uuid);
		$consumer->setUserId($userId);

		$configuration = [];
		if ($withGrant === true) {
			$grant = ['verbs' => ['read']];
			if ($expiresAt !== null) {
				$grant['expiresAt'] = $expiresAt;
			}

			$configuration['grant'] = $grant;
		}

		$consumer->setAuthorizationConfiguration($configuration);
		return $consumer;
	}//end consumer()

	/**
	 * Run the daily sweep.
	 *
	 * @return void
	 */
	private function sweep(): void {
		(new ReflectionMethod(TokenGrantLapseJob::class, 'run'))->invoke($this->job, null);
	}//end sweep()

	/**
	 * A token ending inside the window warns the user it acts as, naming the token and the date.
	 *
	 * @return void
	 */
	public function testAGrantEndingInsideTheWindowWarnsItsHolder(): void {
		$this->consumers->method('findAll')->willReturn([$this->consumer(uuid: 'c-1', expiresAt: '2026-11-25T12:00:00+00:00')]);
		$this->logger->expects($this->once())->method('info')
			->with('[TokenGrantLapseJob] Warned {count} holders of lapsing tokens', ['count' => 1]);

		$this->sweep();

		$this->assertSame(
			[
				[
					'user' => 'alice',
					'object' => ['token_grant', 'c-1'],
					'subject' => 'token_grant_lapsing',
					'parameters' => ['token' => 'Koppeling c-1', 'endsAt' => '2026-11-25', 'daysLeft' => 10],
				],
			],
			$this->sent
		);
		// The earlier warning for the same token is replaced, not stacked.
		$this->assertSame(['alice'], $this->replaced);
	}//end testAGrantEndingInsideTheWindowWarnsItsHolder()

	/**
	 * The last day of the fourteen-day window still warns; the day after does not.
	 *
	 * @return void
	 */
	public function testTheWindowIsFourteenDays(): void {
		$now = new DateTimeImmutable(self::NOW);

		$this->assertSame(1, $this->job->warnFor(consumer: $this->consumer(uuid: 'c-1', expiresAt: '2026-11-29T12:00:00+00:00'), now: $now));
		$this->assertSame(0, $this->job->warnFor(consumer: $this->consumer(uuid: 'c-2', expiresAt: '2026-11-30T12:00:00+00:00'), now: $now));
		$this->assertSame(14, $this->sent[0]['parameters']['daysLeft']);
		$this->assertCount(1, $this->sent);
	}//end testTheWindowIsFourteenDays()

	/**
	 * Nothing is sent for a token already lapsed, one without an end date, or one without a grant.
	 *
	 * @return void
	 */
	public function testLapsedUndatedAndUngrantedTokensAreNotWarned(): void {
		$this->consumers->method('findAll')->willReturn(
			[
				$this->consumer(uuid: 'c-lapsed', expiresAt: '2026-11-10T12:00:00+00:00'),
				$this->consumer(uuid: 'c-undated', expiresAt: null),
				$this->consumer(uuid: 'c-plain', expiresAt: null, withGrant: false),
			]
		);
		$this->notifications->expects($this->never())->method('notify');
		$this->logger->expects($this->once())->method('info')
			->with('[TokenGrantLapseJob] Warned {count} holders of lapsing tokens', ['count' => 0]);

		$this->sweep();
	}//end testLapsedUndatedAndUngrantedTokensAreNotWarned()

	/**
	 * A Consumer without a user, or acting as a user that no longer exists, warns nobody.
	 *
	 * @return void
	 */
	public function testAHolderlessTokenWarnsNobody(): void {
		$now = new DateTimeImmutable(self::NOW);
		$this->notifications->expects($this->never())->method('notify');

		$this->assertSame(0, $this->job->warnFor(consumer: $this->consumer(uuid: 'c-1', expiresAt: '2026-11-20T12:00:00+00:00', userId: null), now: $now));
		$this->assertSame(0, $this->job->warnFor(consumer: $this->consumer(uuid: 'c-2', expiresAt: '2026-11-20T12:00:00+00:00', userId: 'gone'), now: $now));
	}//end testAHolderlessTokenWarnsNobody()

	/**
	 * A failing sweep is logged and never thrown, so the job is not disabled.
	 *
	 * @return void
	 */
	public function testAFailingSweepIsLoggedNotThrown(): void {
		$this->consumers->method('findAll')->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('[TokenGrantLapseJob] Sweep failed: {error}', $this->callback(static fn (array $context): bool => $context['error'] === 'database gone'));

		$this->sweep();

		$this->assertSame([], $this->sent);
	}//end testAFailingSweepIsLoggedNotThrown()
}//end class
