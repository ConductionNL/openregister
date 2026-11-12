<?php

/**
 * OpenRegister RevertOptions
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

use DateTime;

/**
 * How far an object is reverted, and what happens to its version.
 */
final class RevertOptions {

	/**
	 * Constructor.
	 *
	 * @param DateTime|int|string|null $until            DateTime or AuditTrail ID to revert to; null for the whole trail.
	 * @param bool                 $overwriteVersion Whether to overwrite the version rather than increment it.
	 */
	public function __construct(
		public readonly DateTime|int|string|null $until = null,
		public readonly bool $overwriteVersion = false,
	) {
	}//end __construct()

	/**
	 * Options for a reversion point as a request hands it over, typed in this strict file.
	 *
	 * A non-strict caller would otherwise coerce a boolean or float point to an id.
	 *
	 * @param mixed $until            DateTime, AuditTrail ID or version to revert to; null for the whole trail.
	 * @param bool  $overwriteVersion Whether to overwrite the version rather than increment it.
	 *
	 * @return self The options.
	 *
	 * @throws \TypeError When the point is not a DateTime, int, string or null.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the constructor's version flag.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public static function forPoint(mixed $until, bool $overwriteVersion=false): self {
		return new self(until: $until, overwriteVersion: $overwriteVersion);
	}//end forPoint()
}//end class
