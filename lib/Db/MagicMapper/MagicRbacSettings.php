<?php

/**
 * OpenRegister MagicRbacSettings
 *
 * The settings magic-table RBAC reads: the app config, the deny staging switch and the columns a degraded table still lacks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Service\Rbac\DenyEnforcementModeInterface;
use OCP\IAppConfig;

/**
 * The settings magic-table RBAC reads: the app config, the deny staging switch and the columns a degraded table still lacks.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final readonly class MagicRbacSettings {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IAppConfig                        $appConfig           App configuration for RBAC settings.
	 * @param DenyEnforcementModeInterface|null $denyEnforcementMode The deny staging switch.
	 * @param MagicMetadataColumnHealer|null    $metadataColumns     Knows which metadata columns an unhealed table still lacks.
	 */
	public function __construct(
		public IAppConfig $appConfig,
		public ?DenyEnforcementModeInterface $denyEnforcementMode = null,
		public ?MagicMetadataColumnHealer $metadataColumns = null,
	) {
	}//end __construct()
}//end class
