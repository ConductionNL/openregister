<?php

/**
 * ProcessingFlag — whether an art-18 restriction or art-21 objection is set, and why.
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
 * The data subject's choice on one processing marker: set or cleared, and the
 * reason recorded with it. Which marker it is (restriction or objection) is
 * named by the method it is handed to.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class ProcessingFlag {
	/**
	 * Hold the choice.
	 *
	 * @param bool   $active Whether the marker is set.
	 * @param string $reason Reason recorded on the marker.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function __construct(
		public readonly bool $active,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
