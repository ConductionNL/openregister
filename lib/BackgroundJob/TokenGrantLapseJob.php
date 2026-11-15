<?php

/**
 * OpenRegister TokenGrantLapseJob
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
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

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use DateTimeInterface;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapperInterface;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Daily: warns the holder of every Consumer token whose grant ends within the warning window.
 *
 * The holder is the user the Consumer acts as. One notification per token:
 * the previous one for the same Consumer is replaced, so a daily sweep updates
 * the days left rather than stacking a warning per day. A grant without an end
 * date is not warned about here; it is refused at use.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
 */
class TokenGrantLapseJob extends TimedJob {

	/**
	 * The notification subject.
	 *
	 * @var string
	 */
	public const SUBJECT = 'token_grant_lapsing';

	/**
	 * The notification object type; the object id is the Consumer's uuid.
	 *
	 * @var string
	 */
	public const OBJECT_TYPE = 'token_grant';

	/**
	 * Once a day.
	 *
	 * @var int
	 */
	private const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory            $time          Time factory for TimedJob, and the sweep's clock.
	 * @param LoggerInterface         $logger        Diagnostics.
	 * @param ConsumerMapperInterface $consumers     Lists the Consumers whose grants are read.
	 * @param TokenGrantValidator     $validator     Answers whether a grant lapses soon.
	 * @param INotificationManager    $notifications Sends the warning.
	 * @param IUserManager            $users         Confirms the holder still exists.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly ConsumerMapperInterface $consumers,
		private readonly TokenGrantValidator $validator,
		private readonly INotificationManager $notifications,
		private readonly IUserManager $users,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(sensitivity: self::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Warn the holder of every Consumer token about to lapse.
	 *
	 * It never throws: a job that raises is retried and eventually disabled,
	 * and one missed warning is smaller than a sweep that can never run again.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The TimedJob contract; the sweep takes no argument.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	protected function run($argument): void {
		try {
			$now    = $this->time->getDateTime();
			$warned = 0;
			foreach ($this->consumers->findAll() as $consumer) {
				$warned += $this->warnFor(consumer: $consumer, now: $now);
			}

			$this->logger->info('[TokenGrantLapseJob] Warned {count} holders of lapsing tokens', ['count' => $warned]);
		} catch (Throwable $e) {
			$this->logger->warning('[TokenGrantLapseJob] Sweep failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
		}
	}//end run()

	/**
	 * Warn the holder of one Consumer when its grant lapses within the window.
	 *
	 * @param Consumer          $consumer The Consumer.
	 * @param DateTimeInterface $now      The sweep's moment.
	 *
	 * @return int 1 when the holder was warned, else 0.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	public function warnFor(Consumer $consumer, DateTimeInterface $now): int {
		$tokenId = (string)($consumer->getUuid() ?? $consumer->getId());
		$grant   = TokenGrant::fromStored(
			stored: ($consumer->getAuthorizationConfiguration()[TokenGrant::KEY] ?? null),
			tokenId: $tokenId
		);
		if ($grant === null || $grant->expiresAt === null || $this->validator->lapsesSoon(grant: $grant, now: $now) === false) {
			return 0;
		}

		$holder = (string)($consumer->getUserId() ?? '');
		if ($holder === '' || $this->users->get($holder)?->getUID() !== $holder) {
			return 0;
		}

		$notification = $this->notifications->createNotification()
			->setApp('openregister')
			->setUser($holder)
			->setObject(self::OBJECT_TYPE, $tokenId)
			->setSubject(
				self::SUBJECT,
				[
					'token' => (string)($consumer->getName() ?? $tokenId),
					'endsAt' => $grant->expiresAt->format('Y-m-d'),
					'daysLeft' => (int)$grant->daysLeft(now: $now),
				]
			);
		$this->notifications->markProcessed($notification);
		$this->notifications->notify($notification->setDateTime(new DateTime()));

		return 1;
	}//end warnFor()
}//end class
