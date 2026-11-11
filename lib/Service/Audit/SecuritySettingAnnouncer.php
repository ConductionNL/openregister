<?php

/**
 * Tells the administrators when a security-relevant setting moves.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use DateTime;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Announcing, which is not recording (D-6).
 *
 * The record of a settings change belongs to `settings-change-audit`. This
 * class does the other thing Redmine does: it tells a person at the moment it
 * happens. A small beheerteam does not read a trail every morning, and a
 * switched-off access check is the change they need to hear about that day.
 *
 * Only marked settings are announced. Announcing every setting is the mailbox
 * full of everything that gets filtered to a folder nobody opens, which is the
 * same as announcing nothing.
 *
 * ⚠️ A SECRET NEVER ENTERS THE NOTIFICATION, NOT EVEN ITS PARAMETERS.
 * Nextcloud stores notification parameters in its database and the
 * notifications app can mail them. Masking at render time would still leave
 * the credential in a table and a mailbox, so for a secret the old and new
 * values are simply never handed over.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
class SecuritySettingAnnouncer {
	/**
	 * The notification subject the Notifier renders.
	 *
	 * @var string
	 */
	public const SUBJECT = 'security_setting_changed';

	/**
	 * The group that is told.
	 *
	 * @var string
	 */
	public const ADMIN_GROUP = 'admin';

	/**
	 * Constructor.
	 *
	 * @param SecuritySettingRegistry $registry      The marker and the snapshot.
	 * @param AnnouncementRecipients $recipients The notification manager and the administrators it notifies.
	 * @param IUserSession            $userSession   Names the actor.
	 * @param LoggerInterface         $logger        Reports a delivery failure.
	 */
	public function __construct(
		private readonly SecuritySettingRegistry $registry,
		private readonly AnnouncementRecipients $recipients,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The marked settings as they stand now, for comparing after a save.
	 *
	 * @return array<string, mixed> Path to value.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function snapshot(): array {
		try {
			return $this->registry->snapshot();
		} catch (Throwable $unreadable) {
			return [];
		}
	}//end snapshot()

	/**
	 * Announce every marked setting that differs between two snapshots.
	 *
	 * Fail-soft. The setting is already saved by the time this runs, and a
	 * notification that could not be delivered must not turn a successful save
	 * into an error the administrator retries.
	 *
	 * @param array<string, mixed> $before The snapshot taken before the save.
	 * @param array<string, mixed> $after  The snapshot taken after it.
	 *
	 * @return int The number of notifications delivered.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function announce(array $before, array $after): int {
		$changes = $this->changes(before: $before, after: $after);
		if ($changes === []) {
			return 0;
		}

		$recipients = $this->administratorsToTell();
		if ($recipients === null) {
			return 0;
		}

		return $this->announceTo(changes: $changes, recipients: $recipients);
	}//end announce()

	/**
	 * The administrators to tell, or null (logged) when they cannot be found.
	 *
	 * @return array<int, string>|null The administrator uids.
	 */
	private function administratorsToTell(): ?array {
		try {
			return $this->administrators();
		} catch (Throwable $lookupFailed) {
			$this->logger->warning(
				message: '[SecuritySettingAnnouncer] Could not find the administrators to tell: '
					. $lookupFailed->getMessage(),
				context: ['app' => 'openregister']
			);

			return null;
		}
	}//end administratorsToTell()

	/**
	 * Tell every administrator about every changed setting.
	 *
	 * @param array<string, array{old: mixed, new: mixed}> $changes    The changed settings, by path.
	 * @param array<int, string>                           $recipients The administrator uids.
	 *
	 * @return int The notifications delivered.
	 */
	private function announceTo(array $changes, array $recipients): int {
		$actor = $this->actor();
		$sent  = 0;
		foreach ($changes as $path => $change) {
			$setting    = new SettingChange(path: (string)$path, old: $change['old'], new: $change['new']);
			$parameters = $this->parameters(change: $setting, actor: $actor);
			foreach ($recipients as $uid) {
				$sent += $this->deliver(uid: $uid, parameters: $parameters);
			}
		}

		return $sent;
	}//end announceTo()

	/**
	 * The marked settings whose value moved.
	 *
	 * Compared as their string form, because a JSON round trip can turn `true`
	 * into `1` and a stored `"30"` into `30`, and neither is a change anybody
	 * made.
	 *
	 * @param array<string, mixed> $before The snapshot taken before the save.
	 * @param array<string, mixed> $after  The snapshot taken after it.
	 *
	 * @return array<string, array{old: mixed, new: mixed}> Path to change.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function changes(array $before, array $after): array {
		$changes = [];
		foreach ($after as $path => $new) {
			if ($this->registry->isSecurityRelevant(path: (string)$path) === false) {
				continue;
			}

			if (array_key_exists($path, $before) === false) {
				continue;
			}

			$old = $before[$path];
			if (self::render(value: $old) === self::render(value: $new)) {
				continue;
			}

			$changes[(string)$path] = ['old' => $old, 'new' => $new];
		}

		return $changes;
	}//end changes()

	/**
	 * The notification parameters for one change.
	 *
	 * @param SettingChange $change The setting path and its old and new value.
	 * @param string        $actor  Who made the change.
	 *
	 * @return array<string, string|bool> The parameters, with no values for a secret.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function parameters(SettingChange $change, string $actor): array {
		$parameters = [
			'setting' => $change->path,
			'label' => $this->registry->label(path: $change->path),
			'actor' => $actor,
			'secret' => $this->registry->isSecret(path: $change->path),
		];

		if ($parameters['secret'] === true) {
			return $parameters;
		}

		$parameters['oldValue'] = self::render(value: $change->old);
		$parameters['newValue'] = self::render(value: $change->new);

		return $parameters;
	}//end parameters()

	/**
	 * A value as the announcement shows it.
	 *
	 * @param mixed $value The setting value.
	 *
	 * @return string The display form.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public static function render(mixed $value): string {
		if (is_bool($value) === true) {
			if ($value === true) {
				return 'on';
			}

			return 'off';
		}

		if ($value === null) {
			return '';
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return (string)json_encode($value);
	}//end render()

	/**
	 * The uids of everybody in the admin group.
	 *
	 * @return string[] The recipients.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function administrators(): array {
		$group = $this->recipients->groupManager->get(self::ADMIN_GROUP);
		if ($group === null) {
			return [];
		}

		$uids = [];
		foreach ($group->getUsers() as $user) {
			$uids[] = $user->getUID();
		}

		return $uids;
	}//end administrators()

	/**
	 * Who made the change, as the announcement names them.
	 *
	 * @return string The display name, or `system` for a change with no session.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function actor(): string {
		try {
			$user = $this->userSession->getUser();
		} catch (Throwable $sessionUnavailable) {
			return 'system';
		}

		if ($user === null) {
			return 'system';
		}

		$name = trim($user->getDisplayName());
		if ($name === '') {
			return $user->getUID();
		}

		return $name;
	}//end actor()

	/**
	 * Deliver one notification.
	 *
	 * @param string                     $uid        The recipient.
	 * @param array<string, string|bool> $parameters The subject parameters; their `setting` path is the object id.
	 *
	 * @return int 1 when delivered, 0 when not.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function deliver(string $uid, array $parameters): int {
		$path = (string)$parameters['setting'];
		try {
			$notification = $this->recipients->notifications->createNotification();
			$notification->setApp('openregister')
				->setUser($uid)
				->setDateTime(new DateTime())
				->setObject('security_setting', $path)
				->setSubject(self::SUBJECT, $parameters);
			$this->recipients->notifications->notify($notification);
		} catch (Throwable $deliveryFailed) {
			$this->logger->warning(
				message: '[SecuritySettingAnnouncer] Could not announce a security setting change: '
					. $deliveryFailed->getMessage(),
				context: ['app' => 'openregister', 'setting' => $path]
			);

			return 0;
		}

		return 1;
	}//end deliver()
}//end class
