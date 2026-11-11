<?php

/**
 * OpenRegister AppHost: one published manifest of one application.
 *
 * What a publish hands to the schedule sync: the application object, the manifest
 * now in production, and the signed-in user who published it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Value
 * @package  OCA\OpenRegister\AppHost\Scheduling
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Scheduling;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * An application, its production manifest and who published it.
 *
 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
 */
final class ScheduleRelease {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $application The application object.
	 * @param array<string, mixed> $manifest The production manifest; empty when nothing is in production.
	 * @param string|null $publisher The signed-in user, or null when nobody published.
	 * @param string|null $refusal Why this publish cannot be applied, when it is known before applying.
	 */
	public function __construct(
		public readonly ObjectEntity $application,
		public readonly array $manifest,
		public readonly ?string $publisher,
		public readonly ?string $refusal = null,
	) {
	}//end __construct()

	/**
	 * The application's uuid, which keys its compiled flows.
	 *
	 * Not its slug: that is a data property, and two organisations may use the same one.
	 *
	 * @return string The object uuid.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function applicationUuid(): string {
		return (string)$this->application->getUuid();
	}//end applicationUuid()

	/**
	 * The name people recognise the application by.
	 *
	 * @return string The slug, or the object uuid when it carries none.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function slug(): string {
		$slug = ($this->application->getObject()['slug'] ?? null);
		if (is_string($slug) === true && $slug !== '') {
			return $slug;
		}

		return $this->applicationUuid();
	}//end slug()

	/**
	 * The organisation a compiled flow belongs to.
	 *
	 * @return string|null The application's organisation uuid, or null when it has none.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function organisation(): ?string {
		$organisation = trim((string)$this->application->getOrganisation());
		if ($organisation === '') {
			return null;
		}

		return $organisation;
	}//end organisation()

	/**
	 * The flow uuid of a schedule: the same application and id always give the same row.
	 *
	 * @param string $scheduleId The schedule id.
	 *
	 * @return string A name-based uuid.
	 *
	 * @spec openspec/changes/apphost-schedule-flow-action/specs/apphost-scheduling/spec.md
	 */
	public function flowUuid(string $scheduleId): string {
		// NOSEMGREP: php.lang.security.weak-crypto.weak-crypto -- The hash only derives a stable identifier, not a security value.
		$hash = md5('apphost-schedule:' . $this->applicationUuid() . ':' . $scheduleId);
		$variant = dechex((hexdec($hash[16]) & 0x3) | 0x8);

		return sprintf(
			'%s-%s-3%s-%s%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 13, 3),
			$variant,
			substr($hash, 17, 3),
			substr($hash, 20, 12)
		);
	}//end flowUuid()
}//end class
