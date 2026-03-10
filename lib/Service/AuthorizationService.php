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
use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Checker\InvalidHeaderException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWS;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
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
 * Supports JWT, JWT-ZGW, Basic Auth, OAuth2 Bearer, and API Key validation.
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AuthorizationService
{

    /**
     * Supported HMAC algorithms.
     */
    const HMAC_ALGORITHMS = [
        'HS256',
        'HS384',
        'HS512',
    ];

    /**
     * Supported PKCS1 (RSA) algorithms.
     */
    const PKCS1_ALGORITHMS = [
        'RS256',
        'RS384',
        'RS512',
    ];

    /**
     * Supported PSS algorithms.
     */
    const PSS_ALGORITHMS = [
        'PS256',
        'PS384',
        'PS512',
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
     * Check if the headers of a JWT token are valid.
     *
     * @param JWS $token The unserialized token.
     *
     * @return void
     */
    private function checkHeaders(JWS $token): void
    {
        $headerChecker = new HeaderCheckerManager(
            [
                new AlgorithmChecker(
                    array_merge(
                        self::HMAC_ALGORITHMS,
                        self::PKCS1_ALGORITHMS,
                        self::PSS_ALGORITHMS
                    )
                ),
            ],
            [new JWSTokenSupport()]
        );

        $headerChecker->check($token, 0);

    }//end checkHeaders()

    /**
     * Get the JSON Web Key Set for a public key and algorithm.
     *
     * @param string $publicKey The public key or shared secret
     * @param string $algorithm The algorithm deciding how the key should be defined
     *
     * @return JWKSet The resulting JWK set.
     *
     * @throws AuthenticationException If the algorithm is not supported.
     */
    private function getJWK(string $publicKey, string $algorithm): JWKSet
    {
        if (in_array(needle: $algorithm, haystack: self::HMAC_ALGORITHMS) === true) {
            return new JWKSet(
                [
                    JWKFactory::createFromSecret(
                        $publicKey,
                        ['alg' => $algorithm, 'use' => 'sig']
                    ),
                ]
            );
        }

        if (in_array(needle: $algorithm, haystack: self::PKCS1_ALGORITHMS) === true
            || in_array(needle: $algorithm, haystack: self::PSS_ALGORITHMS) === true
        ) {
            $stamp    = microtime().getmypid();
            $filename = "/var/tmp/publickey-$stamp";
            file_put_contents(filename: $filename, data: base64_decode(string: $publicKey));
            $jwk = new JWKSet([JWKFactory::createFromKeyFile($filename)]);
            unlink(filename: $filename);
            return $jwk;
        }

        throw new AuthenticationException(
            message: 'The token algorithm is not supported',
            details: ['algorithm' => $algorithm]
        );

    }//end getJWK()

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

        $algorithmManager = new AlgorithmManager(
            [
                new HS256(),
                new HS384(),
                new HS512(),
                new RS256(),
                new RS384(),
                new RS512(),
                new PS256(),
                new PS384(),
                new PS512(),
            ]
        );

        $verifier          = new JWSVerifier($algorithmManager);
        $serializerManager = new JWSSerializerManager([new CompactSerializer()]);

        $jws = $serializerManager->unserialize($token);

        try {
            $this->checkHeaders(token: $jws);
        } catch (InvalidHeaderException $exception) {
            throw new AuthenticationException(
                message: 'The token could not be validated',
                details: ['reason' => $exception->getMessage()]
            );
        }

        $payload = json_decode(json: $jws->getPayload(), associative: true);
        if (isset($payload['iss']) === false || empty($payload['iss']) === true) {
            throw new AuthenticationException(
                message: 'The token could not be validated',
                details: ['reason' => 'No issuer mentioned']
            );
        }

        $issuer = $this->findIssuer(issuer: $payload['iss']);

        $publicKey = $issuer->getAuthorizationConfiguration()['publicKey'];
        $algorithm = $issuer->getAuthorizationConfiguration()['algorithm'];

        $jwkSet = $this->getJWK(publicKey: $publicKey, algorithm: $algorithm);

        if ($verifier->verifyWithKeySet($jws, $jwkSet, 0) === false) {
            throw new AuthenticationException(
                message: 'The token could not be validated',
                details: ['reason' => 'The token does not match the public key']
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
     */
    public function corsAfterController(IRequest $request, Response $response): Response
    {
        if (isset($request->server['HTTP_ORIGIN']) === true) {
            foreach ($response->getHeaders() as $header => $value) {
                if (strtolower(string: $header) === 'access-control-allow-credentials'
                    && strtolower(string: trim(string: $value)) === 'true'
                ) {
                    $msg = 'Access-Control-Allow-Credentials must not be set to true in order to prevent CSRF';
                    throw new SecurityException($msg);
                }
            }

            $origin = $request->server['HTTP_ORIGIN'];
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
