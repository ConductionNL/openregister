<?php

/**
 * OAuth2RelayGuardInterface: what callers outside the component use of OAuth2RelayGuard.
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

/**
 * The relay-target check the OAuth2 endpoints run on a callback URL.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface OAuth2RelayGuardInterface {

	/**
	 * Whether a relay may forward a code to this callback URL.
	 *
	 * Fails CLOSED on everything: an empty allow-list forwards nowhere, so an
	 * instance that was never configured as a relay cannot accidentally act as one.
	 *
	 * @param string $callbackUrl The receiving instance's callback, taken from the state.
	 *
	 * @return boolean True when the URL is an allow-listed origin plus this application's callback path.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
	 */
	public function permits(string $callbackUrl): bool;
}//end interface
