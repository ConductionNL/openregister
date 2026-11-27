<?php

/**
 * Emergency self-granted access: taken with a reason, bounded, announced, audited.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail\ActionLookupOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditRecordInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Takes a break-glass grant on one record, and answers whether one holds.
 *
 * ONE EVALUATOR (design D-6). This class never decides access on its own: the
 * permission handler asks {@see grants()} as one more input after the deny pass,
 * so a deny still beats an emergency grant and nothing here reopens a record
 * the rules closed for another reason.
 *
 * THE GRANT IS A SEALED TRAIL ROW. {@see take()} writes one hash-chained audit
 * entry carrying the grantee, the verbs, the period and the reason; that row is
 * what {@see grants()} reads back. It is never written onto the record, so no
 * other reader's access moves, and it cannot be edited afterwards (design D-4).
 *
 * EVERY USE IS AUDITED AS MADE UNDER IT. Each time the grant is what admits a
 * request, a `break_glass.used` row names the grantee, the verb and the take it
 * relied on, once per verb per take per request.
 *
 * FAIL CLOSED. A trail that cannot be read holds no grant; a malformed
 * declaration offers none; an anonymous caller can take none.
 *
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
 */
class BreakGlassService {

	/**
	 * The trail action of a take.
	 *
	 * @var string
	 */
	public const TAKEN_ACTION = 'break_glass.taken';

	/**
	 * The trail action of a use.
	 *
	 * @var string
	 */
	public const USED_ACTION = 'break_glass.used';

	/**
	 * The notification subject of a take.
	 *
	 * @var string
	 */
	public const NOTIFICATION_SUBJECT = 'break_glass_taken';

	/**
	 * The app config key of the administered limit on one run of takes (ISO-8601 duration).
	 *
	 * @var string
	 */
	public const LIMIT_CONFIG_KEY = 'break_glass_run_limit';

	/**
	 * How many recent takes of one record are read back.
	 *
	 * @var integer
	 */
	private const TAKES_READ = 50;

	/**
	 * Uses already recorded in this request, keyed by record, grantee, verb and take.
	 *
	 * @var array<string, true>
	 */
	private array $recordedUses = [];

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $trail         The chained trail the takes and uses are sealed in.
	 * @param IUserSession              $userSession   Who is asking.
	 * @param IGroupManager             $groupManager  The caller's groups, and the recipients' members.
	 * @param INotificationManager      $notifications Tells the declared recipients.
	 * @param IAppConfig                $appConfig     The administered limit.
	 * @param ITimeFactory              $clock         Now.
	 * @param LoggerInterface           $logger        Says why something answered no.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $trail,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly INotificationManager $notifications,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $clock,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Take emergency access on one record, for the reason given.
	 *
	 * @param ObjectEntity $object The record.
	 * @param Schema       $schema Its schema, which must declare break glass.
	 * @param mixed        $reason Why; required.
	 *
	 * @return array<string, mixed> The grant: grantee, verbs, takenAt, until, chainStart, reason.
	 *
	 * @throws NotAuthorizedException When the schema declares none, the caller may not take it, or the run is at its limit.
	 * @throws InvalidArgumentException When no reason is given.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public function take(ObjectEntity $object, Schema $schema, mixed $reason): array {
		$declaration = BreakGlassDeclaration::fromSchema(schema: $schema);
		if ($declaration === null) {
			throw new NotAuthorizedException(
				message: 'Schema "' . ((string)($schema->getSlug() ?? $schema->getId())) . '" does not offer emergency access'
			);
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotAuthorizedException(message: 'Emergency access is taken by a signed-in user');
		}

		if (is_string($reason) === false || trim($reason) === '') {
			throw new InvalidArgumentException('A reason is required to take emergency access');
		}

		if ($declaration->mayBeTakenBy(callerGroups: $this->groupManager->getUserGroupIds($user)) === false) {
			throw new NotAuthorizedException(message: 'You may not take emergency access on this schema');
		}

		$grantee = $user->getUID();
		$take = (new BreakGlassPolicy())->take(
			declaration: $declaration,
			grantee: $grantee,
			priorTakes: $this->takesOn(objectUuid: (string)$object->getUuid()),
			now: $this->clock->getTime(),
			limitSeconds: $this->limitSeconds()
		);

		$context = $take->toContext(reason: trim($reason));
		$this->trail->createAuditTrailEntry(object: $object, action: self::TAKEN_ACTION, context: $context);
		$this->announce(declaration: $declaration, object: $object, context: $context);

		return $context;
	}//end take()

