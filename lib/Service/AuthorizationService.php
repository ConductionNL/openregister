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

use DateTime;
use OC\AppFramework\Middleware\Security\Exceptions\SecurityException;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCA\OpenRegister\Service\Audit\TokenContext;
use OCA\OpenRegister\Service\Audit\TokenIdentity;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Service class for handling authorization on incoming calls.
 *
 * Supports JWT (HMAC), Basic Auth, OAuth2 Bearer, and API Key validation.
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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
	 * Map of JWT algorithm names to hash_hmac algorithm strings.
	 *
	 * @var array<string, string>
	 */
	private const HMAC_MAP = [
		'HS256' => 'sha256',
		'HS384' => 'sha384',
		'HS512' => 'sha512',
	];

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager Nextcloud user manager
	 * @param IUserSession $userSession Nextcloud user session
	 * @param ConsumerMapper $consumerMapper Consumer database mapper
	 * @param \OCA\OpenRegister\Service\Rbac\TokenGrantSource|null $tokenGrantSource What the calling token may do
	 * @param TokenContext|null $tokenContext Carries the calling token to the audit writer
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
	) {

	}//end __construct()

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
	 * @param Consumer $consumer The issuer whose credential was accepted.
	 * @param string $mechanism How it authenticated.
	 * @param string|null $reference The credential's own identifier, never its value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-trail-shipped-and-purpose-bound/specs/enhanced-audit-trail/spec.md
	 */
	private function claimConsumerToken(Consumer $consumer, string $mechanism, ?string $reference): void {
		if ($this->tokenContext === null) {
			return;
		}

		$this->tokenContext->claim(
			new TokenIdentity(
				mechanism: $mechanism,
				reference: $reference,
				name: $consumer->getName(),
				ownerUid: $consumer->getUserId(),
				ownerName: null,
				consumerUuid: $consumer->getUuid(),
				consumerName: $consumer->getName(),
			)
		);
	}//end claimConsumerToken()

	/**
	 * Find the consumer for a given JWT issuer.
	 *
	 * @param string $issuer The issuer from the JWT token.
	 *
	 * @return Consumer The consumer matching the issuer.
	 *
	 * @throws AuthenticationException Thrown if no issuer was found.
	 */
	private function findIssuer(string $issuer): Consumer {
		$consumers = $this->consumerMapper->findAll(filters: ['name' => $issuer]);

		if (count(value: $consumers) === 0) {
			throw new AuthenticationException(
				message: 'The issuer was not found',
				details: ['iss' => $issuer]
			);
		}

		return $consumers[0];
	}//end findIssuer()

	/**
	 * Base64url-decode a string per RFC 7515.
	 *
	 * @param string $data The base64url-encoded string
	 *
	 * @return string The decoded data
	 */
	private function base64urlDecode(string $data): string {
		return base64_decode(strtr($data, '-_', '+/'));
	}//end base64urlDecode()

	/**
	 * Verify an HMAC JWT signature using PHP built-in functions.
	 *
	 * @param string $headerB64 The base64url-encoded header
	 * @param string $payloadB64 The base64url-encoded payload
	 * @param string $signature The raw signature bytes
	 * @param string $secret The HMAC shared secret
	 * @param string $algorithm The JWT algorithm (HS256, HS384, HS512)
	 *
	 * @return bool True if the signature is valid
	 */
	private function verifyHmac(
		string $headerB64,
		string $payloadB64,
		string $signature,
		string $secret,
		string $algorithm,
	): bool {
		$hashAlg = self::HMAC_MAP[$algorithm] ?? null;
		if ($hashAlg === null) {
			return false;
		}

		$expected = hash_hmac($hashAlg, $headerB64 . '.' . $payloadB64, $secret, true);

		return hash_equals($expected, $signature);
	}//end verifyHmac()

	/**
	 * Validate data in the JWT payload.
	 *
	 * @param array $payload The payload of the JWT token.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException If the token is expired or missing iat.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-3/tasks.md#task-5
	 */
	public function validatePayload(array $payload): void {
		$now = new DateTime();

		if (isset($payload['iat']) === false) {
			throw new AuthenticationException(
				message: 'The token has no time of creation',
				details: ['iat' => null]
			);
		}

		$iat = new DateTime('@' . $payload['iat']);

		$exp = clone $iat;
		$exp->modify('+1 Hour');
		if (isset($payload['exp']) === true) {
			$exp = new DateTime('@' . $payload['exp']);
		}

		if ($exp->diff($now)->format('%R') === '+') {
			throw new AuthenticationException(
				message: 'The token has expired',
				details: [
					'iat' => $iat->getTimestamp(),
					'exp' => $exp->getTimestamp(),
					'time checked' => $now->getTimestamp(),
				]
			);
		}

	}//end validatePayload()

	/**
	 * Checks if authorization header contains a valid JWT token.
	 *
	 * @param string $authorization The authorization header value.
	 *
	 * @return void
	 *
	 * @throws AuthenticationException If the token is invalid.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-3/tasks.md#task-5
	 */
	protected function authorizeJwt(string $authorization): void {
		$token = substr(string: $authorization, offset: strlen(string: 'Bearer '));

		if ($token === '') {
			throw new AuthenticationException(message: 'No token has been provided', details: []);
		}

		$parts = explode('.', $token);
		if (count($parts) !== 3) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'Invalid JWT format']
			);
		}

		[$headerB64, $payloadB64, $signatureB64] = $parts;

		$headerJson = $this->base64urlDecode(data: $headerB64);
		$header = json_decode($headerJson, true);
		if (is_array($header) === false || isset($header['alg']) === false) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'Invalid header']
			);
		}

		$payloadJson = $this->base64urlDecode(data: $payloadB64);
		$payload = json_decode($payloadJson, true);
		if (is_array($payload) === false) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'Invalid payload']
			);
		}

		if (isset($payload['iss']) === false || empty($payload['iss']) === true) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'No issuer mentioned']
			);
		}

		$issuer = $this->findIssuer(issuer: $payload['iss']);
		$authConf = $issuer->getAuthorizationConfiguration();

		$publicKey = $authConf['publicKey'] ?? '';

		// The verification algorithm MUST come from the issuer's server-side
		// configuration, never from the attacker-controlled token header. Taking
		// it from `$header['alg']` enables an algorithm-confusion attack: an
		// RS/PS-configured issuer (whose `publicKey` is, by definition, public)
		// could be verified via HMAC using that public key as the secret, letting
		// anyone forge a valid HS token. Reject when no algorithm is pinned.
		$algorithm = $authConf['algorithm'] ?? null;
		if (is_string($algorithm) === false || $algorithm === '') {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'No verification algorithm configured for issuer']
			);
		}

		// The token's declared algorithm MUST match the pinned one — an
		// asymmetric-configured issuer refuses an HMAC token and vice versa.
		if ($header['alg'] !== $algorithm) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'Token algorithm does not match issuer configuration']
			);
		}

		$signature = $this->base64urlDecode(data: $signatureB64);

		// Asymmetric algorithms (RS/PS) MUST be verified against the public key
		// with a real signature check — they MUST NOT fall through to HMAC. Until
		// an asymmetric verifier is implemented (see the
		// fix-jwt-algorithm-confusion change, tasks 2.1/2.2), fail closed rather
		// than HMAC-verify with the public key.
		if (in_array($algorithm, self::PKCS1_ALGORITHMS, true) === true
			|| in_array($algorithm, self::PSS_ALGORITHMS, true) === true
		) {
			throw new AuthenticationException(
				message: 'The token algorithm is not supported',
				details: ['algorithm' => $algorithm, 'reason' => 'Asymmetric verification not yet implemented']
			);
		}

		// Verify HMAC signature.
		if (isset(self::HMAC_MAP[$algorithm]) === false) {
			throw new AuthenticationException(
				message: 'The token algorithm is not supported',
				details: ['algorithm' => $algorithm]
			);
		}

		$hmacValid = $this->verifyHmac(
			headerB64: $headerB64,
			payloadB64: $payloadB64,
			signature: $signature,
			secret: $publicKey,
			algorithm: $algorithm
		);
		if ($hmacValid === false) {
			throw new AuthenticationException(
				message: 'The token could not be validated',
				details: ['reason' => 'The token does not match the public key']
			);
		}

		$this->validatePayload(payload: $payload);

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
			tokenId: (string)($issuer->getUuid() ?? $payload['iss'])
		);

		$this->userSession->setUser($this->userManager->get($issuer->getUserId()));

		// The JWT's own id when it carries one, so a single credential can be
		// revoked by name. The token itself is never passed on: the audit trail
		// is shipped off the instance and retained for years, and a credential
		// in it is a breach waiting for somebody to grep for it.
		$jti = null;
		if (isset($payload['jti']) === true && is_string($payload['jti']) === true && $payload['jti'] !== '') {
			$jti = $payload['jti'];
		}

		$this->claimConsumerToken(consumer: $issuer, mechanism: 'jwt', reference: ($jti ?? $issuer->getUuid()));

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
	 * @throws AuthenticationException If credentials are invalid.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-3/tasks.md#task-5
	 */
	protected function authorizeBasic(string $header, array $users = [], array $groups = []): void {
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

		$this->userSession->setUser($user);

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
	 * @throws AuthenticationException If the token is invalid.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-3/tasks.md#task-5
	 */
	protected function authorizeOAuth(string $header, array $users = [], array $groups = []): void {
		if (str_starts_with(haystack: $header, needle: 'Bearer') === false) {
			throw new AuthenticationException(
				message: 'Invalid method',
				details: ['reason' => 'The authentication method you are using is not allowed on this resource.']
			);
		}

		if ($this->userSession->isLoggedIn() === false) {
			throw new AuthenticationException(
				message: 'Not authorized',
				details: ['reason' => 'The token you used has either expired or was not recognized as a valid token']
			);
		}

	}//end authorizeOAuth()

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
	 * @param string $header The API key from the request header
	 * @param array $keys Map of valid API keys to user IDs
	 *
	 * @return void
	 *
	 * @throws AuthenticationException If the API key is invalid.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-svc-flat-3/tasks.md#task-5
	 */
	protected function authorizeApiKey(string $header, array $keys): void {
		if (array_key_exists(key: $header, array: $keys) === false) {
			throw new AuthenticationException(message: 'Invalid API key', details: []);
		}

		$user = $this->userManager->get($keys[$header]);

		if ($user === null) {
			throw new AuthenticationException(message: 'Invalid API key', details: []);
		}

		$this->userSession->setUser($user);

	}//end authorizeApiKey()
}//end class
