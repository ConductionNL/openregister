<?php

/**
 * OpenRegister SlugBackfill
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

/**
 * One slug backfill run: the slugs it wrote, its counters, and where it ran.
 *
 * The operation object of AuditTrailMapper::recordSlugBackfill().
 */
final class SlugBackfill {

	/**
	 * Constructor.
	 *
	 * @param list<array{uuid: string, old: ?string, new: ?string}> $changes Every slug the run wrote.
	 * @param array<string, mixed>                                   $summary The run's counters.
	 * @param AuditedObject                                          $target  The register and schema, when known.
	 */
	public function __construct(
		public readonly array $changes,
		public readonly array $summary,
		public readonly AuditedObject $target = new AuditedObject(),
	) {
	}//end __construct()
}//end class
