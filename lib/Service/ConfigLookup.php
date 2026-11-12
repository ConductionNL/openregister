<?php

/**
 * OpenRegister ConfigLookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Resolver\Exception\ResolverConfigKey;

/**
 * One lookup of a configured register, schema or property: which app-config key of which app,
 * the caller's fallback value and the explicitly requested tenant.
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */
final class ConfigLookup {

	/**
	 * Constructor.
	 *
	 * @param string      $appId            Consumer app id.
	 * @param string      $configKey        Config key to read.
	 * @param string|null $default          Fallback slug/UUID if the config is unset.
	 * @param string|null $organisationUuid Optional explicit-tenant override.
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $configKey,
		public readonly ?string $default=null,
		public readonly ?string $organisationUuid=null,
	) {

	}//end __construct()

	/**
	 * The app-config key this lookup reads, as named in resolver diagnostics.
	 *
	 * @return ResolverConfigKey
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 */
	public function key(): ResolverConfigKey {
		return new ResolverConfigKey(appId: $this->appId, configKey: $this->configKey);
	}//end key()

	/**
	 * The request-scoped cache key: app, config key and explicit tenant.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 */
	public function cacheKey(): string {
		return $this->appId.':'.$this->configKey.':'.($this->organisationUuid ?? '');
	}//end cacheKey()
}//end class
