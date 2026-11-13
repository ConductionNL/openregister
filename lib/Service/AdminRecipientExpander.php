<?php

/**
 * OpenRegister admin recipient expander.
 *
 * Expands the `@admins` recipient token of a scheduled report to the e-mail
 * addresses of the members of Nextcloud's `admin` group, at run time, so an
 * administrator added yesterday gets today's mail without anybody editing the
 * report. Members without an address are skipped, and the list keeps the cap
 * every scheduled report has; both are named so the run record can say so.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-report-can-go-to-all-administrators
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IGroupManager;

/**
 * Turn configured recipients, `@admins` included, into addresses.
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-report-can-go-to-all-administrators
 */
class AdminRecipientExpander {

	/**
	 * The recipient token for every administrator.
	 */
	public const TOKEN = '@admins';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groups The groups.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-report-can-go-to-all-administrators
	 */
	public function __construct(
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * The addresses for a recipient list, with what was left out.
	 *
	 * @param array<int, string> $configured The configured recipients.
	 * @param int                $cap        The most addresses a mail goes to.
	 *
	 * @return array{recipients: array<string, string>, notes: array<int, string>} Address => name, and notes.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-report-can-go-to-all-administrators
	 */
	public function expand(array $configured, int $cap): array {
		$recipients = [];
		$notes = [];
		foreach ($configured as $entry) {
			if ($entry !== self::TOKEN) {
				$recipients[$entry] = $entry;
				continue;
			}

			$admins = $this->admins();
			$recipients = array_merge($recipients, $admins['recipients']);
			if ($admins['skipped'] !== []) {
				$notes[] = 'Administrators without an e-mail address were skipped: ' . implode(', ', $admins['skipped']) . '.';
			}
		}

		if (count($recipients) > $cap) {
			$notes[] = sprintf('%d recipients were left out, over the cap of %d.', (count($recipients) - $cap), $cap);
			$recipients = array_slice($recipients, 0, $cap, true);
		}

		return ['recipients' => $recipients, 'notes' => $notes];
	}//end expand()

	/**
	 * The admin group's members with an address, by user id, and those without one.
	 *
	 * @return array{recipients: array<string, string>, skipped: array<int, string>} The members.
	 */
	private function admins(): array {
		$group = $this->groups->get('admin');
		if ($group === null) {
			return ['recipients' => [], 'skipped' => []];
		}

		$users = $group->getUsers();
		usort($users, static fn ($a, $b): int => strcmp($a->getUID(), $b->getUID()));

		$recipients = [];
		$skipped = [];
		foreach ($users as $user) {
			$address = trim((string)($user->getEMailAddress() ?? ''));
			if ($address === '') {
				$skipped[] = $user->getUID();
				continue;
			}

			$recipients[$address] = $user->getDisplayName();
		}

		return ['recipients' => $recipients, 'skipped' => $skipped];
	}//end admins()
}//end class
