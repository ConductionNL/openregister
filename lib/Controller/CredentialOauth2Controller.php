<?php

/**
 * CredentialOauth2Controller — the authorise, callback and mint dance.
 *
 * ONE CONTROLLER PLAYS BOTH ROLES. `callback()` reads the receiving callback out of
 * the state and compares it to this instance's own: the same means receive, a
 * different one means relay. There is no mode flag and no deployment switch, so a
 * Conduction-hosted relay is an ordinary OpenRegister install whose administrator
 * populated the allow-list, and the relay path is exercised by the same tests every
 * tenant's code runs.
 *
 * The two roles check DIFFERENT things, and the split is deliberate rather than a
 * gap. The relay cannot verify a signature it does not hold the key for, so it
 * checks only that the destination is on its administrator-managed allow-list and
 * forwards; it exchanges nothing and mints nothing. The receiving instance verifies
 * the HMAC with its own key, checks the expiry, consumes the nonce, and looks up the
 * PKCE verifier it stored at start. So a code can only ever be exchanged by the
 * instance that began the flow, whether the relay is honest, compromised or absent.
 *
 * THROTTLING FOLLOWS ADR-082'S TWO-HALVES RULE. The attribute alone does nothing:
 * `sleepDelayOrThrowOnMax()` runs on the way in, but the delay only grows if
 * somebody registers the failed attempts. Every rejection branch below therefore
 * calls `registerAttempt()`. The ADR's own finding was that openregister had 23
 * public endpoints and zero brute-force machinery; adding one whose only credential
 * is a signed opaque string without registering its failures would repeat that.
 *
 * Failures are UNIFORM. The callback never says which check failed and never
 * forwards the provider's own error text, because either would be an oracle for
 * forging a state.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectionRepository;
use OCA\OpenRegister\Service\Credential\OAuth2ConnectService;
use OCA\OpenRegister\Service\Credential\OAuth2InstanceHost;
use OCA\OpenRegister\Service\Credential\OAuth2RelayGuard;
use OCA\OpenRegister\Service\Credential\OAuth2StateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Connects, reconnects and disconnects a tenant's OAuth2 accounts.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A connect endpoint is by nature the
 * seam between the connect service, the flow state, the relay guard, the throttler
 * and the URL generator, plus the response types it returns. Three collaborators were
 * already moved out into {@see OAuth2ConnectionRepository} while writing this; what
 * remains is what a connect genuinely touches.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class is one request shape
 * with a long list of ways to refuse it, and every refusal is a separate branch on
 * purpose: a callback that answered fewer questions would be answering some of them
 * by accident.
 * @SuppressWarnings(PHPMD.StaticAccess) One call, to {@see OAuth2InstanceHost}, a
 * stateless security predicate; injecting it would make the host-lock substitutable.
 */
