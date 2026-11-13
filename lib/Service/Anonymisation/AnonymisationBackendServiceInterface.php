<?php

/**
 * AnonymisationBackendServiceInterface: what callers outside the component use of AnonymisationBackendService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
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

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * The anonymisation backend state and probe the backend endpoints expose.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AnonymisationBackendServiceInterface {

	/**
	 * Resolve the full backend selection state.
	 *
	 * @return BackendState The fully-resolved state.
	 */
	public function getState(): BackendState;

	/**
	 * Issue a fresh probe for a single method, bypassing and refreshing the cache.
	 *
	 * An OpenAnonymiser test also tells integriq's connection registry what it found.
	 *
	 * @param string $method One of BackendState::METHODS.
	 *
	 * @return ProbeResult The fresh probe result.
	 *
	 * @spec openspec/specs/app-connections/spec.md
	 */
	public function testConnection(string $method): ProbeResult;
}//end interface
