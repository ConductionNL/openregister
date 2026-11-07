<?php

/**
 * RiskLevelServiceInterface: what callers outside the Files component use of RiskLevelService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service
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

namespace OCA\OpenRegister\Files\Service;

/**
 * Registers the file risk-level metadata key.
 */
interface RiskLevelServiceInterface {

	/**
	 * Register the metadata key with Nextcloud's files metadata system.
	 *
	 * Must be called from a repair step, not during app boot.
	 *
	 * @return void
	 */
	public function initMetadataKey(): void;
}//end interface
