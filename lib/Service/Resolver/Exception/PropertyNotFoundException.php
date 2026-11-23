<?php

/**
 * OpenRegister Resolver PropertyNotFoundException
 *
 * Raised by RegisterResolverService when a property config key resolves
 * to an identifier that does not match any property on the resolved
 * schema. Lives in `Service\Resolver\Exception` for consistency with the
 * Register / Schema not-found exceptions.
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
 * Raised when a resolved property slug/UUID has no matching property on
 * the target schema.
 *
 * @phpstan-consistent-constructor
 */
class PropertyNotFoundException extends UnresolvedValueException {

	/**
	 * Construct the not-found exception with diagnostic context.
	 *
	 * @param ResolverConfigKey $config           The app and config key that resolved.
	 * @param string            $resolvedValue    Slug/UUID/name the lookup attempted.
	 * @param string            $schemaIdentifier Schema slug/UUID the lookup targeted.
	 * @param Exception|null    $previous         Previous exception in the chain.
	 */
	public function __construct(
		ResolverConfigKey $config,
		string $resolvedValue,
		private readonly string $schemaIdentifier,
		?Exception $previous = null,
	) {
		parent::__construct(
			config: $config,
			resolvedValue: $resolvedValue,
			message: sprintf(
				'Property "%s" (from config key "%s" on app "%s") not found on schema "%s".',
				$resolvedValue,
				$config->configKey,
				$config->appId,
				$schemaIdentifier
			),
			previous: $previous
		);
	}//end __construct()

	/**
	 * Get the schema identifier the property lookup targeted.
	 *
	 * @return string The schema slug or UUID.
	 */
	public function getSchemaIdentifier(): string {
		return $this->schemaIdentifier;
	}//end getSchemaIdentifier()
}//end class
