<?php

/**
 * OpenRegister Resolver ResolverConfigException
 *
 * The app and config key every resolver refusal carries.
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
 * A resolver refusal about one config key of one consumer app.
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */
abstract class ResolverConfigException extends Exception {

	/**
	 * Consumer app id (e.g. `opencatalogi`).
	 *
	 * @var string
	 */
	private string $appId;

	/**
	 * Config key the resolver read.
	 *
	 * @var string
	 */
	private string $configKey;

	/**
	 * Construct the refusal with diagnostic context.
	 *
	 * @param string         $appId     Consumer app id.
	 * @param string         $configKey Config key the resolver read.
	 * @param string         $message   The message.
	 * @param int            $code      The HTTP-style status code.
	 * @param Exception|null $previous  Previous exception in the chain.
	 */
	protected function __construct(string $appId, string $configKey, string $message, int $code, ?Exception $previous) {
		$this->appId = $appId;
		$this->configKey = $configKey;

		parent::__construct(message: $message, code: $code, previous: $previous);
	}//end __construct()

	/**
	 * Get the app id the resolver was working against.
	 *
	 * @return string The consumer app id.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 */
	public function getAppId(): string {
		return $this->appId;
	}//end getAppId()

	/**
	 * Get the config key that was read.
	 *
	 * @return string The config key.
	 *
	 * @spec openspec/specs/register-resolver-service/spec.md
	 */
	public function getConfigKey(): string {
		return $this->configKey;
	}//end getConfigKey()
}//end class
