<?php

/**
 * OAuth2RefreshService — keeps an `oauth2-token-set` credential usable.
 *
 * Owns everything that happens between "a caller wants to make a brokered call" and
 * "there is a valid access token to inject": decoding the stored set, deciding
 * whether the refresh margin has been crossed, taking the per-credential lock,
 * exchanging the refresh token at the catalogue's token endpoint, rotating the
 * stored set, and deciding what a failure means.
 *
 * Two ordering rules carry most of the safety here.
 *
 * THE CUSTODY WRITE HAPPENS BEFORE THE OBJECT UPDATE. The leaf and the object are
 * two stores with no shared transaction, so one of them is written first and the
 * other may fail. Writing the leaf first means the worst outcome is an object whose
 * `expiresAt` is stale, which the next read corrects from the leaf. The other order
 * would leave an object claiming a token the leaf does not hold, which reads as a
 * healthy connection and fails at call time.
 *
 * ONLY `invalid_grant` IS TERMINAL. A 500 or a timeout from a token endpoint is the
 * provider having a bad day; treating it as a revoked grant would relink every
 * connection on the instance during somebody else's incident. So a transient failure
 * raises {@see CredentialUpstreamException} and leaves the credential `active`,
 * while `invalid_grant` moves it to `relink_needed` exactly once and every later
 * call fails closed without contacting the provider at all.
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

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes and rotates OAuth2 token sets on behalf of the broker.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) 16 against a threshold of 13, and the
 * composition is the reason rather than an excuse: SEVEN of the sixteen are the failure
 * vocabulary this class exists to get right (`InvalidArgumentException`,
 * `CredentialAccessDeniedException`, `CredentialRelinkRequiredException`,
 * `CredentialUpstreamException`, `Throwable`) plus the two value objects it decodes
 * and validates with. Collapsing them into a smaller set would mean one exception type
 * for "the grant is gone" and "the provider timed out", which is exactly the
 * distinction the whole class is built around: get it wrong and a provider outage
 * relinks every connection on the instance. The genuine COLLABORATORS are six, and one
 * of them was already split out into {@see CredentialRelinkNotifier} while writing this.
 * @SuppressWarnings(PHPMD.StaticAccess) Two static calls, both to named constructors
 * or to a stateless security predicate: {@see OAuth2TokenSet} decodes a stored
 * document, {@see OAuth2InstanceHost} validates a per-account host. Neither has an
 * instance to call, and injecting the host guard would make it substitutable.
 */
class OAuth2RefreshService {
	/**
	 * How far ahead of an access token's expiry a refresh becomes due, in seconds.
	 *
	 * A constant rather than a setting: a per-install margin is one more value that
	 * can be set wrong, and no provider in the catalogue issues an access token with
	 * a life shorter than five minutes.
	 *
	 * @var integer
	 */
	public const REFRESH_MARGIN_SECONDS = 120;

	/**
	 * How far ahead of expiry the daily sweep refreshes a token set, in seconds.
	 *
	 * Two days, so a token with a sixty-day life is rotated well before it lapses
	 * even if nothing reads it, and a token with a one-hour life is left to the read
	 * path, which will have refreshed it long before the sweep next runs.
	 *
	 * @var integer
	 */
	public const SWEEP_WINDOW_SECONDS = 172800;

	/**
	 * Statuses that are terminal for a brokered call.
	 *
	 * @var array<int, string>
	 */
	public const BLOCKING_STATUSES = ['relink_needed', 'disabled'];

	/**
	 * The provider error code that means the grant itself is gone.
	 *
	 * @var string
	 */
	private const TERMINAL_ERROR = 'invalid_grant';

