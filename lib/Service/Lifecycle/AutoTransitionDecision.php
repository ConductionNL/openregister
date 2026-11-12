<?php

/**
 * OpenRegister AutoTransitionDecision
 *
 * One decided automatic move, bound to the object it was decided for and to
 * the object's state at the moment of the decision.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

use DateTimeInterface;

/**
 * A candidate plus the object identity and version it was decided against.
 *
 * The version and `updated` stamp are what makes a QUEUED move safe: the job
 * applies it only while they still match, because a newer write has made its
 * own decision and this one is stale.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class AutoTransitionDecision {

	/**
	 * The object's uuid.
	 *
	 * @var string
	 */
	public readonly string $uuid;

	/**
	 * The object's register reference, as recorded off the event.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The object's schema reference, as recorded off the event.
	 *
	 * @var string
	 */
	public readonly string $schema;

	/**
	 * The schema's slug, for log lines.
	 *
	 * @var string
	 */
	public readonly string $schemaSlug;

	/**
	 * The move to make.
	 *
	 * @var AutoTransitionCandidate
	 */
	public readonly AutoTransitionCandidate $candidate;

	/**
	 * The object's version at decision time, or null when it carries none.
	 *
	 * @var string|null
	 */
	public readonly ?string $version;

	/**
	 * The object's `updated` stamp at decision time, ISO 8601, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $updated;

	/**
	 * Capture the decision.
	 *
	 * @param AutoTransitionSubject   $subject   The object the move was decided for.
	 * @param AutoTransitionCandidate $candidate The move to make.
	 * @param string|null             $version   The object's version at decision time.
	 * @param mixed                   $updated   The object's `updated` stamp at decision time, as the
	 *                                           entity holds it. Normalised here rather than by the
	 *                                           caller: this is the only class that stores the value,
	 *                                           so it is the only one that needs to know the entity
	 *                                           may hand back a date object, a string, or nothing.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		AutoTransitionSubject $subject,
		AutoTransitionCandidate $candidate,
		?string $version,
		mixed $updated,
	) {
		$this->uuid = $subject->uuid;
		$this->register = $subject->register;
		$this->schema = $subject->schema;
		$this->schemaSlug = $subject->schemaSlug;
		$this->candidate = $candidate;
		$this->version = $version;
		$this->updated = self::normaliseUpdated(updated: $updated);
	}//end __construct()

	/**
	 * The `updated` stamp as ISO 8601, or null when the entity holds no string or date.
	 *
	 * @param mixed $updated The stamp as the entity holds it.
	 *
	 * @return string|null The normalised stamp.
	 */
	private static function normaliseUpdated(mixed $updated): ?string {
		if ($updated instanceof DateTimeInterface === true) {
			return $updated->format(DATE_ATOM);
		}

		if (is_string($updated) === false) {
			return null;
		}

		return $updated;
	}//end normaliseUpdated()
}//end class
