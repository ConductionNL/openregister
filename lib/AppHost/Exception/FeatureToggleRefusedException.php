<?php

/**
 * Raised when a settings update names a feature toggle nobody declared.
 *
 * 422 rather than 400: the request is well formed, and the key it names is a
 * thing that could exist — it simply is not declared by this app. The
 * distinction matters to the caller, because a 400 reads as "you sent
 * nonsense" and this is "you sent a toggle that does not exist here".
 *
 * @category Exception
 * @package  OCA\OpenRegister\AppHost\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Exception;

use RuntimeException;
use Throwable;

/**
 * Raised when a feature-toggle update names an undeclared key.
 *
 * @spec openspec/changes/feature-toggle-surface/specs/apphost-settings-plane/spec.md
 */
class FeatureToggleRefusedException extends RuntimeException {

	/**
	 * Construct the refusal, naming the key.
	 *
	 * @param string         $appId    The app whose toggles were being written.
	 * @param string         $key      The undeclared key.
	 * @param Throwable|null $previous Previous exception in the chain.
	 */
	public function __construct(
		private readonly string $appId,
		private readonly string $key,
		?Throwable $previous = null,
	) {
		parent::__construct(
			message: sprintf(
				'[AppHost:%s] feature toggle "%s" is not declared by this app, so it cannot be set.',
				$appId,
				$key
			),
			code: 422,
			previous: $previous
		);
	}//end __construct()

	/**
	 * The app the refusal was raised for.
	 *
	 * @return string The app id.
	 */
	public function getAppId(): string {
		return $this->appId;
	}//end getAppId()

	/**
	 * The undeclared key the caller named.
	 *
	 * @return string The key.
	 */
	public function getKey(): string {
		return $this->key;
	}//end getKey()
}//end class
