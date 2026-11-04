<?php

/**
 * OpenRegister retention domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Archival\DestructionService;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;

/**
 * The domain services of the retention API.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class RetentionDomain {
	/**
	 * Bundle the services.
	 *
	 * @param RetentionService       $retentionService   Retention service
	 * @param ObjectRetentionHandler $settingsHandler    Settings handler
	 * @param SaveObject             $saveObject         Save object service
	 * @param DestructionService     $destructionService Withholds entries a reviewer decided against
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function __construct(
		public readonly RetentionService $retentionService,
		public readonly ObjectRetentionHandler $settingsHandler,
		public readonly SaveObject $saveObject,
		public readonly DestructionService $destructionService,
	) {
	}//end __construct()
}//end class
