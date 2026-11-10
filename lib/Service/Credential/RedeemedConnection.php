<?php

/**
 * OpenRegister Credential — Redeemed Connection
 *
 * A token set just redeemed for a connection flow, ready to be stored.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Credential
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

/**
 * Store a redeemed token set: the provider, the flow that asked for it, the
 * credential a re-authorisation repairs (or null for a new connection) and the set.
 *
 * @spec openspec/specs/credential-oauth2-connect/spec.md#requirement-re-authorisation-overrides-the-same-credential
 */
final class RedeemedConnection {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>      $provider The catalogue provider entry.
	 * @param OAuth2FlowClaims          $flow     The verified state claims.
	 * @param array<string, mixed>|null $existing The credential a repair targets, or null.
	 * @param OAuth2TokenSet            $tokenSet The token set to store.
	 */
	public function __construct(
		public readonly array $provider,
		public readonly OAuth2FlowClaims $flow,
		public readonly ?array $existing,
		public readonly OAuth2TokenSet $tokenSet,
	) {
	}//end __construct()

	/**
	 * Whose credential this is: a repaired credential keeps its owner, a new one
	 * belongs to the user the flow was started for.
	 *
	 * @return string The owner's user id.
	 */
	public function owner(): string {
		return (string)($this->existing['owner'] ?? $this->flow->string(key: 'u'));
	}//end owner()
}//end class
