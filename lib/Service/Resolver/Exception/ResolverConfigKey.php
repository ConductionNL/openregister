<?php

/**
 * OpenRegister Resolver ResolverConfigKey
 *
 * One config key of one consumer app: what every resolver refusal is about.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Exception
 * @package   OCA\OpenRegister\Service\Resolver\Exception
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resolver\Exception;

/**
 * The consumer app id and the config key the resolver read for it.
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */
final class ResolverConfigKey {

	/**
	 * Consumer app id (e.g. `opencatalogi`).
	 *
	 * @var string
	 */
	public readonly string $appId;

	/**
	 * Config key the resolver read.
	 *
	 * @var string
	 */
	public readonly string $configKey;

	/**
	 * Name one config key of one consumer app.
	 *
	 * @param string $appId     Consumer app id.
	 * @param string $configKey Config key the resolver read.
	 */
	public function __construct(string $appId, string $configKey) {
		$this->appId     = $appId;
		$this->configKey = $configKey;
	}//end __construct()
}//end class
