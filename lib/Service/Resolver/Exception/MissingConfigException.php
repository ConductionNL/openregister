<?php

/**
 * OpenRegister MissingConfigException
 *
 * Thrown when a register/schema resolver is asked to read a config key
 * that is unset and no default was provided. Distinct from
 * RegisterNotFoundException — here the config itself is missing, not
 * the resolved entity.
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

use Exception;

/**
 * Raised when a resolver-config key is unset and no default was passed.
 *
 * @phpstan-consistent-constructor
 */
class MissingConfigException extends ResolverConfigException {

	/**
	 * Construct the missing-config exception with diagnostic context.
	 *
	 * @param string $appId Consumer app id.
	 * @param string $configKey Config key that was missing.
	 * @param Exception|null $previous Previous exception in the chain.
	 */
	public function __construct(string $appId, string $configKey, ?Exception $previous = null) {
		parent::__construct(
			appId: $appId,
			configKey: $configKey,
			message: sprintf(
				'Resolver config key "%s" is not set for app "%s" and no default was provided.',
				$configKey,
				$appId
			),
			code: 500,
			previous: $previous
		);
	}//end __construct()
}//end class