	/**
	 * Whether a break-glass grant admits this user to this verb on this record now.
	 *
	 * Records the use on the chained trail when it does (task 4.5).
	 *
	 * @param string|null  $userId The caller.
	 * @param ObjectEntity $object The record.
	 * @param Schema|null  $schema Its schema.
	 * @param string       $verb   The verb asked for.
	 *
	 * @return bool True only when the schema declares the verb and a take of this user holds now.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public function grants(?string $userId, ObjectEntity $object, ?Schema $schema, string $verb): bool {
		if ($userId === null || $userId === '' || in_array($verb, BreakGlassDeclaration::REFUSED_VERBS, true) === true) {
			return false;
		}

		// The declaration as it stands NOW bounds the grant: narrowing or
		// removing it takes effect on every take still running.
		$declaration = BreakGlassDeclaration::fromSchema(schema: $schema);
		if ($declaration === null || $declaration->grants(verb: $verb) === false) {
			return false;
		}

		$uuid = (string)$object->getUuid();
		if ($uuid === '') {
			return false;
		}

		$now = $this->clock->getTime();
		$current = (new BreakGlassPolicy())->currentRun(priorTakes: $this->takesOn(objectUuid: $uuid), grantee: $userId, now: $now);
		if ($current === null || $current->grantsAt(verb: $verb, now: $now) === false) {
			return false;
		}

		$this->recordUse(object: $object, take: $current, verb: $verb);

		return true;
	}//end grants()

	/**
	 * The takes recorded on one record, newest first; none when the trail cannot be read.
	 *
	 * @param string $objectUuid The record.
	 *
	 * @return array<int, BreakGlassTake> The takes.
	 *
	 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md#requirement-emergency-self-granted-access-is-declared-bounded-and-announced-req-gsr-004
	 */
	public function takesOn(string $objectUuid): array {
		try {
			$rows = $this->trail->findObjectActions(
				objectUuid: $objectUuid,
				options: new ActionLookupOptions(actions: [self::TAKEN_ACTION], limit: self::TAKES_READ)
			);
		} catch (Throwable $e) {
			// Fail CLOSED: a take that cannot be read admits nobody.
			$this->logger->warning(
				message: '[BreakGlassService] Could not read the emergency takes; none holds',
				context: ['file' => __FILE__, 'line' => __LINE__, 'objectUuid' => $objectUuid, 'error' => $e->getMessage()]
			);
			return [];
		}

		$takes = [];
		foreach ($rows as $row) {
			$take = $this->takeOf(row: $row);
			if ($take !== null) {
				$takes[] = $take;
			}
		}

		return $takes;
	}//end takesOn()

	/**
	 * The take one trail row holds, only when the row's actor is its grantee.
	 *
	 * The actor check means a row naming somebody else as grantee, however it
	 * got there, grants that somebody nothing.
	 *
	 * @param mixed $row One trail row.
	 *
	 * @return BreakGlassTake|null The take.
	 */
	private function takeOf(mixed $row): ?BreakGlassTake {
		if (($row instanceof AuditRecordInterface) === false || $row->getAction() !== self::TAKEN_ACTION) {
			return null;
		}

		$take = BreakGlassTake::fromContext(context: $row->getChanged());
		if ($take === null || $take->grantee !== $row->getUser()) {
			return null;
		}

		return $take;
	}//end takeOf()

