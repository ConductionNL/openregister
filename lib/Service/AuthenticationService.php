<?php

/**
 * Authentication Service for generating outbound tokens.
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
use Twig\Environment;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Sandbox\SecurityPolicy;

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
class AuthenticationService implements AuthenticationTokenFetcherInterface {

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
	 * @var Environment
	 */
	private Environment $twig;

	/**
	 * Constructor.
	 *
	 * @param ArrayLoader $loader The Twig array loader
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(ArrayLoader $loader) {
		$this->twig = new Environment($loader);

		// SSTI hardening (SEC-SVC-4): the JWT payload template is user-authored
		// source configuration, so it MUST be compiled inside a Twig sandbox.
		// Only the tags/filters/functions needed to build a JWT claim set are
		// allowlisted; method/property access on objects is denied entirely.
		$policy = new SecurityPolicy(
			allowedTags: [
				'if',
				'for',
				'set',
				'apply',
			],
			allowedFilters: [
				'date',
				'date_modify',
				'upper',
				'lower',
				'trim',
				'length',
				'default',
				'number_format',
				'round',
				'abs',
				'split',
				'join',
				'slice',
				'first',
				'last',
				'replace',
				'format',
				'merge',
				'keys',
				'escape',
				'raw',
				'json_encode',
			],
			allowedMethods: [],
			allowedProperties: [],
			allowedFunctions: [
				'max',
				'min',
				'range',
				'date',
			]
		);
		$this->twig->addExtension(new SandboxExtension($policy, sandboxed: true));

	}//end __construct()

	/**
	 * Create call options for OAuth with Client Credentials.
	 *
	 * @param array $configuration Configuration array for authentication.
	 *
	 * @return array The call options for the OAuth request.
	 *
	 * @throws BadRequestException If required parameters are missing.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function createClientCredentialConfig(array $configuration): array {
		$this->assertParametersSet(required: self::REQUIRED_PARAMETERS_CLIENT_CREDENTIALS, configuration: $configuration);

		$callConfig = [
			'form_params' => [
				'grant_type' => $configuration['grant_type'],
				'scope' => $configuration['scope'],
			],
		];

		$callConfig = $this->withClientCredentials(callConfig: $callConfig, configuration: $configuration);

		return $this->withClientAssertion(callConfig: $callConfig, configuration: $configuration);
	}//end createClientCredentialConfig()

	/**
	 * Refuse a configuration that lacks any of the required parameters.
	 *
	 * @param string[] $required      The parameter names that must be present.
	 * @param array    $configuration The configuration.
	 *
	 * @return void
	 *
	 * @throws BadRequestException Listing the missing parameters.
	 */
	private function assertParametersSet(array $required, array $configuration): void {
		$diff = array_diff($required, array_keys(array: $configuration));
		if ($diff !== []) {
			throw new BadRequestException(
				'Some required parameters are not set: [' . implode(separator: ',', array: $diff) . ']'
			);
		}
	}//end assertParametersSet()

	/**
	 * Put the client id and secret in the body or in basic auth, as configured.
	 *
	 * @param array $callConfig    The call options so far.
	 * @param array $configuration The configuration.
	 *
	 * @return array The call options with the client credentials.
	 */
	private function withClientCredentials(array $callConfig, array $configuration): array {
		if ($configuration['authentication'] === 'body') {
			$callConfig['form_params']['client_id'] = $configuration['client_id'];
			$callConfig['form_params']['client_secret'] = $configuration['client_secret'];
		} elseif ($configuration['authentication'] === 'basic_auth') {
			$callConfig['auth'] = [
				'username' => $configuration['client_id'],
				'password' => $configuration['client_secret'],
			];
		}

		return $callConfig;
	}//end withClientCredentials()

	/**
	 * Add a signed JWT client assertion when the configuration asks for one.
	 *
	 * @param array $callConfig    The call options so far.
	 * @param array $configuration The configuration.
	 *
	 * @return array The call options, with the assertion when configured.
	 */
	private function withClientAssertion(array $callConfig, array $configuration): array {
		if (isset($configuration['client_assertion_type']) === true
			&& $configuration['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
		) {
			$callConfig['form_params']['client_assertion_type'] = $configuration['client_assertion_type'];
			$callConfig['form_params']['client_assertion'] = $this->fetchJWTToken(
				configuration: [
					'algorithm' => 'PS256',
					'secret' => $configuration['private_key'],
					'x5t' => $configuration['x5t'],
					'payload' => $configuration['payload'],
				]
			);
		}

		return $callConfig;
	}//end withClientAssertion()

	/**
	 * Create call options for OAuth with Password Credentials.
	 *
	 * @param array $configuration Configuration array for authentication.
	 *
	 * @return array The call options for the OAuth request.
	 *
	 * @throws BadRequestException If required parameters are missing.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function createPasswordConfig(array $configuration): array {
		$this->assertParametersSet(required: self::REQUIRED_PARAMETERS_PASSWORD, configuration: $configuration);

		$callConfig = [
			'form_params' => [
				'grant_type' => $configuration['grant_type'],
				'scope' => $configuration['scope'],
			],
		];

		return self::withPasswordCredentials(callConfig: $callConfig, configuration: $configuration);
	}//end createPasswordConfig()

	/**
	 * Add the username and password where the configuration says they go: the body, or basic auth.
	 *
	 * @param array $callConfig    The token request so far.
	 * @param array $configuration The authentication configuration.
	 *
	 * @return array
	 */
	private static function withPasswordCredentials(array $callConfig, array $configuration): array {
		if ($configuration['authentication'] === 'body') {
			$callConfig['form_params']['username'] = $configuration['username'];
			$callConfig['form_params']['password'] = $configuration['password'];
		} elseif ($configuration['authentication'] === 'basic_auth') {
			$callConfig['auth'] = [
				'username' => $configuration['username'],
				'password' => $configuration['password'],
			];
		}

		return $callConfig;
	}//end withPasswordCredentials()

	/**
	 * Request an OAuth Access Token with the given configuration.
	 *
	 * @param array $configuration The OAuth configuration.
	 *
	 * @return string The resulting access token.
	 *
	 * @throws BadRequestException If configuration is incomplete.
	 * @throws \GuzzleHttp\Exception\GuzzleException If the token endpoint fails.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function fetchOAuthTokens(array $configuration): string {
		if (isset($configuration['grant_type']) === false) {
			throw new BadRequestException('Grant type not set, cannot request token');
		}

		if (isset($configuration['tokenUrl']) === false) {
			throw new BadRequestException('Token URL not set, cannot request token');
		}

		$callConfig = $this->grantCallConfig(configuration: $configuration);

		$client = new Client();
		$response = $client->post($configuration['tokenUrl'], $callConfig);
		$result = json_decode(json: $response->getBody()->getContents(), associative: true);

		if (isset($configuration['tokenLocation']) === true) {
			return $result[$configuration['tokenLocation']];
		}

		return $result['access_token'];
	}//end fetchOAuthTokens()

	/**
	 * The token call options for the configured grant type.
	 *
	 * @param array $configuration The OAuth configuration.
	 *
	 * @return array The call options.
	 *
	 * @throws BadRequestException If the grant type is not supported or parameters are missing.
	 */
	private function grantCallConfig(array $configuration): array {
		switch ($configuration['grant_type']) {
			case 'client_credentials':
				return $this->createClientCredentialConfig(configuration: $configuration);
			case 'password':
				return $this->createPasswordConfig(configuration: $configuration);
			default:
				throw new BadRequestException('Grant type not supported');
		}
	}//end grantCallConfig()

	/**
	 * Fetch an access token from DeCOS (non-standard OAuth implementation).
	 *
	 * @param array $configuration The source configuration.
	 *
	 * @return string The access token.
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException If the request fails.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function fetchDecosToken(array $configuration): string {
		$url = $configuration['tokenUrl'];
		$tokenLocation = $configuration['tokenLocation'];
		unset($configuration['tokenUrl']);

		$callConfig = [];
		$callConfig['json'] = $configuration;

		$client = new Client();
		$response = $client->post($url, $callConfig);
		$result = json_decode(json: $response->getBody()->getContents(), associative: true);

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
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function getRSJWK(array $configuration): JWK {
		// The key is read from memory: written to a file, it would sit on disk for the length of the call.
		return JWKFactory::createFromKey(
			key: (string)base64_decode(string: $configuration['secret']),
			password: null,
			additional_values: ['use' => 'sig']
		);
	}//end getRSJWK()

	/**
	 * Get OCT key for HS (symmetric) encryption.
	 *
	 * @param array $configuration The source configuration with secret.
	 *
	 * @return JWK The JWK key.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function getHSJWK(array $configuration): JWK {
		return new JWK(
			[
				'kty' => 'oct',
				'k' => rtrim(string: base64_encode(string: addslashes(string: $configuration['secret'])), characters: '='),
			]
		);

	}//end getHSJWK()

	/**
	 * The configuration keys that carry secret material and never reach a payload template.
	 *
	 * @var list<string>
	 */
	private const PAYLOAD_SECRET_KEYS = [
		'secret',
		'clientSecret',
		'client_secret',
		'password',
		'privateKey',
		'private_key',
		'apiKey',
		'api_key',
		'token',
		'accessToken',
		'access_token',
		'refreshToken',
		'refresh_token',
		'authorization',
	];

	/**
	 * Generate the JWT Payload by rendering the Twig template.
	 *
	 * @param array $configuration The source auth configuration.
	 *
	 * @return array The resulting JWT payload.
	 *
	 * @throws \Twig\Error\LoaderError If the template cannot be loaded.
	 * @throws \Twig\Error\SyntaxError If the template has syntax errors.
	 * @throws \Twig\Error\RuntimeError If the template rendering fails.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function getJWTPayload(array $configuration): array {
		// SEC-SVC-4: never expose secret material to the payload template
		// context. Strip credential-bearing keys so a crafted payload template
		// cannot echo adjacent secrets into the rendered claim set.
		$context = $configuration;
		foreach (self::PAYLOAD_SECRET_KEYS as $secretKey) {
			unset($context[$secretKey]);
		}

		$renderedPayload = $this->twig->createTemplate($configuration['payload'])->render($context);

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
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function getJWK(array $configuration): JWK {
		if (in_array(needle: $configuration['algorithm'], haystack: ['HS256', 'HS384', 'HS512']) === true) {
			return $this->getHSJWK(configuration: $configuration);
		}

		if (in_array(needle: $configuration['algorithm'], haystack: ['RS256', 'RS384', 'RS512', 'PS256']) === true) {
			return $this->getRSJWK(configuration: $configuration);
		}

		throw new BadRequestException('Algorithm not supported by key generator');
	}//end getJWK()

	/**
	 * The JWS builder over every algorithm a source may sign with.
	 *
	 * @return JWSBuilder The builder.
	 */
	private function jwsBuilder(): JWSBuilder {
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

		return new JWSBuilder($algorithmManager);
	}//end jwsBuilder()

	/**
	 * The protected header of a JWT.
	 *
	 * @param string      $algorithm The signing algorithm.
	 * @param string|null $x5t       Optional certificate thumbprint.
	 *
	 * @return array The header.
	 */
	private function jwtHeader(string $algorithm, ?string $x5t): array {
		$header = [
			'alg' => $algorithm,
			'typ' => 'JWT',
		];
		if ($x5t !== null) {
			$header['x5t'] = $x5t;
		}

		return $header;
	}//end jwtHeader()

	/**
	 * Generate a signed JWT token.
	 *
	 * @param array       $payload   The JWT payload
	 * @param JWK         $jwk       The signing key
	 * @param string      $algorithm The signing algorithm
	 * @param string|null $x5t       Optional certificate thumbprint
	 *
	 * @return string The compact-serialized JWT string.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function generateJWT(array $payload, JWK $jwk, string $algorithm, ?string $x5t = null): string {
		$jwsBuilder = $this->jwsBuilder();
		$jws = $jwsBuilder
			->create()
			->withPayload(json_encode(value: $payload))
			->addSignature($jwk, $this->jwtHeader(algorithm: $algorithm, x5t: $x5t))
			->build();

		return (new CompactSerializer())->serialize($jws, 0);
	}//end generateJWT()

	/**
	 * Generate a JWT token for authentication.
	 *
	 * @param array $configuration The auth configuration (must contain payload, algorithm, secret).
	 *
	 * @return string The generated JWT token.
	 *
	 * @throws BadRequestException If required parameters are missing.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function fetchJWTToken(array $configuration): string {
		$this->assertParametersSet(required: self::REQUIRED_PARAMETERS_JWT, configuration: $configuration);

		$payload = $this->getJWTPayload(configuration: $configuration);
		$jwk = $this->getJWK(configuration: $configuration);

		return $this->generateJWT(
			payload: $payload,
			jwk: $jwk,
			algorithm: $configuration['algorithm'],
			x5t: ($configuration['x5t'] ?? null)
		);
	}//end fetchJWTToken()
}//end class