	/**
	 * Constructor.
	 *
	 * @param CredentialStore $credentialStore Holds the token set; the only place it is ever written.
	 * @param ObjectService $objectService Updates the credential's non-secret metadata.
	 * @param CredentialLock $lock Excludes a second concurrent rotation of one credential.
	 * @param OAuth2ClientResolver $clients Resolves the client id and secret for the token request.
	 * @param IClientService $clientService NC HTTP client factory for the token request.
	 * @param CredentialRelinkNotifier $relinkNotifier Announces a lost grant to the instance and its owner.
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CredentialStore $credentialStore,
		private readonly ObjectService $objectService,
		private readonly CredentialLock $lock,
		private readonly OAuth2ClientResolver $clients,
		private readonly IClientService $clientService,
		private readonly CredentialRelinkNotifier $relinkNotifier,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return an access token that is valid now, refreshing first when it is not.
	 *
	 * @param array<string, mixed> $credential The credential object's serialised data (must carry an `id`/`uuid`).
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param string $credentialId The credential UUID.
	 * @param string $scope The credential scope (`personal`|`organisation`).
	 * @param string|null $actingUserId The asserted user for a sessionless caller.
	 *
	 * @return string The access token to inject.
	 *
	 * @throws CredentialRelinkRequiredException When the credential is blocked or its grant is gone.
	 * @throws CredentialAccessDeniedException When no usable token set is stored.
	 * @throws CredentialUpstreamException When the token endpoint could not be reached or refused transiently.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-brokered-call-refreshes-an-expiring-token-set-before-it-is-used
	 */
	public function accessTokenFor(
		array $credential,
		array $provider,
		string $credentialId,
		string $scope,
		?string $actingUserId = null,
	): string {
		$this->assertNotBlocked(credential: $credential, credentialId: $credentialId);

		$set = $this->storedSet(credentialId: $credentialId, scope: $scope);
		if ($set->needsRefresh(marginSeconds: self::REFRESH_MARGIN_SECONDS) === false) {
			return $set->getAccessToken();
		}

		if ($this->lock->acquire($credentialId) === false) {
			// Somebody else is rotating this credential. Wait for them, then read
			// what they stored rather than starting a second exchange; after a
			// successful rotation the re-read is outside the margin and there is
			// nothing left to do.
			$this->lock->waitForRelease($credentialId);
			$set = $this->storedSet(credentialId: $credentialId, scope: $scope);
			if ($set->needsRefresh(marginSeconds: self::REFRESH_MARGIN_SECONDS) === false) {
				return $set->getAccessToken();
			}

			if ($this->lock->acquire($credentialId) === false) {
				throw new CredentialUpstreamException(message: 'refresh lock is held and the token is still expiring');
			}
		}

		try {
			$rotated = $this->rotate(
				credential: $credential,
				provider: $provider,
				credentialId: $credentialId,
				scope: $scope,
				current: $set,
				actingUserId: $actingUserId
			);
		} finally {
			$this->lock->release($credentialId);
		}

		return $rotated->getAccessToken();
	}//end accessTokenFor()

	/**
	 * Refresh one credential unconditionally, for the daily sweep.
	 *
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param string $credentialId The credential UUID.
	 * @param string $scope The credential scope.
	 *
	 * @return boolean True when a rotation happened, false when the credential was outside the sweep window.
	 *
	 * @throws Throwable Whatever the rotation raised; the sweep decides what to do with it.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-daily-job-refreshes-active-token-sets-before-they-expire
	 */
	public function sweepCredential(array $credential, array $provider, string $credentialId, string $scope): bool {
		$this->assertNotBlocked(credential: $credential, credentialId: $credentialId);

		$set = $this->storedSet(credentialId: $credentialId, scope: $scope);
		if ($set->needsRefresh(marginSeconds: self::SWEEP_WINDOW_SECONDS) === false) {
			return false;
		}

		if ($this->lock->acquire($credentialId) === false) {
			// The read path is already rotating it. Leaving it to them is the whole
			// point of the lock; the next sweep picks up anything they did not finish.
			return false;
		}

		$owner = trim((string)($credential['owner'] ?? ''));

		try {
			$this->rotate(
				credential: $credential,
				provider: $provider,
				credentialId: $credentialId,
				scope: $scope,
				current: $set,
				actingUserId: $this->nullIfEmpty(value: $owner)
			);
		} finally {
			$this->lock->release($credentialId);
		}

		return true;
	}//end sweepCredential()

