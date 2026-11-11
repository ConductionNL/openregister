<?php

/**
 * The state of staging one pending value: the set, the change and the live value it replaces.
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
 * One stage of ConfigurationDraftService: built by it and never handed out.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final class DraftStageRun {

	/**
	 * The live value the pending value replaces; read before the pending value is staged.
	 *
	 * @var ConfigurationSnapshot|null
	 */
	public ?ConfigurationSnapshot $live = null;

	/**
	 * Constructor.
	 *
	 * @param string                   $setUuid The set to draft into.
	 * @param ConfigurationDraftChange $change  The address, the pending value and whether it removes.
	 */
	public function __construct(
		public readonly string $setUuid,
		public readonly ConfigurationDraftChange $change,
	) {
	}//end __construct()
}//end class
