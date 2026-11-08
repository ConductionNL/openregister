<?php

/**
 * The two config stores the claim-derived grant store reads and writes.
 *
 * The rules an administrator declared live in app config; the claims and
 * grants of one account live in that account's preferences. Both are the
 * store's storage, and are handed over as one collaborator. This bundle
 * carries no behaviour.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\IAppConfig;
use OCP\IConfig;

/**
 * Per-user preferences and app config, wired by DI autowiring.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class DerivedGrantConfig {
	/**
	 * Bundle the config stores.
	 *
	 * @param IConfig    $config    Per-user preferences.
	 * @param IAppConfig $appConfig Where the rules are declared.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly IConfig $config,
		public readonly IAppConfig $appConfig,
	) {
	}//end __construct()
}//end class
