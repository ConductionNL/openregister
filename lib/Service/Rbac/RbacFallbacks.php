<?php

/**
 * The access-control collaborators a caller builds when none was injected.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Builds the stateless access-control collaborators a caller falls back to.
 *
 * A caller outside this component that may be built without the shared
 * instance (its parameter is nullable-with-default) builds the equivalent here,
 * so it names only the interface and never the class.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class RbacFallbacks {

	/**
	 * A fresh object-scope resolver; it is stateless, so it equals the shared one.
	 *
	 * @return ObjectScopeResolverInterface The resolver.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function objectScope(): ObjectScopeResolverInterface {
		return new ObjectScopeResolver();
	}//end objectScope()

	/**
	 * A deny enforcement switch reading the same app-config key as the shared one.
	 *
	 * @param IAppConfig      $appConfig The store holding the mode.
	 * @param LoggerInterface $logger    Where a staged denial is recorded.
	 *
	 * @return DenyEnforcementModeInterface The switch.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public static function denyEnforcement(IAppConfig $appConfig, LoggerInterface $logger): DenyEnforcementModeInterface {
		return new DenyEnforcementMode(appConfig: $appConfig, logger: $logger);
	}//end denyEnforcement()
}//end class
