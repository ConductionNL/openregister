<?php

/**
 * OpenRegister OAuth2 start run
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * One authorisation step of an OAuth2 connection start, and what it has set up so far.
 *
 * The steps that build the authorization URL record the per-instance client
 * they minted and the state nonce they stored, so a start that fails after
 * either can remove exactly what it created. Built and used by
 * CredentialOauth2Controller only.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 */
final class OAuth2StartRun {

	/**
	 * Who starts, in which scope.
	 *
	 * @var OAuth2StartRequest
	 */
	public readonly OAuth2StartRequest $start;

	/**
	 * The catalogue provider entry.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $provider;

	/**
	 * The claims the state carries; the minted-client marker is taken out once read.
	 *
	 * @var array<string, mixed>
	 */
	public array $claims;

	/**
	 * The credential id of the client minted for this start, empty when none was.
	 *
	 * @var string
	 */
	public string $minted = '';

	/**
	 * The nonce of the state stored for this start, empty when none was.
	 *
	 * @var string
	 */
	public string $nonce = '';

	/**
	 * Start a run from the start request and its prepared provider and claims.
	 *
	 * @param OAuth2StartRequest                                                  $start    Who starts, in which scope.
	 * @param array{provider: array<string, mixed>, claims: array<string, mixed>} $prepared The provider entry and the claims.
	 *
	 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function __construct(OAuth2StartRequest $start, array $prepared) {
		$this->start = $start;
		$this->provider = $prepared['provider'];
		$this->claims = $prepared['claims'];
	}//end __construct()
}//end class