class CredentialOauth2Controller extends Controller {
	/**
	 * The brute-force action every failed callback is registered under.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'openregisterOauth2Callback';

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The current request.
	 * @param OAuth2ConnectService $connect Builds authorization URLs and redeems codes.
	 * @param OAuth2StateService $states Issues and redeems the signed state.
	 * @param OAuth2RelayGuard $relay Decides where a relay may forward.
	 * @param OAuth2ConnectionRepository $connections Loads, gates and disables a stored connection.
	 * @param IUserSession $userSession The current session.
	 * @param IURLGenerator $urlGenerator Builds this instance's own callback and metadata URLs.
	 * @param IThrottler $throttler Registers failed callback attempts (ADR-082).
	 * @param LoggerInterface $logger Secret-free diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly OAuth2ConnectService $connect,
		private readonly OAuth2StateService $states,
		private readonly OAuth2RelayGuard $relay,
		private readonly OAuth2ConnectionRepository $connections,
		private readonly IUserSession $userSession,
		private readonly IURLGenerator $urlGenerator,
		private readonly IThrottler $throttler,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * POST /api/credentials/oauth2/start — begin connecting an account.
	 *
	 * @return JSONResponse `{authorizationUrl, expiresIn}`, or a static error.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	#[NoAdminRequired]
	public function start(): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$providerId = (string)$this->request->getParam('provider', '');
		$requestedScope = (string)$this->request->getParam('scope', 'personal');

		try {
			$provider = $this->connect->oauth2Provider(providerId: $providerId);
			$organisation = $this->connections->gatedOrganisation(uid: $uid, requestedScope: $requestedScope);
			$host = $this->requestedHost(provider: $provider);
			$claims = $this->buildClaims(
				uid: $uid,
				providerId: $providerId,
				provider: $provider,
				requestedScope: $requestedScope,
				organisation: $organisation,
				host: $host
			);
		} catch (InvalidArgumentException $invalid) {
			return new JSONResponse(['message' => 'Invalid connection request'], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $refused) {
			return new JSONResponse(['message' => 'Connection not permitted'], Http::STATUS_FORBIDDEN);
		}

		try {
			// A per-instance provider has no application to bring, so one is created at
			// the account's own server HERE, before the URL that names its client id is
			// built. The client secret it issues goes straight to the broker as its own
			// credential; only the non-secret client id travels on in the claims.
			$claims = $this->connect->ensureInstanceClient(
				provider: $provider,
				claims: $claims,
				redirectUri: $this->ownCallbackUrl()
			);
			$issued = $this->states->issue(claims: $claims);
			$url = $this->connect->authorizationUrl(
				provider: $provider,
				claims: $claims,
				redirectUri: $this->ownCallbackUrl(),
				state: $issued['state'],
				challenge: $issued['challenge']
			);
		} catch (Throwable $failure) {
			$this->logger->warning('[CredentialOauth2Controller] could not start a connection: ' . $failure->getMessage());

			return new JSONResponse(['message' => 'Unable to start the connection'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['authorizationUrl' => $url, 'expiresIn' => OAuth2StateService::STATE_TTL_SECONDS]);
	}//end start()

	/**
	 * GET /oauth2/callback — receive a provider's redirect, or relay it onward.
	 *
	 * @return RedirectResponse|JSONResponse A redirect to the return URL or the relay target, or a static error.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function callback(): RedirectResponse | JSONResponse {
		$state = (string)$this->request->getParam('state', '');
		$code = (string)$this->request->getParam('code', '');

		$shape = $this->states->parseUnverified(state: $state);
		if ($shape === null || $code === '') {
			return $this->refuse();
		}

		$destination = (string)($shape['cb'] ?? '');
		if ($destination !== '' && rtrim($destination, '/') !== rtrim($this->ownCallbackUrl(), '/')) {
			return $this->forward(destination: $destination, code: $code, state: $state);
		}

		$redeemed = $this->states->consume(state: $state);
		if ($redeemed === null) {
			return $this->refuse();
		}

		$returnUrl = $this->safeReturnUrl(candidate: (string)($redeemed['claims']['r'] ?? ''));

		try {
			$this->connect->complete(
				claims: $redeemed['claims'],
				code: $code,
				verifier: $redeemed['verifier'],
				redirectUri: $this->ownCallbackUrl()
			);
		} catch (Throwable $failure) {
			// Nothing was written: complete() mints only once the token response is
			// in hand. Report failure without echoing the provider's own words.
			$this->logger->warning('[CredentialOauth2Controller] connect failed: ' . $failure->getMessage());

			return new RedirectResponse($returnUrl . '?connected=failed');
		}

		return new RedirectResponse($returnUrl . '?connected=ok');
	}//end callback()

	/**
	 * DELETE /api/credentials/oauth2/{id} — revoke upstream and disable locally.
	 *
	 * @param string $id The credential UUID.
	 *
	 * @return JSONResponse The disabled credential's status, or a static error.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
	 */
	#[NoAdminRequired]
	public function disconnect(string $id): JSONResponse {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$entity = $this->connections->findManageable(credentialId: $id, uid: $uid);
		if ($entity === null) {
			return new JSONResponse(['message' => 'Request not permitted'], Http::STATUS_FORBIDDEN);
		}

		$data = $entity->jsonSerialize();
		unset($data['@self']);

		$lastError = '';
		try {
			$lastError = $this->connect->revokeUpstream(
				provider: $this->connect->oauth2Provider(providerId: (string)($data['provider'] ?? '')),
				credential: $data,
				credentialId: $id,
				scope: (string)($data['scope'] ?? 'personal')
			);
		} catch (Throwable $failure) {
			// An unreachable or unknown provider must not keep a tenant connected.
			$lastError = 'revoke_failed';
		}

		try {
			$this->connections->disable(credentialId: $id, data: $data, lastError: $lastError);
		} catch (Throwable $failure) {
			return new JSONResponse(['message' => 'Unable to disconnect'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['status' => 'disabled', 'revoked' => ($lastError === '')]);
	}//end disconnect()

	/**
	 * GET /oauth2/client-metadata.json — this instance's own AT Protocol client metadata.
	 *
	 * AT Protocol has no client registry: a client IS the JSON document it publishes,
	 * and its client identifier is that document's own URL. So the tenant's own
	 * Nextcloud is its own OAuth client and needs no relay at all.
	 *
	 * @return JSONResponse The client metadata document.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function clientMetadata(): JSONResponse {
		$metadataUrl = $this->urlGenerator->linkToRouteAbsolute('openregister.credentialOauth2.clientMetadata');

		return new JSONResponse(
			[
				'client_id' => $metadataUrl,
				'client_name' => 'Nextcloud OpenRegister',
				'client_uri' => $this->urlGenerator->getAbsoluteURL('/'),
				'redirect_uris' => [$this->ownCallbackUrl()],
				'grant_types' => ['authorization_code', 'refresh_token'],
				'response_types' => ['code'],
				'scope' => 'atproto transition:generic',
				'token_endpoint_auth_method' => 'none',
				'application_type' => 'web',
				'dpop_bound_access_tokens' => true,
			]
		);
	}//end clientMetadata()

	/**
	 * Relay a code onward, without exchanging it.
	 *
	 * @param string $destination The receiving instance's callback, from the state.
	 * @param string $code The authorization code.
	 * @param string $state The state, forwarded unchanged.
	 *
	 * @return RedirectResponse|JSONResponse The forward, or a refusal.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
	 */
	private function forward(string $destination, string $code, string $state): RedirectResponse | JSONResponse {
		if ($this->relay->permits(callbackUrl: $destination) === false) {
			return $this->refuse();
		}

		return new RedirectResponse(
			$destination . '?' . http_build_query(['code' => $code, 'state' => $state])
		);
	}//end forward()

	/**
	 * Refuse a callback uniformly, registering the attempt.
	 *
	 * @return JSONResponse The static refusal.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	private function refuse(): JSONResponse {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (Throwable $throttlerFailure) {
			$this->logger->warning('[CredentialOauth2Controller] registerAttempt failed: ' . $throttlerFailure->getMessage());
		}

		// Uniform, and deliberately silent about which check failed.
		return new JSONResponse(['message' => 'Bad Request'], Http::STATUS_BAD_REQUEST);
	}//end refuse()

	/**
	 * Assemble the claims a state carries.
	 *
	 * @param string $uid The initiating user.
	 * @param string $providerId The catalogue provider identifier.
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 * @param string $requestedScope The requested credential scope.
	 * @param string|null $organisation The gated organisation, for an organisation-scoped connect.
	 * @param string|null $host The validated per-account host, when the provider has one.
	 *
	 * @return array<string, mixed> The claims, plus a `_credential` shape for client resolution.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
	 */
	private function buildClaims(
		string $uid,
		string $providerId,
		array $provider,
		string $requestedScope,
		?string $organisation,
		?string $host,
	): array {
		$clientRef = trim((string)$this->request->getParam('credentialRef', ''));
		$clientId = trim((string)$this->request->getParam('clientId', ''));
		$reauthorise = trim((string)$this->request->getParam('credentialId', ''));

		if ($reauthorise !== '' && $this->connections->findManageable(credentialId: $reauthorise, uid: $uid) === null) {
			throw new InvalidArgumentException(message: 'the credential named for re-authorisation is not manageable by this caller');
		}

		$scopes = $this->request->getParam('scopes');
		if (is_array($scopes) === false || $scopes === []) {
			$scopes = ($provider['oauth2']['defaultScopes'] ?? []);
		}

		return [
			'u' => $uid,
			'p' => $providerId,
			's' => $requestedScope,
			'o' => $organisation,
			'h' => $host,
			'sc' => array_values(array_map('strval', $scopes)),
			'a' => $this->requestedAllowedApps(),
			'nm' => trim((string)$this->request->getParam('name', $providerId)),
			'cl' => $clientId,
			'cr' => $clientRef,
			'cid' => $reauthorise,
			'r' => $this->safeReturnUrl(candidate: (string)$this->request->getParam('returnUrl', '')),
			'cb' => $this->ownCallbackUrl(),
		];
	}//end buildClaims()

	/**
	 * The per-account host a start supplied, validated, or null when the provider has none.
	 *
	 * @param array<string, mixed> $provider The catalogue provider entry.
	 *
	 * @return string|null The normalised origin, or null.
	 *
	 * @throws InvalidArgumentException When the provider needs a host and none was supplied or it is unsafe.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-per-account-host-is-pinned-at-mint-and-immutable-afterwards
	 */
	private function requestedHost(array $provider): ?string {
		if (trim((string)($provider['baseUrlFrom'] ?? '')) === '') {
			return null;
		}

		return OAuth2InstanceHost::normalise(candidate: (string)$this->request->getParam('instanceBaseUrl', ''));
	}//end requestedHost()

	/**
	 * The app ids a start asked to grant the new connection to.
	 *
	 * @return array<int, string> The app ids.
	 *
	 * @spec exclude private request accessor with no behaviour of its own
	 */
	private function requestedAllowedApps(): array {
		$apps = $this->request->getParam('allowedApps', []);
		if (is_array($apps) === false) {
			return [];
		}

		$normalised = [];
		foreach ($apps as $app) {
			$candidate = trim((string)$app);
			if ($candidate !== '' && preg_match('/^[a-z0-9_-]+$/', $candidate) === 1) {
				$normalised[] = $candidate;
			}
		}

		return $normalised;
	}//end requestedAllowedApps()

	/**
	 * This instance's own callback URL.
	 *
	 * @return string The absolute callback URL.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-relay-forwards-a-code-and-never-exchanges-it
	 */
	private function ownCallbackUrl(): string {
		return $this->urlGenerator->linkToRouteAbsolute('openregister.credentialOauth2.callback');
	}//end ownCallbackUrl()

	/**
	 * Reduce a proposed return URL to one on this instance.
	 *
	 * An attacker-chosen redirect target on a callback is an open redirect. The value
	 * is taken at start from an authenticated caller, reduced to a path here, and then
	 * carried inside the SIGNED state, so the callback only ever redirects to
	 * somewhere the instance itself approved.
	 *
	 * @param string $candidate The proposed return URL.
	 *
	 * @return string An absolute URL on this instance.
	 *
	 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
	 */
	private function safeReturnUrl(string $candidate): string {
		$fallback = $this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'additional']);

		$candidate = trim($candidate);
		if ($candidate === '') {
			return $fallback;
		}

		$parts = parse_url($candidate);
		if (is_array($parts) === false || isset($parts['host']) === true || isset($parts['scheme']) === true) {
			return $fallback;
		}

		$path = (string)($parts['path'] ?? '');
		if (str_starts_with($path, '/') === false) {
			return $fallback;
		}

		return $this->urlGenerator->getAbsoluteURL($path);
	}//end safeReturnUrl()

}//end class
