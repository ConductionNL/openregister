<?php

/**
 * ProcessingMarker — an art-18 restriction or art-21 objection marker to set.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

/**
 * Which processing marker is set, whether it is active, and why.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class ProcessingMarker {
	/**
	 * Hold the marker.
	 *
	 * @param string $op     Operation label: `restrict` (art-18) or `object` (art-21).
	 * @param bool   $active Whether the marker is active.
	 * @param string $reason Reason recorded on the marker.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function __construct(
		public readonly string $op,
		public readonly bool $active,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