	/**
	 * Read and decode the stored token set.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param string $scope The credential scope.
	 *
	 * @return OAuth2TokenSet The decoded set.
	 *
	 * @throws CredentialAccessDeniedException When nothing is stored, or the stored document is not a token set.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
	 */
	public function storedSet(string $credentialId, string $scope): OAuth2TokenSet {
		$stored = $this->credentialStore->get($credentialId, $scope);
		if ($stored === null || $stored === '') {
			throw new CredentialAccessDeniedException(message: 'no token set stored for credential');
		}

		try {
			return OAuth2TokenSet::fromStoredJson(stored: $stored);
		} catch (InvalidArgumentException $malformed) {
			// The message names the decode failure and never quotes the value, which
			// is why it is safe to pass on: OAuth2TokenSet is written to guarantee that.
			throw new CredentialAccessDeniedException(
				message: 'stored token set is unusable: ' . $malformed->getMessage(),
				previous: $malformed
			);
		}
	}//end storedSet()

	/**
	 * Persist a token set and mirror its non-secret metadata onto the credential object.
	 *
	 * The custody write happens FIRST and the object update only after it returned,
	 * so a failed custody write never leaves the object claiming a token the leaf
	 * does not hold.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param string $scope The credential scope.
	 * @param OAuth2TokenSet $set The set to store.
	 * @param array<string, mixed> $extraMetadata Additional non-secret properties to write on the object.
	 *
	 * @return void
	 *
	 * @throws Throwable When the custody write fails; the object is then left untouched.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	public function persist(string $credentialId, string $scope, OAuth2TokenSet $set, array $extraMetadata = []): void {
		$this->credentialStore->put($credentialId, $set->toStoredJson(), $scope);

		$this->writeMetadata(
			credentialId: $credentialId,
			metadata: array_merge(
				[
					'status' => 'active',
					'scopes' => $set->getScopes(),
					'account' => $set->getAccount(),
					'expiresAt' => $set->getExpiresAt()?->format(DATE_ATOM),
					'lastRefreshedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
					'lastError' => '',
				],
				$extraMetadata
			)
		);
	}//end persist()

	/**
	 * Refuse a credential whose status already says it cannot be used.
	 *
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param string $credentialId The credential UUID.
	 *
	 * @return void
	 *
	 * @throws CredentialRelinkRequiredException When the credential is `relink_needed` or `disabled`.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	private function assertNotBlocked(array $credential, string $credentialId): void {
		$status = (string)($credential['status'] ?? 'active');
		if (in_array($status, self::BLOCKING_STATUSES, true) === true) {
			throw new CredentialRelinkRequiredException(
				message: 'credential ' . $credentialId . ' is ' . $status . ' and must be re-authorised'
			);
		}
	}//end assertNotBlocked()

	/**
	 * Exchange the refresh token and store the result.
	 *
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param string $credentialId The credential UUID.
	 * @param string $scope The credential scope.
	 * @param OAuth2TokenSet $current The set being replaced.
	 * @param string|null $actingUserId The asserted user for a sessionless caller.
	 *
	 * @return OAuth2TokenSet The rotated set.
	 *
	 * @throws CredentialRelinkRequiredException When the provider says the grant is gone.
	 * @throws CredentialUpstreamException When the exchange failed for any other reason.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	private function rotate(
		array $credential,
		array $provider,
		string $credentialId,
		string $scope,
		OAuth2TokenSet $current,
		?string $actingUserId,
	): OAuth2TokenSet {
		$refreshToken = $current->getRefreshToken();
		if ($refreshToken === null) {
			$this->markRelinkNeeded(credential: $credential, credentialId: $credentialId, reason: 'no_refresh_token');
			throw new CredentialRelinkRequiredException(message: 'credential ' . $credentialId . ' has no refresh token');
		}

		$oauth2 = [];
		if (is_array(($provider['oauth2'] ?? null)) === true) {
			$oauth2 = $provider['oauth2'];
		}

		$client = $this->clients->resolve(
			credential: $credential,
			provider: (string)($credential['provider'] ?? ''),
			actingUserId: $actingUserId
		);

		$response = $this->exchange(
			endpoint: $this->tokenEndpoint(oauth2: $oauth2, credential: $credential, credentialId: $credentialId),
			form: $this->refreshForm(oauth2: $oauth2, refreshToken: $refreshToken, client: $client),
			client: $client,
			credential: $credential,
			credentialId: $credentialId
		);

		$rotated = OAuth2TokenSet::fromTokenResponse(
			response: $response,
			requestedScopes: $current->getScopes(),
			previous: $current
		);

		$this->persist(credentialId: $credentialId, scope: $scope, set: $rotated);

		return $rotated;
	}//end rotate()

	/**
	 * Build the refresh request's form body.
	 *
	 * @param array<string, mixed> $oauth2 The provider entry's `oauth2` block.
	 * @param string $refreshToken The refresh token being spent.
	 * @param array{clientId: string, clientSecret: string|null} $client The resolved client.
	 *
	 * @return array<string, string> The form fields.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	private function refreshForm(array $oauth2, string $refreshToken, array $client): array {
		$grant = (string)($oauth2['refreshGrant'] ?? 'refresh_token');

		// Meta has no refresh_token grant: a short-lived token is traded for a
		// long-lived one against the same endpoint, with the CURRENT token in a
		// differently named field. Naming the grant in the catalogue is what lets
		// one code path serve both shapes.
		$form = ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken];
		if ($grant === 'fb_exchange_token') {
			$form = ['grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $refreshToken];
		}

		$form['client_id'] = $client['clientId'];
		if ($client['clientSecret'] !== null && (string)($oauth2['clientAuth'] ?? '') !== 'client_secret_basic') {
			$form['client_secret'] = $client['clientSecret'];
		}

		return $form;
	}//end refreshForm()

	/**
	 * Resolve the absolute token endpoint for a provider.
	 *
	 * @param array<string, mixed> $oauth2 The provider entry's `oauth2` block.
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param string $credentialId The credential UUID.
	 *
	 * @return string The absolute token endpoint URL.
	 *
	 * @throws CredentialAccessDeniedException When the catalogue names no usable token endpoint.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-broker/spec.md#requirement-the-catalogue-may-describe-an-oauth2-provider
	 */
	private function tokenEndpoint(array $oauth2, array $credential, string $credentialId): string {
		$endpoint = trim((string)($oauth2['tokenEndpoint'] ?? ''));
		if ($endpoint === '') {
			throw new CredentialAccessDeniedException(message: 'no token endpoint in the catalogue for credential ' . $credentialId);
		}

		if (($oauth2['endpointsRelativeToInstance'] ?? false) !== true) {
			return $endpoint;
		}

		try {
			$origin = OAuth2InstanceHost::normalise(candidate: (string)($credential['instanceBaseUrl'] ?? ''));
		} catch (InvalidArgumentException $badHost) {
			throw new CredentialAccessDeniedException(
				message: 'credential ' . $credentialId . ' has no usable instance host: ' . $badHost->getMessage(),
				previous: $badHost
			);
		}

		return $origin . '/' . ltrim($endpoint, '/');
	}//end tokenEndpoint()