	/**
	 * Record a use of the grant on the chained trail, once per verb per take per request.
	 *
	 * @param ObjectEntity   $object The record.
	 * @param BreakGlassTake $take   The take relied on.
	 * @param string         $verb   The verb used.
	 *
	 * @return void
	 */
	private function recordUse(ObjectEntity $object, BreakGlassTake $take, string $verb): void {
		$key = $object->getUuid() . '|' . $take->grantee . '|' . $verb . '|' . $take->takenAt;
		if (isset($this->recordedUses[$key]) === true) {
			return;
		}

		$this->recordedUses[$key] = true;
		$this->trail->createAuditTrailEntry(
			object: $object,
			action: self::USED_ACTION,
			context: [
				'grantee' => $take->grantee,
				'verb' => $verb,
				'takenAt' => BreakGlassTake::moment(timestamp: $take->takenAt),
				'until' => BreakGlassTake::moment(timestamp: $take->until),
			],
			actorId: $take->grantee
		);
	}//end recordUse()

	/**
	 * The administered limit on one run, in seconds; null when none is administered or it is malformed.
	 *
	 * @return int|null The limit.
	 */
	private function limitSeconds(): ?int {
		try {
			$configured = $this->appConfig->getValueString(AppIdentity::APP_ID, self::LIMIT_CONFIG_KEY, '');
		} catch (Throwable $e) {
			return null;
		}

		return BreakGlassDeclaration::durationSecondsOf(value: $configured);
	}//end limitSeconds()

	/**
	 * Tell every declared recipient at this moment.
	 *
	 * A recipient who cannot be told is logged, not fatal: the take is already
	 * sealed on the trail, and refusing the help at the counter because a
	 * notification failed would punish the person being helped.
	 *
	 * @param BreakGlassDeclaration $declaration The declaration naming the recipients.
	 * @param ObjectEntity          $object      The record.
	 * @param array<string, mixed>  $context     The take.
	 *
	 * @return void
	 */
	private function announce(BreakGlassDeclaration $declaration, ObjectEntity $object, array $context): void {
		foreach ($this->recipients(declaration: $declaration, grantee: (string)$context['grantee']) as $recipient) {
			try {
				$notification = $this->notifications->createNotification();
				$notification->setApp(AppIdentity::APP_ID);
				$notification->setUser($recipient);
				$notification->setDateTime(new DateTime('@' . $this->clock->getTime()));
				$notification->setObject('object', (string)$object->getUuid());
				$notification->setSubject(
					self::NOTIFICATION_SUBJECT,
					[
						'objectTitle' => ($object->getName() ?? (string)$object->getUuid()),
						'objectUuid' => (string)$object->getUuid(),
						'grantee' => $context['grantee'],
						'verbs' => implode(', ', $context['verbs']),
						'until' => $context['until'],
						'reason' => $context['reason'],
					]
				);
				$this->notifications->notify($notification);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[BreakGlassService] Could not tell a recipient about emergency access',
					context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $object->getUuid(), 'error' => $e->getMessage()]
				);
			}//end try
		}//end foreach
	}//end announce()

	/**
	 * The declared users and the members of the declared groups, each once.
	 *
	 * The grantee is told too when declared: the notice is the record that the
	 * grant was taken, and somebody taking it on their own team is still news.
	 *
	 * @param BreakGlassDeclaration $declaration The declaration.
	 * @param string                $grantee     Who took it.
	 *
	 * @return array<int, string> The recipients' uids.
	 */
	private function recipients(BreakGlassDeclaration $declaration, string $grantee): array {
		$recipients = $declaration->notifyUsers;
		foreach ($declaration->notifyGroups as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group === null) {
				$this->logger->warning(
					message: '[BreakGlassService] A declared recipient group does not exist',
					context: ['file' => __FILE__, 'line' => __LINE__, 'group' => $groupId, 'grantee' => $grantee]
				);
				continue;
			}

			foreach ($group->getUsers() as $member) {
				$recipients[] = $member->getUID();
			}
		}

		return array_values(array_unique($recipients));
	}//end recipients()
}//end class
