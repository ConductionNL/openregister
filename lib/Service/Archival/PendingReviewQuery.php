<?php

/**
 * Which entries of a destruction list still wait on a reviewer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTimeInterface;

/**
 * The list asked about, the reviewer whose entries count, and how long an entry must have waited.
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */
final class PendingReviewQuery {
	/**
	 * Hold the values.
	 *
	 * @param string                 $listUuid     The uuid of the list, carried onto each entry.
	 * @param string|null            $reviewer     Only this reviewer's entries, or every assigned entry when null.
	 * @param DateTimeInterface|null $waitingSince Only entries assigned at or before this moment.
	 */
	public function __construct(
		public readonly string $listUuid,
		public readonly ?string $reviewer,
		public readonly ?DateTimeInterface $waitingSince,
	) {
	}//end __construct()
}//end class
