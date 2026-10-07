<?php

/**
 * Authorization Service for validating incoming API requests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OC\AppFramework\Middleware\Security\Exceptions\SecurityException;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Service\Audit\TokenContext;
use OCA\OpenRegister\Service\Audit\TokenIdentity;
use OCA\OpenRegister\Service\Consumer\ConsumerMapperSource;
use OCA\OpenRegister\Service\Consumer\ConsumerSource;
use OCA\OpenRegister\Service\Consumer\EndpointAllowList;
use OCA\OpenRegister\Service\Consumer\JwtValidator;
use OCA\OpenRegister\Service\Consumer\ResolvedConsumer;
use OCP\AppFramework\Http\Response;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Service class for handling authorization on incoming calls.
 *
 * Supports JWT (HMAC, RSA PKCS1 and RSA-PSS), Basic Auth, OAuth2 Bearer, and
 * API Key validation. Every entry point is public and takes an optional
 * {@see ConsumerSource}: OpenRegister's own consumer table by default, or the
 * caller's store (integriq keeps its consumers as objects of its own schema).
 * The consumer that authenticated is read back with getResolvedConsumer().
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 *
 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
 */
class AuthorizationService {

	/**
	 * Supported HMAC algorithms.
	 *
	 * @var string[]
	 */
	public const HMAC_ALGORITHMS = ['HS256', 'HS384', 'HS512'];

	/**
	 * Supported PKCS1 (RSA) algorithms.
	 *
	 * @var string[]
	 */
	public const PKCS1_ALGORITHMS = ['RS256', 'RS384', 'RS512'];

	/**
	 * Supported PSS (RSA-PSS) algorithms.
	 *
	 * @var string[]
	 */
	public const PSS_ALGORITHMS = ['PS256', 'PS384', 'PS512'];

