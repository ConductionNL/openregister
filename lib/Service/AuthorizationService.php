<?php

/**
 * Authorization Service for validating incoming API requests.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author  Conduction Development Team <dev@conductio.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use DateTime;
use OC\AppFramework\Middleware\Security\Exceptions\SecurityException;
use OCA\OpenRegister\Db\Consumer;
use OCA\OpenRegister\Db\ConsumerMapper;
use OCA\OpenRegister\Exception\AuthenticationException;
use OCP\AppFramework\Http\Response;
use OCP\IGroupManager;
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
 */
class AuthorizationService
{

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
     * @param IUserManager   $userManager    Nextcloud user manager
     * @param IUserSession   $userSession    Nextcloud user session
     * @param ConsumerMapper $consumerMapper Consumer database mapper
     * @param IGroupManager  $groupManager   Nextcloud group manager
     */
    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IUserSession $userSession,
        private readonly ConsumerMapper $consumerMapper,
        private readonly IGroupManager $groupManager,
    ) {

    }//end __construct()

    /**
     * Find the consumer for a given JWT issuer.
     *
     * @param string $issuer The issuer from the JWT token.
     *
     * @return Consumer The consumer matching the issuer.
     *
     * @throws AuthenticationException Thrown if no issuer was found.
     */
    private function findIssuer(string $issuer): Consumer
    {
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
     * @return string|false The decoded data or false on failure
     */
    private function base64urlDecode(string $data): string|false
    {
        return base64_decode(strtr($data, '-_', '+/'));

    }//end base64urlDecode()

    /**
     * Verify an HMAC JWT signature using PHP built-in functions.
     *
     * @param string $headerB64  The base64url-encoded header
     * @param string $payloadB64 The base64url-encoded payload
     * @param string $signature  The raw signature bytes
     * @param string $secret     The HMAC shared secret
     * @param string $algorithm  The JWT algorithm (HS256, HS384, HS512)
     *
     * @return bool True if the signature is valid
     */
    private function verifyHmac(
        string $headerB64,
        string $payloadB64,
        string $signature,
        string $secret,
        string $algorithm
    ): bool {
        $hashAlg = self::HMAC_MAP[$algorithm] ?? null;
        if ($hashAlg === null) {
            return false;
        }

        $expected = hash_hmac($hashAlg, $headerB64.'.'.$payloadB64, $secret, true);

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
     */
    public function validatePayload(array $payload): void
    {
        $now = new DateTime();

        if (isset($payload['iat']) === true) {
            $iat = new DateTime('@'.$payload['iat']);
        } else {
            throw new AuthenticationException(
                message: 'The token has no time of creation',
                details: ['iat' => null]
            );
        }

        if (isset($payload['exp']) === true) {
            $exp = new DateTime('@'.$payload['exp']);
        } else {
            $exp = clone $iat;
            $exp->modify('+1 Hour');
        }

        if ($exp->diff($now)->format('%R') === '+') {
            throw new AuthenticationException(
                message: 'The token has expired',
                details: [
                    'iat'          => $iat->getTimestamp(),
                    'exp'          => $exp->getTimestamp(),
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
     */
    public function authorizeJwt(string $authorization): void
    {
        $token = substr(string: $authorization, offset: strlen(string: 'Bearer '));

        if ($token === '' || $token === false) {
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

        $headerJson = $this->base64urlDecode($headerB64);
        if ($headerJson === false) {
            throw new AuthenticationException(
                message: 'The token could not be validated',
                details: ['reason' => 'Invalid header encoding']
            );
        }

        $header = json_decode($headerJson, true);
        if (is_array($header) === false || isset($header['alg']) === false) {
            throw new AuthenticationException(
                message: 'The token could not be validated',
                details: ['reason' => 'Invalid header']
            );
        }

        $payloadJson = $this->base64urlDecode($payloadB64);
        if ($payloadJson === false) {
            throw new AuthenticationException(
                message: 'The token could not be validated',
                details: ['reason' => 'Invalid payload encoding']
            );
        }

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

        $issuer   = $this->findIssuer(issuer: $payload['iss']);
        $authConf = $issuer->getAuthorizationConfiguration();

        $publicKey = $authConf['publicKey'] ?? '';
        $algorithm = $authConf['algorithm'] ?? $header['alg'];

        $signature = $this->base64urlDecode($signatureB64);
        if ($signature === false) {
            throw new AuthenticationException(
                message: 'The token could not be validated',
                details: ['reason' => 'Invalid signature encoding']
            );
        }

        // Verify HMAC signature.
        if (isset(self::HMAC_MAP[$algorithm]) === true) {
            if ($this->verifyHmac($headerB64, $payloadB64, $signature, $publicKey, $algorithm) === false) {
                throw new AuthenticationException(
                    message: 'The token could not be validated',
                    details: ['reason' => 'The token does not match the public key']
                );
            }
        } else {
            throw new AuthenticationException(
                message: 'The token algorithm is not supported',
                details: ['algorithm' => $algorithm]
            );
        }

        $this->validatePayload(payload: $payload);

        $this->userSession->setUser($this->userManager->get($issuer->getUserId()));

    }//end authorizeJwt()

    /**
     * Authorize user based on HTTP Basic Auth.
     *
     * @param string $header The authorization header value
     * @param array  $users  The users allowed to authenticate
     * @param array  $groups The groups allowed to authenticate
     *
     * @return void
     *
     * @throws AuthenticationException If credentials are invalid.
     */
    public function authorizeBasic(string $header, array $users=[], array $groups=[]): void
    {
        $header = substr(string: $header, offset: strlen(string: 'Basic '));
        $decode = base64_decode(string: $header);
        [$username, $password] = explode(separator: ':', string: $decode);

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
     * @param array  $users  The users allowed to authenticate
     * @param array  $groups The groups allowed to authenticate
     *
     * @return void
     *
     * @throws AuthenticationException If the token is invalid.
     */
    public function authorizeOAuth(string $header, array $users=[], array $groups=[]): void
    {
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
     * @param IRequest $request  The incoming request
     * @param Response $response The outgoing response
     *
     * @return Response The updated response.
     *
     * @throws SecurityException If CSRF-unsafe headers are detected.
     *
     * @psalm-suppress UndefinedClass SecurityException is a private Nextcloud internal class
     */
    public function corsAfterController(IRequest $request, Response $response): Response
    {
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
     * @param array  $keys   Map of valid API keys to user IDs
     *
     * @return void
     *
     * @throws AuthenticationException If the API key is invalid.
     */
    public function authorizeApiKey(string $header, array $keys): void
    {
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
