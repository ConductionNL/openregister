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
use OCA\OpenRegister\Kernel\Contracts\ConsumerGrantsInterface;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantStore;
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
	 * @param ConsumerGrantsInterface $consumers     Lists the Consumers whose grants are read.
	 * @param TokenGrantValidator     $validator     Answers whether a grant lapses soon.
	 * @param INotificationManager    $notifications Sends the warning.
	 * @param IUserManager            $users         Confirms the holder still exists.
	 * @param PersonalTokenGrantStore|null $personalGrants The grants on personal tokens, when wired.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly ConsumerGrantsInterface $consumers,
		private readonly TokenGrantValidator $validator,
		private readonly INotificationManager $notifications,
		private readonly IUserManager $users,
		private readonly ?PersonalTokenGrantStore $personalGrants = null,
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
			foreach ($this->consumers->consumerGrants() as $consumer) {
				$warned += $this->warnFor(consumer: $consumer, now: $now);
			}

			foreach (($this->personalGrants?->all() ?? []) as $token) {
				$warned += $this->warnForPersonal(uid: $token['uid'], tokenId: $token['tokenId'], now: $now);
			}

			$this->logger->info('[TokenGrantLapseJob] Warned {count} holders of lapsing tokens', ['count' => $warned]);
		} catch (Throwable $e) {
			$this->logger->warning('[TokenGrantLapseJob] Sweep failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
		}
	}//end run()

	/**
	 * Warn the holder of one Consumer when its grant lapses within the window.
	 *
	 * @param array{tokenId: string, holder: string, name: string, stored: mixed} $consumer The Consumer, as ConsumerGrantsInterface lists it.
	 * @param DateTimeInterface                                                   $now      The sweep's moment.
	 *
	 * @return int 1 when the holder was warned, else 0.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	public function warnFor(array $consumer, DateTimeInterface $now): int {
		$grant = TokenGrant::fromStored(stored: $consumer['stored'], tokenId: $consumer['tokenId']);

		return $this->notifyIfLapsing(
			grant: $grant,
			holder: $consumer['holder'],
			name: $consumer['name'],
			now: $now
		);
	}//end warnFor()

	/**
	 * Warn the owner of one personal token when its grant lapses within the window.
	 *
	 * @param string            $uid     The token's owner.
	 * @param string            $tokenId The token id.
	 * @param DateTimeInterface $now     The sweep's moment.
	 *
	 * @return int 1 when the owner was warned, else 0.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	public function warnForPersonal(string $uid, string $tokenId, DateTimeInterface $now): int {
		$grant = $this->personalGrants?->grantFor(uid: $uid, tokenId: $tokenId);
		if ($grant === null) {
			return 0;
		}

		return $this->notifyIfLapsing(grant: $grant, holder: $uid, name: $grant->tokenId, now: $now);
	}//end warnForPersonal()

	/**
	 * Notify the holder when the grant lapses within the warning window.
	 *
	 * The notification's object is the grant's token id, so a new warning
	 * replaces the previous one for the same token instead of stacking.
	 *
	 * @param TokenGrant|null   $grant  The grant, or null for none.
	 * @param string            $holder The user to warn.
	 * @param string            $name   The token's name in the warning.
	 * @param DateTimeInterface $now    The sweep's moment.
	 *
	 * @return int 1 when the holder was warned, else 0.
	 */
	private function notifyIfLapsing(?TokenGrant $grant, string $holder, string $name, DateTimeInterface $now): int {
		if ($grant === null || $grant->expiresAt === null || $this->validator->lapsesSoon(grant: $grant, now: $now) === false) {
			return 0;
		}

		if ($holder === '' || $this->users->get($holder)?->getUID() !== $holder) {
			return 0;
		}

		$notification = $this->notifications->createNotification()
			->setApp('openregister')
			->setUser($holder)
			->setObject(self::OBJECT_TYPE, $grant->tokenId)
			->setSubject(
				self::SUBJECT,
				[
					'token' => $name,
					'endsAt' => $grant->expiresAt->format('Y-m-d'),
					'daysLeft' => (int)$grant->daysLeft(now: $now),
				]
			);
		$this->notifications->markProcessed($notification);
		$this->notifications->notify($notification->setDateTime(new DateTime()));

		return 1;
	}//end notifyIfLapsing()
}//end class
