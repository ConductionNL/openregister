<?php

/**
 * OpenRegister Resolver UnresolvedValueException
 *
 * A config key whose value names nothing the caller can reach.
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
 * A resolved slug/UUID with no matching entity; answers 404.
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */
abstract class UnresolvedValueException extends ResolverConfigException {

	/**
	 * Slug, UUID or name that failed to resolve.
	 *
	 * @var string
	 */
	private string $resolvedValue;

	/**
	 * Construct the not-found refusal with diagnostic context.
	 *
	 * @param ResolverConfigKey $config        The app and config key that resolved.
	 * @param string            $resolvedValue Slug/UUID the lookup attempted.
	 * @param string            $message       The message.
	 * @param Exception|null    $previous      Previous exception in the chain.
	 */
	protected function __construct(ResolverConfigKey $config, string $resolvedValue, string $message, ?Exception $previous) {
		$this->resolvedValue = $resolvedValue;

		parent::__construct(config: $config, message: $message, code: 404, previous: $previous);
	}//end __construct()

	/**
	 * The message of a slug/UUID that names no entity of that kind in the caller's tenant.
	 *
	 * @param string $kind          The entity kind, capitalised.
	 * @param string $appId         Consumer app id.
	 * @param string $configKey     Config key that resolved.
	 * @param string $resolvedValue Slug/UUID the lookup attempted.
	 *
	 * @return string The message.
	 */
	protected static function notInTenant(string $kind, string $appId, string $configKey, string $resolvedValue): string {
		return sprintf(
			'%s "%s" (from config key "%s" on app "%s") not found in caller tenant.',
			$kind,
			$resolvedValue,
			$configKey,
			$appId
		);
	}//end notInTenant()

	/**
	 * Get the resolved value that could not be hydrated.
	 *
	 * @return string The slug, UUID or name the lookup attempted.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 */
	public function getResolvedValue(): string {
		return $this->resolvedValue;
	}//end getResolvedValue()
}//end class
