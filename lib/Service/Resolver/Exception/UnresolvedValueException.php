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
	 * Construct the not-found refusal with diagnostic context.
	 *
	 * @param ResolverConfigKey $config        The app and config key that resolved.
	 * @param string            $resolvedValue Slug/UUID the lookup attempted.
	 * @param string            $message       The message.
	 * @param Exception|null    $previous      Previous exception in the chain.
	 */
	protected function __construct(ResolverConfigKey $config, private readonly string $resolvedValue, string $message, ?Exception $previous) {
		parent::__construct(config: $config, message: $message, code: 404, previous: $previous);
	}//end __construct()

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
