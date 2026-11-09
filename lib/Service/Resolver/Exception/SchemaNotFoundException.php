<?php

/**
 * OpenRegister Resolver SchemaNotFoundException
 *
 * Raised by RegisterResolverService when a config key resolves to a
 * schema slug/UUID that doesn't exist in the caller's tenant. Lives in
 * `Service\Resolver\Exception` to avoid name collision with the generic
 * `OCA\OpenRegister\Exception\SchemaNotFoundException`.
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
 * Raised when a resolved schema slug/UUID has no matching entity.
 *
 * @phpstan-consistent-constructor
 */
class SchemaNotFoundException extends UnresolvedValueException {

	/**
	 * Construct the not-found exception with diagnostic context.
	 *
	 * @param string $appId Consumer app id.
	 * @param string $configKey Config key that resolved.
	 * @param string $resolvedValue Slug/UUID the lookup attempted.
	 * @param Exception|null $previous Previous exception in the chain.
	 */
	public function __construct(string $appId, string $configKey, string $resolvedValue, ?Exception $previous = null) {
		parent::__construct(
			config: new ResolverConfigKey(appId: $appId, configKey: $configKey),
			resolvedValue: $resolvedValue,
			message: self::notInTenant(kind: 'Schema', appId: $appId, configKey: $configKey, resolvedValue: $resolvedValue),
			previous: $previous
		);
	}//end __construct()
}//end class