	/**
	 * Perform the token request and decode its response.
	 *
	 * @param string $endpoint The absolute token endpoint.
	 * @param array<string, string> $form The form body.
	 * @param array{clientId: string, clientSecret: string|null} $client The resolved client.
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param string $credentialId The credential UUID.
	 *
	 * @return array<string, mixed> The decoded token response.
	 *
	 * @throws CredentialRelinkRequiredException When the provider answered `invalid_grant`.
	 * @throws CredentialUpstreamException On any other failure.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	private function exchange(string $endpoint, array $form, array $client, array $credential, string $credentialId): array {
		$options = ['body' => $form, 'headers' => ['Accept' => 'application/json'], 'timeout' => 20];
		if ($client['clientSecret'] !== null && isset($form['client_secret']) === false) {
			$options['auth'] = [$client['clientId'], $client['clientSecret']];
		}

		try {
			$response = $this->clientService->newClient()->post($endpoint, $options);
			$decoded = json_decode((string)$response->getBody(), true);
		} catch (Throwable $failure) {
			$error = $this->errorCodeOf(throwable: $failure);
			if ($error === self::TERMINAL_ERROR) {
				$this->markRelinkNeeded(credential: $credential, credentialId: $credentialId, reason: $error);
				throw new CredentialRelinkRequiredException(message: 'refresh refused: ' . $error, previous: $failure);
			}

			throw new CredentialUpstreamException(
				message: 'token endpoint unreachable or failing: ' . $failure::class,
				previous: $failure
			);
		}//end try

		if (is_array($decoded) === false) {
			throw new CredentialUpstreamException(message: 'token endpoint returned a body that is not JSON');
		}

		$error = (string)($decoded['error'] ?? '');
		if ($error === self::TERMINAL_ERROR) {
			$this->markRelinkNeeded(credential: $credential, credentialId: $credentialId, reason: $error);
			throw new CredentialRelinkRequiredException(message: 'refresh refused: ' . $error);
		}

		if ($error !== '') {
			throw new CredentialUpstreamException(message: 'token endpoint returned error ' . $error);
		}

		return $decoded;
	}//end exchange()

	/**
	 * Read an OAuth2 error code out of a transport failure, without quoting the body.
	 *
	 * A `invalid_grant` commonly arrives as an HTTP 400 with a JSON body, which the
	 * NC client raises rather than returns. Reading the code out of it is what keeps
	 * a revoked grant from being misfiled as a transient failure and retried forever.
	 *
	 * @param Throwable $throwable The transport failure.
	 *
	 * @return string The OAuth2 error code, or an empty string when there is none.
	 *
	 * @spec exclude private error-code extraction; asserted through exchange()'s terminal and transient branches
	 */
	private function errorCodeOf(Throwable $throwable): string {
		$body = '';
		if (method_exists($throwable, 'getResponse') === true) {
			$response = $throwable->getResponse();
			if ($response !== null && method_exists($response, 'getBody') === true) {
				$body = (string)$response->getBody();
			}
		}

		if ($body === '') {
			return '';
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			return '';
		}

		return (string)($decoded['error'] ?? '');
	}//end errorCodeOf()

