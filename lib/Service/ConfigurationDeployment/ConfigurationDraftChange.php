<?php

/**
 * OpenRegister ConfigurationDraftChange
 *
 * One pending change at an address: a value, or its removal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * One pending change at an address: a value to set, or the removal of the
 * value there.
 *
 * Passed between the staging helpers of {@see ConfigurationDraftService}.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final class ConfigurationDraftChange {

	/**
	 * Name the change.
	 *
	 * @param ConfigurationAddress $address The layer, layer reference and key to draft at.
	 * @param mixed                $value   The pending value (null for a removal).
	 * @param boolean              $removes Whether the draft removes the value.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function __construct(
		public readonly ConfigurationAddress $address,
		public readonly mixed $value,
		public readonly bool $removes,
	) {
	}//end __construct()
}//end class
