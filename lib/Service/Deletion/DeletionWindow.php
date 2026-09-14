<?php

/**
 * DeletionWindow — the recovery window of one soft-deleted object.
 *
 * A window that only exists as a computed `purgeDate` inside a JSON blob is
 * not a window: nobody can read it, so nobody knows whether they have a week
 * or an hour. This value object is the readable form, and it is the same
 * shape everywhere it is published: on the object's deletion metadata, in the
 * trash listing and in the refusal a reader gets from the normal object path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use DateTimeImmutable;

/**
 * The published recovery window of a soft-deleted object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class DeletionWindow {
	/**
	 * The window came from the schema's own `archive.deleteRetention`.
	 *
	 * @var string
	 */
	public const SOURCE_SCHEMA = 'schema';

	/**
	 * The window came from the instance setting `objectDeleteRetention`.
	 *
	 * @var string
	 */
	public const SOURCE_INSTANCE = 'instance';

	/**
	 * Neither the schema nor the instance stated one, so the built-in
	 * default applied.
	 *
	 * @var string
	 */
	public const SOURCE_DEFAULT = 'default';

	/**
	 * Build a window.
	 *
	 * @param DateTimeImmutable $destroyableFrom The date the object may be destroyed.
	 * @param int               $daysRemaining   Whole days left, never below zero.
	 * @param int               $retentionDays   The stated retention in days.
	 * @param string            $source          Which rule produced the retention.
	 * @param DateTimeImmutable $deletedAt       When the object was soft-deleted.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DateTimeImmutable $destroyableFrom,
		private readonly int $daysRemaining,
		private readonly int $retentionDays,
		private readonly string $source,
		private readonly DateTimeImmutable $deletedAt,
	) {
	}//end __construct()

	/**
	 * The date from which the object may be destroyed.
	 *
	 * @return DateTimeImmutable The destroyable-from date.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function destroyableFrom(): DateTimeImmutable {
		return $this->destroyableFrom;
	}//end destroyableFrom()

	/**
	 * Whole days left before the object may be destroyed.
	 *
	 * @return int Days remaining, zero once the window has lapsed.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function daysRemaining(): int {
		return $this->daysRemaining;
	}//end daysRemaining()

	/**
	 * The stated retention in days.
	 *
	 * @return int Retention in days.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function retentionDays(): int {
		return $this->retentionDays;
	}//end retentionDays()

	/**
	 * Which rule produced the retention: the schema, the instance or the
	 * built-in default.
	 *
	 * @return string One of the SOURCE_* constants.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function source(): string {
		return $this->source;
	}//end source()

	/**
	 * Whether the window has lapsed, so destruction is now permitted.
	 *
	 * @return bool True once the destroyable-from date has passed.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function hasLapsed(): bool {
		return $this->daysRemaining === 0;
	}//end hasLapsed()

	/**
	 * The window as the API publishes it.
	 *
	 * @return array<string, mixed> The published shape.
	 *
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function toArray(): array {
		return [
			'deletedAt' => $this->deletedAt->format(DATE_ATOM),
			'destroyableFrom' => $this->destroyableFrom->format(DATE_ATOM),
			'daysRemaining' => $this->daysRemaining,
			'retentionDays' => $this->retentionDays,
			'retentionSource' => $this->source,
			'lapsed' => $this->hasLapsed(),
		];
	}//end toArray()
}//end class
