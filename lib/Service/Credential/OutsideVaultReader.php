<?php

/**
 * OutsideVaultReader — reads a referenced secret from HashiCorp Vault or OpenBao (credential-outside-vault-reference D-3).
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

use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads one key of a KV v2 secret from an outside vault, logging in with a
 * token or AppRole, with a two second timeout.
 *
 * The value and the login token are kept in this object only, which lives for
 * one request, so a proxy call with retries reads once. Neither is written,
 * logged or returned elsewhere. A failure raises CredentialUpstreamException
 * with a fixed sentence and at most the vault's status code, never its body.
 *
 * Authorization is the caller's: the broker runs its guards before it asks.
 *
 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
 */
final class OutsideVaultReader {

	/**
	 * Seconds a login or read may take.
	 */
	private const TIMEOUT = 2;

	/**
	 * Values read in this request, by connection, path and key.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * Login tokens of this request, by connection.
	 *
	 * @var array<string, string>
	 */
	private array $tokens = [];

	/**
	 * Constructor.
	 *
	 * @param IClientService  $clientService Makes the HTTP calls.
	 * @param LoggerInterface $logger        Logs failures by status only.
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The value a reference names, read from the vault once per request.
	 *
	 * @param OutsideVaultConnection $connection The vault connection, with its login secret.
	 * @param VaultReference         $reference  The path and key.
	 *
	 * @return string The value.
	 *
	 * @throws CredentialUpstreamException When the vault cannot be reached, refuses, or holds no such key.
	 *
	 * @spec openspec/changes/credential-outside-vault-reference/specs/credential-broker/spec.md#requirement-a-credential-can-reference-a-secret-held-in-an-outside-vault
	 */
	public function read(OutsideVaultConnection $connection, VaultReference $reference): string {
		$cacheKey = $reference->connection."\0".$reference->path."\0".$reference->key;
		if (isset($this->values[$cacheKey]) === true) {
			return $this->values[$cacheKey];
		}

		$token = $this->token(connection: $connection, reference: $reference);
		$data  = $this->call(
			connection: $reference->connection,
			method: 'get',
			url: $connection->address().'/v1/'.$connection->mount.'/data/'.$reference->path,
			options: ['headers' => ['X-Vault-Token' => $token, 'Accept' => 'application/json']]
		);

		$value = ($data['data']['data'][$reference->key] ?? null);
		if (is_string($value) === false && is_int($value) === false && is_float($value) === false) {
			$this->logger->warning(
				'[OutsideVaultReader] The outside vault holds no such key',
				['connection' => $reference->connection, 'path' => $reference->path]
			);
			throw new CredentialUpstreamException('The outside vault holds no value for this reference.');
		}

		$this->values[$cacheKey] = (string) $value;
		return $this->values[$cacheKey];
	}//end read()

	/**
	 * The token to read with: the connection's token, or one from an AppRole login made once per request.
	 *
	 * @param OutsideVaultConnection $connection The connection.
	 * @param VaultReference         $reference  The reference, for the connection uuid.
	 *
	 * @return string The token.
	 *
	 * @throws CredentialUpstreamException When the login fails.
	 */
	private function token(OutsideVaultConnection $connection, VaultReference $reference): string {
		if ($connection->authMethod === OutsideVaultConnection::AUTH_TOKEN) {
			return $connection->secret;
		}

		if (isset($this->tokens[$reference->connection]) === true) {
			return $this->tokens[$reference->connection];
		}

		$data  = $this->call(
			connection: $reference->connection,
			method: 'post',
			url: $connection->address().'/v1/auth/approle/login',
			options: ['json' => ['role_id' => (string) $connection->roleId, 'secret_id' => $connection->secret]]
		);
		$token = ($data['auth']['client_token'] ?? null);
		if (is_string($token) === false || $token === '') {
			$this->logger->warning('[OutsideVaultReader] The outside vault gave no token on login', ['connection' => $reference->connection]);
			throw new CredentialUpstreamException('The outside vault refused the login.');
		}

		$this->tokens[$reference->connection] = $token;
		return $token;
	}//end token()

	/**
	 * One call to the vault, decoded; a transport failure or a non-2xx status raises.
	 *
	 * @param string               $connection The connection uuid, for the log.
	 * @param string               $method     get or post.
	 * @param string               $url        The URL.
	 * @param array<string, mixed> $options    The request options.
	 *
	 * @return array<string, mixed> The decoded body.
	 *
	 * @throws CredentialUpstreamException When the call fails.
	 */
	private function call(string $connection, string $method, string $url, array $options): array {
		$options += ['timeout' => self::TIMEOUT, 'http_errors' => false];
		try {
			$client = $this->clientService->newClient();
			$response = match ($method) {
				'post' => $client->post($url, $options),
				default => $client->get($url, $options),
			};
		} catch (Throwable $e) {
			$this->logger->warning('[OutsideVaultReader] The outside vault could not be reached', ['connection' => $connection, 'exception' => $e::class]);
			throw new CredentialUpstreamException('The outside vault could not be reached.');
		}

		return $this->decode(response: $response, connection: $connection);
	}//end call()

	/**
	 * A vault answer decoded; a non-2xx status raises with the status only.
	 *
	 * @param IResponse $response   The answer.
	 * @param string    $connection The connection uuid, for the log.
	 *
	 * @return array<string, mixed> The decoded body.
	 *
	 * @throws CredentialUpstreamException When the status is not 2xx or the body is no JSON object.
	 */
	private function decode(IResponse $response, string $connection): array {
		$status = $response->getStatusCode();
		if ($status < 200 || $status > 299) {
			$this->logger->warning('[OutsideVaultReader] The outside vault refused', ['connection' => $connection, 'status' => $status]);
			throw new CredentialUpstreamException(sprintf('The outside vault answered %d.', $status));
		}

		$data = json_decode((string) $response->getBody(), true);
		if (is_array($data) === false) {
			throw new CredentialUpstreamException('The outside vault gave an unreadable answer.');
		}

		return $data;
	}//end decode()
}//end class
