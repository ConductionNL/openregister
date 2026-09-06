<?php

/**
 * OAuth2ClientResolver — finds the OAuth2 client a connection authenticates with.
 *
 * A token exchange needs a client id and, for most providers, a client secret. There
 * were three places that secret could have lived and two of them break ADR-064: an
 * app-config value and a file on disk are both "a secret at rest outside the custody
 * leaf", which is the exact shape the ADR closes. So the client secret is itself a
 * brokered credential of the existing `generic-oauth2` provider, resolved here
 * through {@see CredentialBrokerService::resolveInjectable()} and therefore through
 * the ordinary owner and allowed-apps guards. A caller cannot borrow a client secret
 * it has no claim on.
 *
 * Two sources, in order:
 *
 *   1. The credential's own `clientCredentialRef` — the tenant brought its own
 *      developer application, or one was registered at the account's own server.
 *   2. The instance default for that provider, set by an administrator as
 *      `oauth2_client_<provider>` (the credentialRef) and `oauth2_client_id_<provider>`
 *      (the non-secret client id).
 *
 * THE BROKER IS FETCHED LAZILY FROM THE CONTAINER, and that is not laziness for its
 * own sake. `CredentialBrokerService` needs the refresh service, the refresh service
 * needs this resolver, and this resolver needs the broker: three constructor
 * injections would be a dependency cycle the container cannot build. Resolving the
 * broker at call time breaks the cycle at the one edge where the dependency is
 * genuinely dynamic — it is needed only when a client secret is actually required,
 * which is not on the read path of an unexpired token.
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

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Resolves the client id and client secret for one OAuth2 connection.
 */
class OAuth2ClientResolver {
	/**
	 * The app id the broker guards see for this in-process resolution.
	 *
	 * @var string
	 */
	private const BROKER_APP_ID = 'openregister';

	/**
	 * App-config key prefix holding the instance default client credentialRef per provider.
	 *
	 * @var string
	 */
	private const CONFIG_CLIENT_REF_PREFIX = 'oauth2_client_';

	/**
	 * App-config key prefix holding the instance default (non-secret) client id per provider.
	 *
	 * @var string
	 */
	private const CONFIG_CLIENT_ID_PREFIX = 'oauth2_client_id_';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the per-provider instance default client.
	 * @param ContainerInterface $container Resolves the broker lazily, breaking the construction cycle.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Resolve the client id and secret for a credential's token exchanges.
	 *
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param string $provider The catalogue provider identifier.
	 * @param string|null $actingUserId The asserted user for a sessionless caller (a background sweep).
	 *
	 * @return array{clientId: string, clientSecret: string|null} The client id, and its secret when one exists.
	 *
	 * @throws CredentialAccessDeniedException When no client is configured for the provider at all.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	public function resolve(array $credential, string $provider, ?string $actingUserId = null): array {
		$clientRef = trim((string)($credential['clientCredentialRef'] ?? ''));
		$clientId = trim((string)($credential['clientId'] ?? ''));

		if ($clientRef === '') {
			$clientRef = $this->appConfig->getValueString(self::BROKER_APP_ID, self::CONFIG_CLIENT_REF_PREFIX . $provider, '');
			if ($clientId === '') {
				$clientId = $this->appConfig->getValueString(self::BROKER_APP_ID, self::CONFIG_CLIENT_ID_PREFIX . $provider, '');
			}
		}

		if ($clientId === '') {
			throw new CredentialAccessDeniedException(message: 'no OAuth2 client id is configured for provider ' . $provider);
		}

		$clientSecret = null;
		if ($clientRef !== '') {
			$clientSecret = $this->secretFor(clientRef: $clientRef, actingUserId: $actingUserId);
		}

		return ['clientId' => $clientId, 'clientSecret' => $clientSecret];
	}//end resolve()

	/**
	 * Read one client secret out of the broker, through its own guard chain.
	 *
	 * @param string $clientRef The `generic-oauth2` credential UUID holding the client secret.
	 * @param string|null $actingUserId The asserted user for a sessionless caller.
	 *
	 * @return string|null The client secret, or null when the referenced credential holds none.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	private function secretFor(string $clientRef, ?string $actingUserId): ?string {
		try {
			$broker = $this->container->get(CredentialBrokerService::class);
		} catch (Throwable $unresolvable) {
			// Fail CLOSED. A client secret that cannot be resolved is not "no
			// secret needed"; treating it as one would send an unauthenticated
			// token request and read the provider's refusal as the tenant's fault.
			throw new CredentialAccessDeniedException(
				message: 'credential broker is unavailable to resolve the OAuth2 client secret',
				previous: $unresolvable
			);
		}

		return $broker->resolveInjectable(
			credentialId: $clientRef,
			appId: self::BROKER_APP_ID,
			actingUserId: $actingUserId
		);
	}//end secretFor()
}//end class