	/**
	 * Return null for an empty string, so an absent owner is not asserted as one.
	 *
	 * @param string $value The candidate.
	 *
	 * @return string|null The value, or null when it is empty.
	 *
	 * @spec exclude private coercion helper with no behaviour of its own
	 */
	private function nullIfEmpty(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullIfEmpty()

	/**
	 * Move a credential to `relink_needed`, announce it, and tell its owner.
	 *
	 * Best-effort on the announcement side: the caller is already raising a typed
	 * exception, and a notification backend that is down must not turn a recoverable
	 * relink into an unhandled error. The status write is what actually matters, and
	 * it is what makes this happen once rather than on every attempt.
	 *
	 * @param array<string, mixed> $credential The credential object's serialised data.
	 * @param string $credentialId The credential UUID.
	 * @param string $reason The provider's OAuth2 error code.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	private function markRelinkNeeded(array $credential, string $credentialId, string $reason): void {
		$this->writeMetadata(
			credentialId: $credentialId,
			metadata: ['status' => 'relink_needed', 'lastError' => $reason]
		);

		$this->relinkNotifier->announce(
			credentialId: $credentialId,
			provider: (string)($credential['provider'] ?? ''),
			owner: (string)($credential['owner'] ?? ''),
			reason: $reason
		);
	}//end markRelinkNeeded()

	/**
	 * Write non-secret metadata back onto the credential object.
	 *
	 * Runs with RBAC and multitenancy off for the same reason `mint()` does: the
	 * caller may be a background sweep with no session, and the authorization
	 * decision was already taken by the guard chain that admitted the credential.
	 *
	 * @param string $credentialId The credential UUID.
	 * @param array<string, mixed> $metadata The properties to merge onto the object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
	 */
	private function writeMetadata(string $credentialId, array $metadata): void {
		try {
			$existing = $this->objectService->find(
				id: $credentialId,
				register: CredentialBrokerService::REGISTER,
				schema: CredentialBrokerService::SCHEMA,
				_rbac: false,
				_multitenancy: false,
				_render: false
			);

			if ($existing === null) {
				return;
			}

			$data = $existing->jsonSerialize();
			unset($data['@self']);

			$this->objectService->saveObject(
				object: array_merge($data, $metadata),
				register: CredentialBrokerService::REGISTER,
				schema: CredentialBrokerService::SCHEMA,
				uuid: $credentialId,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $writeFailure) {
			// The token set is already safe in the custody leaf, which is the store
			// that matters; a stale mirror on the object is corrected by the next
			// read. Failing the whole call here would turn a cosmetic problem into
			// an outage.
			$this->logger->warning(
				'[OAuth2RefreshService] could not update credential metadata for ' . $credentialId
				. ': ' . $writeFailure->getMessage()
			);
		}//end try
	}//end writeMetadata()
}//end class
