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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\WriteCause;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Refreshes and rotates OAuth2 token sets on behalf of the broker.
 *
 * The token request itself, and the classification of its failures, live in
 * {@see OAuth2RefreshExchange}; this class owns the lock, the custody write and
 * the relink bookkeeping around it.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) One static call, to a named constructor:
 * {@see OAuth2TokenSet} decodes a stored document and has no instance to call.
 */
class OAuth2RefreshService implements OAuth2CredentialSweepInterface {
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
	 * Constructor.
	 *
	 * @param CredentialStore $credentialStore Holds the token set; the only place it is ever written.
	 * @param ObjectOperationsInterface $objectService Updates the credential's non-secret metadata.
	 * @param CredentialLock $lock Excludes a second concurrent rotation of one credential.
	 * @param OAuth2RefreshExchange $exchange Spends the refresh token and classifies the answer.
	 * @param CredentialRelinkNotifier $relinkNotifier Announces a lost grant to the instance and its owner.
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CredentialStore $credentialStore,
		private readonly ObjectOperationsInterface $objectService,
		private readonly CredentialLock $lock,
		private readonly OAuth2RefreshExchange $exchange,
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
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-brokered-call-refreshes-an-expiring-token-set-before-it-is-used
	 */
	public function accessTokenFor(array $credential, array $provider, string $credentialId, string $scope, ?string $actingUserId=null): string {
		$this->assertNotBlocked(credential: $credential, credentialId: $credentialId);

		$set = $this->storedSet(credentialId: $credentialId, scope: $scope);
		if ($set->needsRefresh(marginSeconds: self::REFRESH_MARGIN_SECONDS) === false) {
			return $set->getAccessToken();
		}

		$target = new OAuth2RefreshTarget(
			credential: $credential, provider: $provider, credentialId: $credentialId, scope: $scope, actingUserId: $actingUserId
		);
		if ($this->lock->acquire($credentialId) === false) {
			return $this->afterContention(target: $target);
		}

		return $this->rotateLocked(target: $target, current: $set)->getAccessToken();
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
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-daily-job-refreshes-active-token-sets-before-they-expire
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

		$owner = $this->nullIfEmpty(value: trim((string)($credential['owner'] ?? '')));
		$target = new OAuth2RefreshTarget(credential: $credential, provider: $provider, credentialId: $credentialId, scope: $scope, actingUserId: $owner);
		$this->rotateLocked(target: $target, current: $set);

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
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
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
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	public function persist(string $credentialId, string $scope, OAuth2TokenSet $set, array $extraMetadata = []): void {
		// The secret is kept under the uuid of the stored credential, not under the identifier as sent.
		$uuid = (string) $this->storedCredential(credentialId: $credentialId)->getUuid();
		$this->credentialStore->put($uuid, $set->toStoredJson(), $scope);

		$this->writeMetadata(credentialId: $uuid, metadata: array_merge($this->mirroredMetadata(set: $set), $extraMetadata));
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
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
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
	 * Finish a refresh another rotation was holding the lock for.
	 *
	 * Somebody else is rotating this credential. Wait for them, then read what they
	 * stored rather than starting a second exchange; after a successful rotation
	 * the re-read is outside the margin and there is nothing left to do.
	 *
	 * @param OAuth2RefreshTarget $target The credential being refreshed.
	 *
	 * @return string The access token to inject.
	 *
	 * @throws CredentialUpstreamException When the lock is still held and the token is still expiring.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	private function afterContention(OAuth2RefreshTarget $target): string {
		$this->lock->waitForRelease($target->credentialId);
		$set = $this->storedSet(credentialId: $target->credentialId, scope: $target->scope);
		if ($set->needsRefresh(marginSeconds: self::REFRESH_MARGIN_SECONDS) === false) {
			return $set->getAccessToken();
		}

		if ($this->lock->acquire($target->credentialId) === false) {
			throw new CredentialUpstreamException(message: 'refresh lock is held and the token is still expiring');
		}

		return $this->rotateLocked(target: $target, current: $set)->getAccessToken();
	}//end afterContention()

	/**
	 * Rotate under a lock this call already holds, and release it whatever happens.
	 *
	 * @param OAuth2RefreshTarget $target The credential being refreshed.
	 * @param OAuth2TokenSet $current The set being replaced.
	 *
	 * @return OAuth2TokenSet The rotated set.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	private function rotateLocked(OAuth2RefreshTarget $target, OAuth2TokenSet $current): OAuth2TokenSet {
		try {
			return $this->rotate(target: $target, current: $current);
		} finally {
			$this->lock->release($target->credentialId);
		}
	}//end rotateLocked()

	/**
	 * Exchange the refresh token and store the result.
	 *
	 * @param OAuth2RefreshTarget $target The credential being refreshed.
	 * @param OAuth2TokenSet $current The set being replaced.
	 *
	 * @return OAuth2TokenSet The rotated set.
	 *
	 * @throws CredentialRelinkRequiredException When the provider says the grant is gone.
	 * @throws CredentialUpstreamException When the exchange failed for any other reason.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	private function rotate(OAuth2RefreshTarget $target, OAuth2TokenSet $current): OAuth2TokenSet {
		$refreshToken = $this->refreshTokenOf(target: $target, current: $current);
		$response = $this->redeemOrRelink(target: $target, refreshToken: $refreshToken);
		$rotated = OAuth2TokenSet::fromTokenResponse(response: $response, requestedScopes: $current->getScopes(), previous: $current);
		$this->persist(credentialId: $target->credentialId, scope: $target->scope, set: $rotated);

		return $rotated;
	}//end rotate()

	/**
	 * The refresh token to spend, moving a set that has none to `relink_needed`.
	 *
	 * @param OAuth2RefreshTarget $target The credential being refreshed.
	 * @param OAuth2TokenSet $current The set being replaced.
	 *
	 * @return string The refresh token.
	 *
	 * @throws CredentialRelinkRequiredException When the set carries no refresh token.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	private function refreshTokenOf(OAuth2RefreshTarget $target, OAuth2TokenSet $current): string {
		$refreshToken = $current->getRefreshToken();
		if ($refreshToken === null) {
			$this->markRelinkNeeded(credential: $target->credential, credentialId: $target->credentialId, reason: 'no_refresh_token');
			throw new CredentialRelinkRequiredException(message: 'credential ' . $target->credentialId . ' has no refresh token');
		}

		return $refreshToken;
	}//end refreshTokenOf()

	/**
	 * Spend the refresh token, moving the credential to `relink_needed` when the grant is gone.
	 *
	 * @param OAuth2RefreshTarget $target The credential being refreshed.
	 * @param string $refreshToken The refresh token being spent.
	 *
	 * @return array<string, mixed> The decoded token response.
	 *
	 * @throws CredentialRelinkRequiredException When the provider answered `invalid_grant`.
	 * @throws CredentialUpstreamException On any other failure.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
	 */
	private function redeemOrRelink(OAuth2RefreshTarget $target, #[\SensitiveParameter] string $refreshToken): array {
		try {
			return $this->exchange->redeem(
				credential: $target->credential,
				provider: $target->provider,
				credentialId: $target->credentialId,
				refreshToken: $refreshToken,
				actingUserId: $target->actingUserId
			);
		} catch (CredentialRelinkRequiredException $grantGone) {
			$this->markRelinkNeeded(credential: $target->credential, credentialId: $target->credentialId, reason: OAuth2RefreshExchange::TERMINAL_ERROR);
			throw $grantGone;
		}
	}//end redeemOrRelink()

	/**
	 * The non-secret metadata a stored token set is mirrored onto the object as.
	 *
	 * @param OAuth2TokenSet $set The set just stored.
	 *
	 * @return array<string, mixed> The metadata.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
	 */
	private function mirroredMetadata(OAuth2TokenSet $set): array {
		return [
			'status' => 'active',
			'scopes' => $set->getScopes(),
			'account' => $set->getAccount(),
			'expiresAt' => $set->getExpiresAt()?->format(DATE_ATOM),
			'lastRefreshedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			'lastError' => '',
		];
	}//end mirroredMetadata()

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
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-invalid-grant-moves-the-credential-to-relink-needed-and-fails-closed
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
	 * The stored credential an identifier names, past RBAC and multitenancy.
	 *
	 * @param string $credentialId The credential identifier.
	 *
	 * @return ObjectEntity The credential, which has a uuid to be written under.
	 *
	 * @throws RuntimeException When no credential with a uuid is stored under it.
	 * @throws Throwable When the lookup itself fails.
	 */
	private function storedCredential(string $credentialId): ObjectEntity {
		$stored = WriteCause::asLookup(fn () => $this->objectService->find(
			register: CredentialBrokerService::REGISTER,
			schema: CredentialBrokerService::SCHEMA,
			id: $credentialId,
			_render: false,
			_rbac: false,
			_multitenancy: false
		));

		if ($stored === null || (string) $stored->getUuid() === '') {
			throw new RuntimeException('The credential is not stored.');
		}

		return $stored;
	}//end storedCredential()

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
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
	 */
	private function writeMetadata(string $credentialId, array $metadata): void {
		try {
			$this->saveMerged(existing: $this->storedCredential(credentialId: $credentialId), metadata: $metadata);
		} catch (Throwable $writeFailure) {
			// The token set is already safe in the custody leaf, which is the store
			// that matters; a stale mirror on the object is corrected by the next
			// read. Failing the whole call here would turn a cosmetic problem into
			// an outage.
			$this->logger->warning('[OAuth2RefreshService] could not update credential metadata for ' . $credentialId . ': ' . $writeFailure->getMessage());
		}
	}//end writeMetadata()

	/**
	 * Save the object with the metadata merged over its stored properties.
	 *
	 * @param ObjectEntity $existing The stored credential.
	 * @param array<string, mixed> $metadata The properties to merge onto the object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
	 */
	private function saveMerged(ObjectEntity $existing, array $metadata): void {
		$data = $existing->jsonSerialize();
		unset($data['@self']);

		$this->objectService->saveObject(
			object: array_merge($data, $metadata),
			register: CredentialBrokerService::REGISTER,
			schema: CredentialBrokerService::SCHEMA,
			uuid: $existing->getUuid(),
			_rbac: false,
			_multitenancy: false
		);
	}//end saveMerged()
}//end class
