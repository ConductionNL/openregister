<?php

/**
 * A record's payload before and after its anonymisation profile is applied.
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

/**
 * The payload as it is, as it will be, and which properties that touches or keeps.
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */
final class AnonymisedPayload {
	/**
	 * Hold the payload change.
	 *
	 * @param array<string, mixed> $before            The payload as it is now.
	 * @param array<string, mixed> $after             The payload as it will be.
	 * @param string[]             $changedProperties The properties this touches.
	 * @param string[]             $keptProperties    The properties deliberately kept.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function __construct(
		public readonly array $before,
		public readonly array $after,
		public readonly array $changedProperties,
		public readonly array $keptProperties,
	) {
	}//end __construct()
}//end class
