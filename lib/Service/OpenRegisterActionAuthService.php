<?php

/**
 * OpenRegister's own action-authorization service (ADR-023).
 *
 * `GenericActionAuthService` takes its app id as a constructor STRING, which
 * makes it unautowirable — every leaf app binds it. OpenRegister never bound
 * one for itself, which is the mechanical reason its own operations carried no
 * per-action rights while it shipped the service other apps use for theirs.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\AppHost\Service\GenericActionAuthService;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * The action matrix, bound to the `openregister` app id.
 */
class OpenRegisterActionAuthService extends GenericActionAuthService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config store for the matrix.
	 * @param IGroupManager $groupManager Resolves the user's groups.
	 */
	public function __construct(IAppConfig $appConfig, IGroupManager $groupManager) {
		parent::__construct(
			appId: 'openregister',
			appConfig: $appConfig,
			groupManager: $groupManager
		);

	}//end __construct()
}//end class
