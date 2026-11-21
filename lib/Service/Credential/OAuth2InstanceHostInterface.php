<?php

/**
 * OAuth2InstanceHostInterface: the per-account host check callers outside the component use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use InvalidArgumentException;

/**
 * Validates a per-account API host and returns its normalised origin.
 *
 * Declares only what callers outside the component use. ContractBindings binds
 * it to the final {@see OAuth2InstanceHost}, so every caller runs the same
 * host-lock; inside the component the static rule is called directly.
 */
interface OAuth2InstanceHostInterface {

	/**
	 * Validate a candidate instance base URL and return its normalised origin.
	 *
	 * @param string $candidate The URL supplied at connect time.
	 *
	 * @return string The normalised origin (`https://host` with no trailing slash).
	 *
	 * @throws InvalidArgumentException When the candidate is not a safe, absolute https origin.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
	 */
	public function origin(string $candidate): string;
}//end interface
