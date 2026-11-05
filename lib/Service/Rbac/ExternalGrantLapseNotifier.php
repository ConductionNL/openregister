<?php

/**
 * OpenRegister ExternalGrantLapseNotifier
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use DateTime;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

/**
 * Warns the holder of an external grant that it is about to lapse.
 *
 * One notification per grant: the previous one for the same schema, action and
 * principal is replaced, so a daily sweep updates the days left rather than
 * stacking a warning per day.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
class ExternalGrantLapseNotifier {

	/**
	 * The notification subject.
	 *
	 * @var string
	 */
	public const SUBJECT = 'external_grant_lapsing';

	/**
	 * Constructor.
	 *
	 * @param INotificationManager $notifications Sends the warning.
	 * @param IUserManager         $users         Resolves a user principal.
	 * @param IGroupManager        $groups        Resolves a group principal to its members.
	 */
	public function __construct(
		private readonly INotificationManager $notifications,
		private readonly IUserManager $users,
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * Warn every holder of one lapsing grant on a schema.
	 *
	 * @param Schema               $schema The schema the grant is written on.
	 * @param array<string, mixed> $grant  One entry of ExternalGrantGuard::lapsingWithin().
	 *
	 * @return int How many people were warned.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function warn(Schema $schema, array $grant): int {
		$holders = $this->holdersOf(principal: (string)($grant['principal'] ?? ''));
		foreach ($holders as $uid) {
			$notification = $this->notificationFor(schema: $schema, grant: $grant)->setUser($uid);
			$this->notifications->markProcessed($notification);
			$this->notifications->notify($notification->setDateTime(new DateTime()));
		}

		return count($holders);
	}//end warn()

	/**
	 * The users who hold a grant: the user it names, or the members of the group it names.
	 *
	 * @param string $principal The grant's principal.
	 *
	 * @return array<int, string> The user ids.
	 */
	private function holdersOf(string $principal): array {
		if ($principal === '') {
			return [];
		}

		if ($this->users->userExists($principal) === true) {
			return [$principal];
		}

		$group = $this->groups->get($principal);
		if ($group === null) {
			return [];
		}

		return array_values(array_map(static fn ($user): string => $user->getUID(), $group->getUsers()));
	}//end holdersOf()

	/**
	 * The notification for one grant, without its recipient.
	 *
	 * @param Schema               $schema The schema.
	 * @param array<string, mixed> $grant  The grant.
	 *
	 * @return INotification The notification.
	 */
	private function notificationFor(Schema $schema, array $grant): INotification {
		$action = (string)($grant['action'] ?? '');
		return $this->notifications->createNotification()
			->setApp('openregister')
			->setObject('external_grant', $schema->getId().':'.$action.':'.(string)($grant['principal'] ?? ''))
			->setSubject(
				self::SUBJECT,
				[
					'schema' => (string)($schema->getTitle() ?? $schema->getSlug() ?? ''),
					'action' => $action,
					'endsAt' => (string)($grant['endsAt'] ?? ''),
					'daysLeft' => (int)($grant['daysLeft'] ?? 0),
				]
			);
	}//end notificationFor()
}//end class