	/**
	 * The consumer resolved for the current request, or null.
	 *
	 * @var ResolvedConsumer|null
	 */
	private ?ResolvedConsumer $resolvedConsumer = null;

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Nextcloud user manager
	 * @param IUserSession $userSession Nextcloud user session
	 * @param ConsumerMapper $consumerMapper Consumer database mapper
	 * @param \OCA\OpenRegister\Service\Rbac\TokenGrantSource|null $tokenGrantSource What the calling token may do
	 * @param TokenContext|null $tokenContext Carries the calling token to the audit writer
	 * @param ICacheFactory|null $cacheFactory Distributed cache for jti replay refusal
	 * @param IGroupManager|null $groupManager Group lookups for an endpoint's users/groups allow-list
	 * @param IRequest|null $request The current request (the OAuth Bearer guard)
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IUserSession $userSession,
		private readonly ConsumerMapper $consumerMapper,
		// BOTH SIDES OF THIS MERGE ADDED A NULLABLE-LAST PARAMETER and neither
		// replaces the other: `tokenGrantSource` answers what a token may DO,
		// `tokenContext` carries who presented it to the audit writer. Keeping
		// only one would have compiled, and quietly disabled the other's
		// feature on an authorisation path.
		private readonly ?\OCA\OpenRegister\Service\Rbac\TokenGrantSource $tokenGrantSource = null,
		private readonly ?TokenContext $tokenContext = null,
		private readonly ?ICacheFactory $cacheFactory = null,
		private readonly ?IGroupManager $groupManager = null,
		private readonly ?IRequest $request = null,
	) {

	}//end __construct()

	/**
	 * The consumer the last authorize call on this request authenticated, or null.
	 *
	 * JWT and consumer-backed API keys resolve a consumer; Basic, OAuth and
	 * rule-inline API keys authenticate a Nextcloud user and leave this null,
	 * so a caller keys rate limits on the client instead.
	 *
	 * @return ResolvedConsumer|null The consumer, with its store's own row in `record`.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function getResolvedConsumer(): ?ResolvedConsumer {
		return $this->resolvedConsumer;
	}//end getResolvedConsumer()

	/**
	 * The consumer source for a call: the caller's, or OpenRegister's own table.
	 *
	 * @param ConsumerSource|null $consumers The caller's source.
	 *
	 * @return ConsumerSource
	 */
	private function sourceFor(?ConsumerSource $consumers): ConsumerSource {
		return $consumers ?? new ConsumerMapperSource(consumers: $this->consumerMapper);
	}//end sourceFor()

	/**
	 * Make the user the acting user for THIS request only.
	 *
	 * `setUser()` also writes the user into the PHP session, so a credential
	 * call carrying a browser session cookie would reassign that browser's
	 * session to the credential's user (ADR-099). The volatile setter persists
	 * nothing.
	 *
	 * @param IUser|null $user The user.
	 *
	 * @return void
	 */
	private function actAs(?IUser $user): void {
		$this->userSession->setVolatileActiveUser($user);
	}//end actAs()

	/**
	 * Tell the audit writer which consumer's token opened this request.
	 *
	 * The authorisation layer is the ONLY place that knows this. A JWT presents
	 * no Nextcloud app password, so the resolver behind TokenContext finds
	 * nothing to work with, and by the time the save path writes an audit row
	 * the issuer is long out of scope. Without this call a koppeling
	 * authenticating by JWT writes rows that cannot say which koppeling wrote
	 * them, which is the whole question the attribution exists to answer.
	 *
	 * Optional and fail-soft on purpose: this is bookkeeping attached to an
	 * authorisation path, and a container without the context registered must
	 * still be able to authorise a call.
	 *
	 * @param ResolvedConsumer $consumer The issuer whose credential was accepted.
	 * @param string $mechanism How it authenticated.
	 * @param string|null $reference The credential's own identifier, never its value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function claimConsumerToken(ResolvedConsumer $consumer, string $mechanism, ?string $reference): void {
		if ($this->tokenContext === null) {
			return;
		}

		$this->tokenContext->claim(
			new TokenIdentity(
				mechanism: $mechanism,
				reference: $reference,
				name: $consumer->name,
				ownerUid: $consumer->userId,
				ownerName: null,
				consumerUuid: $consumer->uuid,
				consumerName: $consumer->name,
			)
		);
	}//end claimConsumerToken()

	/**
	 * Find the consumer for a given JWT issuer.
	 *
	 * @param string         $issuer    The issuer from the JWT token.
	 * @param ConsumerSource $consumers Where consumers live.
	 *
	 * @return ResolvedConsumer The consumer matching the issuer.
	 *
	 * @throws AuthenticationException Thrown if no issuer was found.
	 */
	private function findIssuer(string $issuer, ConsumerSource $consumers): ResolvedConsumer {
		$consumer = $consumers->findByIssuer(issuer: $issuer);

		if ($consumer === null) {
			throw new AuthenticationException(
				message: 'The issuer was not found',
				details: ['iss' => $issuer]
			);
		}

		return $consumer;
	}//end findIssuer()

	/**
	 * Validate data in the JWT payload.
	 *
	 * @param array $payload The payload of the JWT token.
	 *
	 * @return void
	 *
	 * Checks: iat present and not in the future beyond the clock skew; exp
	 * (default iat + 1 hour) not passed; nbf, when present, reached; jti, when
	 * present, not seen before (it is remembered until the token expires). A
	 * caller-supplied exp is not capped here: whether OpenRegister caps a
	 * token's lifetime is an open question (Q4).
	 *
	 * @throws AuthenticationException If the token is missing iat, expired, not yet valid, issued in the future or replayed.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function validatePayload(array $payload): void {
		(new JwtValidator(cacheFactory: $this->cacheFactory))->validateClaims(payload: $payload);
	}//end validatePayload()

	/**
	 * Checks if authorization header contains a valid JWT token.
	 *
	 * @param string              $authorization The authorization header value.
	 * @param ConsumerSource|null $consumers     Where the issuer is looked up (default: OpenRegister's consumers).
	 *
	 * @return void
	 *
	 * @throws AuthenticationException If the token is invalid.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 */
	public function authorizeJwt(string $authorization, ?ConsumerSource $consumers=null): void {
		$this->resolvedConsumer = null;
		$token = substr(string: $authorization, offset: strlen(string: 'Bearer '));

		$validator = new JwtValidator(cacheFactory: $this->cacheFactory);
		[$header, $payload] = $validator->decode(token: $token);

		$issuer = $this->findIssuer(issuer: (string)$payload['iss'], consumers: $this->sourceFor(consumers: $consumers));
		$authConf = $issuer->configuration;

		// The algorithm comes from the issuer's stored configuration, never
		// from the token header (algorithm confusion); RS/PS are verified
		// against the RSA public key and never fall through to HMAC.
		$validator->verifyPinned(token: $token, header: $header, configuration: $authConf);
		$validator->validateClaims(payload: $payload);

		// 🔴 THIS LINE IS THE ROW. Making the Consumer act AS its Nextcloud
		// user is what gives a supplier the handler's whole desk: the token
		// resolves to a person and inherits everything that person may do
		// (row Q13.20). Binding the Consumer's grant beside it turns the
		// principal into a filtered one — intersection, never substitution, so
		// it can only narrow what that user could already do.
		//
		// Bound BEFORE the user is set, so there is no window in which the
		// request is the user with no ceiling on it.
		$this->tokenGrantSource?->bindFromConsumer(
			storedAuthorization: $authConf,
			tokenId: (string)($issuer->uuid ?? $payload['iss'])
		);

		$this->resolvedConsumer = $issuer;
		$this->actAs(user: $this->userManager->get((string)$issuer->userId));

		// The JWT's own id when it carries one, so a single credential can be
		// revoked by name. The token itself is never passed on: the audit trail
		// is shipped off the instance and retained for years, and a credential
		// in it is a breach waiting for somebody to grep for it.
		$jti = null;
		if (isset($payload['jti']) === true && is_string($payload['jti']) === true && $payload['jti'] !== '') {
			$jti = $payload['jti'];
		}

		$this->claimConsumerToken(consumer: $issuer, mechanism: 'jwt', reference: ($jti ?? $issuer->uuid));

	}//end authorizeJwt()

	/**
	 * Authorize user based on HTTP Basic Auth.
	 *
	 * @param string $header The authorization header value
	 * @param array $users The users allowed to authenticate
	 * @param array $groups The groups allowed to authenticate
	 *
	 * @return void
	 *
	 * @throws AuthenticationException If credentials are invalid or the user is outside the allow-list.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 *
	 * @orphan-auth exclude cross-app entry point; caller is integriq's endpoint runtime (gate 23 gap 2)
	 */
	public function authorizeBasic(string $header, array $users = [], array $groups = []): void {
		$this->resolvedConsumer = null;
		$header = substr(string: $header, offset: strlen(string: 'Basic '));

		// Guard against malformed base64 (base64_decode returns false on
		// invalid input, which explode() cannot accept in PHP 8).
		$decode = base64_decode(string: $header, strict: true);
		if ($decode === false || str_contains($decode, ':') === false) {
			throw new AuthenticationException(message: 'Invalid username or password', details: []);
		}

		// Limit to 2 parts so a password containing ':' is preserved intact.
		[$username, $password] = explode(separator: ':', string: $decode, limit: 2);

		$user = $this->userManager->checkPassword($username, $password);

		if ($user === false) {
			throw new AuthenticationException(message: 'Invalid username or password', details: []);
		}

		(new EndpointAllowList(groupManager: $this->groupManager))->assertAllowed(user: $user, users: $users, groups: $groups);
		$this->actAs(user: $user);

	}//end authorizeBasic()

	/**
	 * Authorize user based on OAuth2 Bearer token.
	 *
	 * @param string $header The authorization header value
	 * @param array $users The users allowed to authenticate
	 * @param array $groups The groups allowed to authenticate
	 *
	 * @return void
	 *
	 * Nextcloud validates the Bearer token before this runs; this makes sure the
	 * request really carried one, so a plain session cookie plus a made-up
	 * header value is not taken for a token.
	 *
	 * @throws AuthenticationException If the token is invalid, the request carried no Bearer header, or the user is outside the allow-list.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 *
	 * @orphan-auth exclude cross-app entry point; caller is integriq's endpoint runtime (gate 23 gap 2)
	 */
	public function authorizeOAuth(string $header, array $users = [], array $groups = []): void {
		$this->resolvedConsumer = null;
		if (str_starts_with(haystack: $header, needle: 'Bearer') === false) {
			throw new AuthenticationException(
				message: 'Invalid method',
				details: ['reason' => 'The authentication method you are using is not allowed on this resource.']
			);
		}

		if (ltrim(substr($header, strlen('Bearer'))) === '') {
			throw new AuthenticationException(message: 'Invalid token', details: ['reason' => 'Bearer token value is empty.']);
		}

		// Fail closed without the request: the header is the only proof that
		// Nextcloud authenticated a token rather than a session cookie.
		$requestHeader = (string)$this->request?->getHeader('Authorization');
		if (str_starts_with($requestHeader, 'Bearer ') === false) {
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'OAuth endpoints require Bearer token authentication, not session cookie auth.']
			);
		}

		$user = $this->userSession->getUser();
		if ($this->userSession->isLoggedIn() === false || $user === null) {
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'The token you used has either expired or was not recognized as a valid token']
			);
		}

		(new EndpointAllowList(groupManager: $this->groupManager))->assertAllowed(user: $user, users: $users, groups: $groups);

	}//end authorizeOAuth()

	/**
	 * Authorize the Nextcloud user of the current browser session.
	 *
	 * For endpoints that accept a signed-in Nextcloud user. The request must
	 * pass Nextcloud's CSRF check, because an app's dispatch route is usually
	 * #[NoCSRFRequired] and would otherwise be forgeable from any origin. Fails
	 * closed when the request is not available.
	 *
	 * @param array $users  The users allowed (uid or e-mail); empty with empty groups means any user.
	 * @param array $groups The groups allowed.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException Without a signed-in user, without a passing CSRF check, or outside the allow-list.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 *
	 * @orphan-auth exclude cross-app entry point; caller is integriq's endpoint runtime (gate 23 gap 2)
	 */
	public function authorizeNcSession(array $users = [], array $groups = []): void {
		$this->resolvedConsumer = null;
		$user = $this->userSession->getUser();
		if ($this->userSession->isLoggedIn() === false || $user === null) {
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'This endpoint requires an authenticated Nextcloud session.']
			);
		}

		if ($this->request?->passesCSRFCheck() !== true) {
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'A same-origin request with a valid CSRF request token is required for session authentication.']
			);
		}

		(new EndpointAllowList(groupManager: $this->groupManager))->assertAllowed(user: $user, users: $users, groups: $groups);

	}//end authorizeNcSession()

	/**
	 * Add CORS headers to controller result.
	 *
	 * @param IRequest $request The incoming request
	 * @param Response $response The outgoing response
	 *
	 * @return Response The updated response.
	 *
	 * @throws SecurityException If CSRF-unsafe headers are detected.
	 *
	 * @psalm-suppress UndefinedClass SecurityException is a private Nextcloud internal class
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-3/tasks.md#task-5
	 */
	public function corsAfterController(IRequest $request, Response $response): Response {
		$origin = $request->getHeader('Origin');
		if (empty($origin) === false) {
			foreach ($response->getHeaders() as $header => $value) {
				if (strtolower(string: $header) === 'access-control-allow-credentials'
					&& strtolower(string: trim(string: $value)) === 'true'
				) {
					$msg = 'Access-Control-Allow-Credentials must not be set to true in order to prevent CSRF';
					throw new SecurityException($msg);
				}
			}

			$response->addHeader('Access-Control-Allow-Origin', $origin);
		}

		return $response;
	}//end corsAfterController()

	/**
	 * Authorize user based on API key.
	 *
	 * The endpoint's own keys (`key => uid`) are tried first; then a consumer
	 * whose configured `apiKey` equals the presented key, which is then the
	 * resolved consumer. Comparisons are constant-time; an empty key never
	 * matches.
	 *
	 * @param string              $header    The API key from the request header
	 * @param array               $keys      Map of valid API keys to user IDs
	 * @param ConsumerSource|null $consumers Where consumer keys are looked up (default: OpenRegister's consumers).
	 *
	 * @return void
	 *
	 * @throws AuthenticationException If the API key is invalid.
	 *
	 * @spec openspec/changes/authorization-service-public-hardened/specs/auth-system/spec.md
	 *
	 * @orphan-auth exclude cross-app entry point; caller is integriq's endpoint runtime (gate 23 gap 2)
	 */
	public function authorizeApiKey(string $header, array $keys, ?ConsumerSource $consumers=null): void {
		$this->resolvedConsumer = null;
		if ($header === '') {
			throw new AuthenticationException(message: 'Invalid API key', details: []);
		}

		foreach ($keys as $key => $userId) {
			if (hash_equals((string)$key, $header) === true) {
				$user = $this->userManager->get((string)$userId);
				if ($user === null) {
					throw new AuthenticationException(message: 'Invalid API key', details: []);
				}

				$this->actAs(user: $user);
				return;
			}
		}

		$consumer = $this->sourceFor(consumers: $consumers)->findByApiKey(apiKey: $header);
		if ($consumer === null) {
			throw new AuthenticationException(message: 'Invalid API key', details: []);
		}

		$this->resolvedConsumer = $consumer;
		if ((string)$consumer->userId !== '') {
			$this->actAs(user: $this->userManager->get((string)$consumer->userId));
		}

		$this->claimConsumerToken(consumer: $consumer, mechanism: 'apiKey', reference: $consumer->uuid);

	}//end authorizeApiKey()
}//end class
