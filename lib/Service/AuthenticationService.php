<?php

/**
 * Authentication Service for generating outbound tokens.
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

use GuzzleHttp\Client;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Service for handling authentication against external services.
 *
 * Generates OAuth2 access tokens and signed JWT tokens for outbound API calls.
 *
 * @package OCA\OpenRegister\Service
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class AuthenticationService
{

    /**
     * Required parameters for OAuth2 client credentials flow.
     */
    public const REQUIRED_PARAMETERS_CLIENT_CREDENTIALS = [
        'grant_type',
        'scope',
        'authentication',
        'client_id',
        'client_secret',
    ];

    /**
     * Required parameters for OAuth2 password flow.
     */
    public const REQUIRED_PARAMETERS_PASSWORD = [
        'grant_type',
        'scope',
        'authentication',
        'username',
        'password',
    ];

    /**
     * Required parameters for JWT generation.
     */
    public const REQUIRED_PARAMETERS_JWT = [
        'payload',
        'secret',
        'algorithm',
    ];

    /**
     * Twig environment for payload rendering.
     *
     * @var \Twig\Environment|null
     */
    private ?\Twig\Environment $twig = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
    }//end __construct()

    /**
     * Get or lazily create the Twig environment.
     *
     * @return \Twig\Environment|null
     */
    private function getTwig(): ?\Twig\Environment
    {
        if ($this->twig !== null) {
            return $this->twig;
        }

        if (class_exists(\Twig\Environment::class) === false) {
            return null;
        }

        $this->twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader());
        return $this->twig;
    }//end getTwig()

    /**
     * Create call options for OAuth with Client Credentials.
     *
     * @param array $configuration Configuration array for authentication.
     *
     * @return array The call options for the OAuth request.
     *
     * @throws BadRequestException If required parameters are missing.
     */
    private function createClientCredentialConfig(array $configuration): array
    {
        $missingParams = array_keys(array: $configuration);
        $diff          = array_diff(self::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS, $missingParams);
        if ($diff !== []) {
            throw new BadRequestException(
                'Some required parameters are not set: ['.implode(separator: ',', array: $diff).']'
            );
        }

        $callConfig = [
            'form_params' => [
                'grant_type' => $configuration['grant_type'],
                'scope'      => $configuration['scope'],
            ],
        ];

        if ($configuration['authentication'] === 'body') {
            $callConfig['form_params']['client_id']     = $configuration['client_id'];
            $callConfig['form_params']['client_secret'] = $configuration['client_secret'];
        } else if ($configuration['authentication'] === 'basic_auth') {
            $callConfig['auth'] = [
                'username' => $configuration['client_id'],
                'password' => $configuration['client_secret'],
            ];
        }

        if (isset($configuration['client_assertion_type']) === true
            && $configuration['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
        ) {
            $callConfig['form_params']['client_assertion_type'] = $configuration['client_assertion_type'];
            $callConfig['form_params']['client_assertion']      = $this->fetchJWTToken(
                configuration: [
                    'algorithm' => 'PS256',
                    'secret'    => $configuration['private_key'],
                    'x5t'       => $configuration['x5t'],
                    'payload'   => $configuration['payload'],
                ]
            );
        }

        return $callConfig;

    }//end createClientCredentialConfig()

    /**
     * Create call options for OAuth with Password Credentials.
     *
     * @param array $configuration Configuration array for authentication.
     *
     * @return array The call options for the OAuth request.
     *
     * @throws BadRequestException If required parameters are missing.
     */
    private function createPasswordConfig(array $configuration): array
    {
        $configKeys = array_keys(array: $configuration);
        $diff       = array_diff(self::REQUIRED_PARAMETERS_PASSWORD, $configKeys);
        if ($diff !== []) {
            throw new BadRequestException(
                'Some required parameters are not set: ['.implode(separator: ',', array: $diff).']'
            );
        }

        $callConfig = [
            'form_params' => [
                'grant_type' => $configuration['grant_type'],
                'scope'      => $configuration['scope'],
            ],
        ];

        if ($configuration['authentication'] === 'body') {
            $callConfig['form_params']['username'] = $configuration['username'];
            $callConfig['form_params']['password'] = $configuration['password'];
        } else if ($configuration['authentication'] === 'basic_auth') {
            $callConfig['auth'] = [
                'username' => $configuration['username'],
                'password' => $configuration['password'],
            ];
        }

        return $callConfig;

    }//end createPasswordConfig()

    /**
     * Request an OAuth Access Token with the given configuration.
     *
     * @param array $configuration The OAuth configuration.
     *
     * @return string The resulting access token.
     *
     * @throws BadRequestException If configuration is incomplete.
     * @throws \GuzzleHttp\Exception\GuzzleException If the token endpoint fails.
     */
    public function fetchOAuthTokens(array $configuration): string
    {
        if (isset($configuration['grant_type']) === false) {
            throw new BadRequestException('Grant type not set, cannot request token');
        }

        if (isset($configuration['tokenUrl']) === false) {
            throw new BadRequestException('Token URL not set, cannot request token');
        }

        switch ($configuration['grant_type']) {
            case 'client_credentials':
                $callConfig = $this->createClientCredentialConfig(configuration: $configuration);
                break;
            case 'password':
                $callConfig = $this->createPasswordConfig(configuration: $configuration);
                break;
            default:
                throw new BadRequestException('Grant type not supported');
        }

        $client   = new Client();
        $response = $client->post($configuration['tokenUrl'], $callConfig);
        $result   = json_decode(json: $response->getBody()->getContents(), associative: true);

        if (isset($configuration['tokenLocation']) === true) {
            return $result[$configuration['tokenLocation']];
        }

        return $result['access_token'];

    }//end fetchOAuthTokens()

    /**
     * Fetch an access token from DeCOS (non-standard OAuth implementation).
     *
     * @param array $configuration The source configuration.
     *
     * @return string The access token.
     *
     * @throws \GuzzleHttp\Exception\GuzzleException If the request fails.
     */
    public function fetchDecosToken(array $configuration): string
    {
        $url           = $configuration['tokenUrl'];
        $tokenLocation = $configuration['tokenLocation'];
        unset($configuration['tokenUrl']);

        $callConfig         = [];
        $callConfig['json'] = $configuration;

        $client   = new Client();
        $response = $client->post($url, $callConfig);
        $result   = json_decode(json: $response->getBody()->getContents(), associative: true);

        if (isset($tokenLocation) === true) {
            return $result[$tokenLocation];
        }

        return $result['token'];

    }//end fetchDecosToken()

    /**
     * Get RSA key for RS and PS (asymmetric) encryption.
     *
     * @param array $configuration The auth configuration with secret key.
     *
     * @return JWK The JWK key.
     */
    private function getRSJWK(array $configuration): JWK
    {
        $stamp    = microtime().getmypid();
        $filename = "/var/tmp/privatekey-$stamp";
        file_put_contents(filename: $filename, data: base64_decode(string: $configuration['secret']));

        try {
            $jwk = JWKFactory::createFromKeyFile($filename, null, ['use' => 'sig']);
        } finally {
            unlink(filename: $filename);
        }

        return $jwk;

    }//end getRSJWK()

    /**
     * Get OCT key for HS (symmetric) encryption.
     *
     * @param array $configuration The source configuration with secret.
     *
     * @return JWK The JWK key.
     */
    private function getHSJWK(array $configuration): JWK
    {
        return new JWK(
            [
                'kty' => 'oct',
                'k'   => rtrim(string: base64_encode(string: addslashes(string: $configuration['secret'])), characters: '='),
            ]
        );

    }//end getHSJWK()

    /**
     * Generate the JWT Payload by rendering the Twig template.
     *
     * @param array $configuration The source auth configuration.
     *
     * @return array The resulting JWT payload.
     *
     * @throws \Twig\Error\LoaderError  If the template cannot be loaded.
     * @throws \Twig\Error\SyntaxError  If the template has syntax errors.
     * @throws \Twig\Error\RuntimeError If the template rendering fails.
     */
    private function getJWTPayload(array $configuration): array
    {
        $twig = $this->getTwig();
        if ($twig === null) {
            throw new \RuntimeException('Twig is not available (vendor/autoload.php missing or composer install not run).');
        }

        $renderedPayload = $twig->createTemplate($configuration['payload'])->render($configuration);

        return json_decode(json: $renderedPayload, associative: true);

    }//end getJWTPayload()

    /**
     * Get the JWK key based on algorithm and secret.
     *
     * @param array $configuration The auth configuration with algorithm and secret.
     *
     * @return JWK The resulting JWK key.
     *
     * @throws BadRequestException If the algorithm is not supported.
     */
    private function getJWK(array $configuration): JWK
    {
        if (in_array(needle: $configuration['algorithm'], haystack: ['HS256', 'HS384', 'HS512']) === true) {
            return $this->getHSJWK(configuration: $configuration);
        }

        if (in_array(needle: $configuration['algorithm'], haystack: ['RS256', 'RS384', 'RS512', 'PS256']) === true) {
            return $this->getRSJWK(configuration: $configuration);
        }

        throw new BadRequestException('Algorithm not supported by key generator');

    }//end getJWK()

    /**
     * Generate a signed JWT token.
     *
     * @param array       $payload   The JWT payload
     * @param JWK         $jwk       The signing key
     * @param string      $algorithm The signing algorithm
     * @param string|null $x5t       Optional certificate thumbprint
     *
     * @return string The compact-serialized JWT string.
     */
    private function generateJWT(array $payload, JWK $jwk, string $algorithm, ?string $x5t=null): string
    {
        $algorithmManager = new AlgorithmManager(
            [
                new HS256(),
                new HS384(),
                new HS512(),
                new RS256(),
                new RS384(),
                new RS512(),
                new PS256(),
            ]
        );

        $jwsBuilder    = new JWSBuilder($algorithmManager);
        $jwsSerializer = new CompactSerializer();

        $header = [
            'alg' => $algorithm,
            'typ' => 'JWT',
        ];
        if ($x5t !== null) {
            $header['x5t'] = $x5t;
        }

        $jws = $jwsBuilder
            ->create()
            ->withPayload(json_encode(value: $payload))
            ->addSignature($jwk, $header)
            ->build();

        return $jwsSerializer->serialize($jws, 0);

    }//end generateJWT()

    /**
     * Generate a JWT token for authentication.
     *
     * @param array $configuration The auth configuration (must contain payload, algorithm, secret).
     *
     * @return string The generated JWT token.
     *
     * @throws BadRequestException If required parameters are missing.
     */
    public function fetchJWTToken(array $configuration): string
    {
        $configKeys = array_keys(array: $configuration);
        $diff       = array_diff(self::REQUIRED_PARAMETERS_JWT, $configKeys);
        if ($diff !== []) {
            throw new BadRequestException(
                'Some required parameters are not set: ['.implode(separator: ',', array: $diff).']'
            );
        }

        $payload = $this->getJWTPayload(configuration: $configuration);
        $jwk     = $this->getJWK(configuration: $configuration);

        if (isset($configuration['x5t']) === true) {
            return $this->generateJWT(
                payload: $payload,
                jwk: $jwk,
                algorithm: $configuration['algorithm'],
                x5t: $configuration['x5t']
            );
        }

        return $this->generateJWT(payload: $payload, jwk: $jwk, algorithm: $configuration['algorithm']);

    }//end fetchJWTToken()
}//end class
