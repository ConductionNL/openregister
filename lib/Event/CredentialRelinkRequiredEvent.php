<?php

/**
 * CredentialRelinkRequiredEvent — a brokered connection lost its grant.
 *
 * Dispatched once when an `oauth2-token-set` credential's refresh is rejected with
 * `invalid_grant`, at the moment its status becomes `relink_needed`. Once per break
 * rather than once per attempt: a `relink_needed` credential never reaches the
 * refresh path again, so the event cannot storm.
 *
 * It carries the credential id, its provider and its owner, and NOTHING ELSE. No
 * access token, no refresh token, no client secret and no provider response body
 * are on it, because a listener is arbitrary code in another app and an event
 * payload is exactly the kind of second read path ADR-064 exists to close.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Signals that a connected account must be re-authorised.
 */
class CredentialRelinkRequiredEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $credentialId The `brokeredcredential` object UUID, which is the credentialRef apps hold.
	 * @param string $provider The catalogue provider identifier the connection targets.
	 * @param string $owner The owning user id, who is the person able to re-authorise.
	 * @param string $reason A short, secret-free reason (the provider's OAuth2 error code).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $credentialId,
		private readonly string $provider,
		private readonly string $owner,
		private readonly string $reason,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The credential that needs re-authorising.
	 *
	 * @return string The credential UUID.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	public function getCredentialId(): string {
		return $this->credentialId;
	}//end getCredentialId()

	/**
	 * The catalogue provider the connection targets.
	 *
	 * @return string The provider identifier.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	public function getProvider(): string {
		return $this->provider;
	}//end getProvider()

	/**
	 * The user who can re-authorise the connection.
	 *
	 * @return string The owning user id.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	public function getOwner(): string {
		return $this->owner;
	}//end getOwner()

	/**
	 * The secret-free reason the grant was refused.
	 *
	 * @return string The provider's OAuth2 error code.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()
}//end class
