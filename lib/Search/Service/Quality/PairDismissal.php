<?php

/**
 * OpenRegister PairDismissal
 *
 * A reviewer's verdict that two objects are not the same.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

/**
 * The compared-values fingerprint, the reason and the reviewer of one dismissal.
 *
 * @spec openspec/specs/duplicate-detection/spec.md
 */
final class PairDismissal {

	/**
	 * Constructor.
	 *
	 * @param string $fingerprint Opaque hash of the compared values, computed by the scorer.
	 * @param string $reason      Why the reviewer decided the two are not the same.
	 * @param string $dismissedBy Acting uid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function __construct(
		public readonly string $fingerprint,
		public readonly string $reason,
		public readonly string $dismissedBy,
	) {
	}//end __construct()

	/**
	 * The verdict as the stored row carries it.
	 *
	 * @return array{fingerprint: string, reason: string, dismissedBy: string}
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md#requirement-a-reviewed-pair-is-recorded-as-not-a-duplicate-and-stops-being-offered-req-dmd-003
	 */
	public function verdict(): array {
		return ['fingerprint' => $this->fingerprint, 'reason' => $this->reason, 'dismissedBy' => $this->dismissedBy];
	}//end verdict()
}//end class
