<?php

/**
 * OAuth2AccountIdentity — asking the provider whose account was just connected.
 *
 * A credential that holds a live token and cannot say WHOSE it is cannot be audited.
 * The owner sees a row saying "Mastodon" with no way to tell which of their two
 * accounts it speaks for, and neither does anyone reviewing the instance a year
 * later. So the handle is read once, at the only moment it is guaranteed to be
 * knowable: right after the token set is stored.
 *
 * THE CALL GOES THROUGH THE BROKER'S OWN PROXY, which matters more than it looks.
 * Every path this class can reach is already an allow-rule on the provider entry, so
 * the identity fetch is bounded by exactly the same guards as any other call on that
 * credential, and a catalogue naming a path outside its own rules fails closed
 * instead of quietly reaching further than it was reviewed to.
 *
 * BEST EFFORT, ALWAYS. The connection is already made and already works; undoing it
 * because a cosmetic label could not be read would trade something that matters for
 * something that does not. One case is expected rather than accidental: the broker
 * deliberately refuses to admit an ORGANISATION-scoped credential on an asserted user
 * id, so an organisation connect records no handle. That is the owner guard working,
 * and it costs a label rather than a capability.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads and records the account a connection speaks for.
 */
class OAuth2AccountIdentity {
	/**
	 * The app id this in-process identity call is made as.
	 *
	 * @var string
	 */
	private const BROKER_APP_ID = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService $broker Makes the one bounded call.
	 * @param OAuth2RefreshService $refresh Persists the set carrying the account.
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CredentialBrokerService $broker,
		private readonly OAuth2RefreshService $refresh,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Ask who the connection belongs to, and record the answer on the credential.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param string $credentialId The credential just minted or repaired.
	 * @param string $scope The credential scope.
	 * @param OAuth2TokenSet $set The token set just stored.
	 * @param string $owner The credential's owner, asserted for this sessionless call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	public function record(
		array $provider,
		string $credentialId,
		string $scope,
		OAuth2TokenSet $set,
		string $owner,
	): void {
		$identity = ($provider['identity'] ?? null);
		if (is_array($identity) === false || trim((string)($identity['path'] ?? '')) === '') {
			return;
		}

		try {
			$response = $this->broker->request(
				credentialId: $credentialId,
				appId: self::BROKER_APP_ID,
				method: (string)($identity['method'] ?? 'GET'),
				path: (string)$identity['path'],
				actingUserId: $owner
			);

			$decoded = json_decode($response['body'], true);
			if (is_array($decoded) === false) {
				return;
			}

			$this->refresh->persist(
				credentialId: $credentialId,
				scope: $scope,
				set: $set->withAccount(
					id: $this->fieldAt(payload: $decoded, path: (string)($identity['idField'] ?? '')),
					handle: $this->fieldAt(payload: $decoded, path: (string)($identity['handleField'] ?? '')),
					displayName: $this->fieldAt(payload: $decoded, path: (string)($identity['displayNameField'] ?? ''))
				)
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[OAuth2AccountIdentity] could not read the connected account for ' . $credentialId . ': ' . $failure->getMessage()
			);
		}//end try
	}//end record()

	/**
	 * Read one scalar out of a decoded response by dotted path.
	 *
	 * @param array<string, mixed> $payload The decoded response.
	 * @param string $path The dotted field path, or an empty string.
	 *
	 * @return string The value, or an empty string when it is absent or not a scalar.
	 *
	 * @spec exclude private response accessor with no behaviour of its own
	 */
	private function fieldAt(array $payload, string $path): string {
		if ($path === '') {
			return '';
		}

		$cursor = $payload;
		foreach (explode('.', $path) as $segment) {
			if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
				return '';
			}

			$cursor = $cursor[$segment];
		}

		if (is_scalar($cursor) === false) {
			return '';
		}

		return (string)$cursor;
	}//end fieldAt()
}//end class
