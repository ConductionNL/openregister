<?php
/**
 * Unit tests for OutsideVaultReader, OutsideVaultConnection and VaultReference.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Credential;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\Credential\OutsideVaultConnection;
use OCA\OpenRegister\Service\Credential\OutsideVaultReader;
use OCA\OpenRegister\Service\Credential\VaultReference;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(OutsideVaultReader::class)]
#[CoversClass(OutsideVaultConnection::class)]
#[CoversClass(VaultReference::class)]
final class OutsideVaultReaderTest extends TestCase {

	/**
	 * The calls made: method, url, options.
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * Everything logged, as text.
	 *
	 * @var list<string>
	 */
	private array $logged = [];

	/**
	 * A reader whose HTTP client answers from the given routes.
	 *
	 * @param array<string, array{0: int, 1: string}|\Throwable> $routes Answers by "METHOD url".
	 *
	 * @return OutsideVaultReader The reader.
	 */
	private function reader(array $routes): OutsideVaultReader {
		$client = $this->createMock(IClient::class);
		$answer = function (string $method, string $url, array $options) use ($routes): IResponse {
			$this->calls[] = [$method, $url, $options];
			$route = ($routes[strtoupper($method).' '.$url] ?? [404, '{}']);
			if ($route instanceof \Throwable) {
				throw $route;
			}

			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($route[0]);
			$response->method('getBody')->willReturn($route[1]);
			return $response;
		};
		$client->method('get')->willReturnCallback(fn (string $url, array $options = []): IResponse => $answer('get', $url, $options));
		$client->method('post')->willReturnCallback(fn (string $url, array $options = []): IResponse => $answer('post', $url, $options));

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($client);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = $message.' '.json_encode($context);
		});

		return new OutsideVaultReader($service, $logger);
	}

	private static function secretBody(array $data): string {
		return (string) json_encode(['data' => ['data' => $data, 'metadata' => ['version' => 3]]]);
	}

	private const READ_URL = 'https://vault.example/v1/secret/data/integriq/zaaksysteem';

	public function testATokenConnectionReadsTheKeyWithItsToken(): void {
		$reader = $this->reader(['GET '.self::READ_URL => [200, self::secretBody(['password' => 's3cr3t-pw', 'user' => 'zs'])]]);

		$value = $reader->read(
			new OutsideVaultConnection('https://vault.example/', OutsideVaultConnection::AUTH_TOKEN, 'hvs.token'),
			new VaultReference('conn-1', 'integriq/zaaksysteem', 'password')
		);

		$this->assertSame('s3cr3t-pw', $value);
		$this->assertCount(1, $this->calls);
		$this->assertSame('get', $this->calls[0][0]);
		$this->assertSame(self::READ_URL, $this->calls[0][1]);
		$this->assertSame('hvs.token', $this->calls[0][2]['headers']['X-Vault-Token']);
		$this->assertSame(2, $this->calls[0][2]['timeout']);
		$this->assertFalse($this->calls[0][2]['http_errors']);
	}

	public function testAnAppRoleConnectionLogsInOnceAndReadsOncePerRequest(): void {
		$reader = $this->reader(
			[
				'POST https://bao.example/v1/auth/approle/login' => [200, '{"auth":{"client_token":"s.login"}}'],
				'GET https://bao.example/v1/kv/data/apps/a' => [200, self::secretBody(['password' => 'pw-a', 'user' => 'u-a'])],
			]
		);
		$connection = new OutsideVaultConnection('https://bao.example', OutsideVaultConnection::AUTH_APPROLE, 'secret-id', 'role-id', 'kv');

		$this->assertSame('pw-a', $reader->read($connection, new VaultReference('conn-2', 'apps/a', 'password')));
		$this->assertSame('pw-a', $reader->read($connection, new VaultReference('conn-2', 'apps/a', 'password')));
		$this->assertSame('u-a', $reader->read($connection, new VaultReference('conn-2', 'apps/a', 'user')));

		$this->assertSame(['post', 'get', 'get'], array_column($this->calls, 0));
		$this->assertSame(['role_id' => 'role-id', 'secret_id' => 'secret-id'], $this->calls[0][2]['json']);
		$this->assertSame('s.login', $this->calls[1][2]['headers']['X-Vault-Token']);
		$this->assertSame('s.login', $this->calls[2][2]['headers']['X-Vault-Token']);
	}

	public function testAMissingKeyIsAnUpstreamErrorWithoutTheSecret(): void {
		$reader = $this->reader(['GET '.self::READ_URL => [200, self::secretBody(['user' => 'zs', 'other' => 'leak-me'])]]);

		try {
			$reader->read(new OutsideVaultConnection('https://vault.example', 'token', 'hvs.token'), new VaultReference('conn-1', 'integriq/zaaksysteem', 'password'));
			$this->fail('Expected an upstream error');
		} catch (CredentialUpstreamException $e) {
			$this->assertSame('The outside vault holds no value for this reference.', $e->getMessage());
		}

		$this->assertStringNotContainsString('leak-me', implode("\n", $this->logged));
		$this->assertStringNotContainsString('hvs.token', implode("\n", $this->logged));
	}

	public function testARefusalNamesTheStatusNotTheBody(): void {
		$reader = $this->reader(['GET '.self::READ_URL => [403, '{"errors":["permission denied for hvs.token"]}']]);

		try {
			$reader->read(new OutsideVaultConnection('https://vault.example', 'token', 'hvs.token'), new VaultReference('conn-1', 'integriq/zaaksysteem', 'password'));
			$this->fail('Expected an upstream error');
		} catch (CredentialUpstreamException $e) {
			$this->assertSame('The outside vault answered 403.', $e->getMessage());
		}

		$this->assertStringNotContainsString('permission denied', implode("\n", $this->logged));
		$this->assertStringContainsString('"status":403', implode("\n", $this->logged));
	}

	public function testARevokedAppRoleIsARefusedLogin(): void {
		$reader = $this->reader(['POST https://bao.example/v1/auth/approle/login' => [400, '{"errors":["invalid secret id"]}']]);

		$this->expectException(CredentialUpstreamException::class);
		$this->expectExceptionMessage('The outside vault answered 400.');
		$reader->read(new OutsideVaultConnection('https://bao.example', 'approle', 'secret-id', 'role-id'), new VaultReference('conn-2', 'apps/a', 'password'));
	}

	public function testALoginWithoutATokenIsRefused(): void {
		$reader = $this->reader(['POST https://bao.example/v1/auth/approle/login' => [200, '{"auth":{}}']]);

		$this->expectException(CredentialUpstreamException::class);
		$this->expectExceptionMessage('The outside vault refused the login.');
		$reader->read(new OutsideVaultConnection('https://bao.example', 'approle', 'secret-id', 'role-id'), new VaultReference('conn-2', 'apps/a', 'password'));
	}

	public function testAnUnreachableVaultIsAnUpstreamErrorWithoutItsMessage(): void {
		$reader = $this->reader(['GET '.self::READ_URL => new RuntimeException('cURL error 28 with token hvs.token')]);

		try {
			$reader->read(new OutsideVaultConnection('https://vault.example', 'token', 'hvs.token'), new VaultReference('conn-1', 'integriq/zaaksysteem', 'password'));
			$this->fail('Expected an upstream error');
		} catch (CredentialUpstreamException $e) {
			$this->assertSame('The outside vault could not be reached.', $e->getMessage());
		}

		$this->assertStringNotContainsString('hvs.token', implode("\n", $this->logged));
	}

	public function testAnUnreadableAnswerIsAnUpstreamError(): void {
		$reader = $this->reader(['GET '.self::READ_URL => [200, 'not json']]);

		$this->expectException(CredentialUpstreamException::class);
		$this->expectExceptionMessage('The outside vault gave an unreadable answer.');
		$reader->read(new OutsideVaultConnection('https://vault.example', 'token', 'hvs.token'), new VaultReference('conn-1', 'integriq/zaaksysteem', 'password'));
	}

	/**
	 * @return array<string, array{0: callable}>
	 */
	public static function invalidSettings(): array {
		return [
			'file address' => [static fn () => new OutsideVaultConnection('file:///etc/passwd', 'token', 't')],
			'no host' => [static fn () => new OutsideVaultConnection('https://', 'token', 't')],
			'unknown login' => [static fn () => new OutsideVaultConnection('https://v.example', 'userpass', 't')],
			'approle without role' => [static fn () => new OutsideVaultConnection('https://v.example', 'approle', 't', ' ')],
			'mount that climbs' => [static fn () => new OutsideVaultConnection('https://v.example', 'token', 't', null, '../sys')],
			'path that climbs' => [static fn () => new VaultReference('c', 'apps/../../sys/raw', 'k')],
			'absolute path' => [static fn () => new VaultReference('c', '/apps/a', 'k')],
			'path with a query' => [static fn () => new VaultReference('c', 'apps/a?version=1', 'k')],
			'no key' => [static fn () => VaultReference::fromArray(['connection' => 'c', 'path' => 'apps/a'])],
			'no connection' => [static fn () => VaultReference::fromArray(['path' => 'apps/a', 'key' => 'k'])],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidSettings')]
	public function testSettingsThatCouldReachElsewhereAreRefused(callable $make): void {
		$this->expectException(InvalidArgumentException::class);
		$make();
	}

	public function testAReferenceReadsFromItsStoredFormAndADumpHidesTheSecret(): void {
		$reference = VaultReference::fromArray(['connection' => 'conn-1', 'path' => 'integriq/zaaksysteem', 'key' => 'password']);
		$this->assertSame(['conn-1', 'integriq/zaaksysteem', 'password'], [$reference->connection, $reference->path, $reference->key]);

		$connection = new OutsideVaultConnection('https://vault.example/', 'token', 'hvs.token');
		$this->assertSame('https://vault.example', $connection->address());
		$this->assertStringNotContainsString('hvs.token', print_r($connection, true));
		$this->assertSame('secret', $connection->__debugInfo()['mount']);
	}
}
