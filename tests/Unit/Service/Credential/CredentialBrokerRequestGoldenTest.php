<?php

/**
 * Golden characterisation test for CredentialBrokerService::request().
 *
 * Pins, on the code before request() was split into named steps, the allowed
 * call (method upper-cased, host-locked URL, the caller's headers plus the
 * broker-injected auth header, the body) and each refusal in guard order — owner,
 * allowed app, inject-only provider, allow-rule — with the reason it logs and
 * the proof that a refused call never reaches the outbound client.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/credential-broker/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

final class CredentialBrokerRequestGoldenTest extends TestCase {

	/**
	 * The outbound calls and warnings, in order.
	 *
	 * @var array<int, mixed>
	 */
	public array $trace = [];

	private function github(): array {
		return [
			'identifier' => 'github',
			'baseUrl' => 'https://api.github.com',
			'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
			'allowRules' => [['method' => 'POST', 'pathPattern' => '/repos/*/issues']],
		];
	}

	private function broker(string $ownerUid, array $credential, array $provider): CredentialBrokerService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$entity = new ObjectEntity();
		$entity->setOwner($ownerUid);
		$entity->setObject($credential);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturn($entity);

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($provider);
		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturn('SECRET123');

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(201);
		$response->method('getHeaders')->willReturn(['Content-Type' => ['application/json']]);
		$response->method('getBody')->willReturn('{"number":1}');
		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback(
			function (string $method, string $uri, array $options) use ($response): IResponse {
				$this->trace[] = ['call' => $method, 'uri' => $uri, 'headers' => $options['headers'] ?? null, 'body' => $options['body'] ?? null];
				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		$test   = $this;
		$logger = new class ($test) extends AbstractLogger {
			public function __construct(private CredentialBrokerRequestGoldenTest $test) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->test->trace[] = ['log' => (string)$level, 'message' => (string)$message];
			}
		};

		return new CredentialBrokerService($objects, $store, $catalogue, $session, $clients, $logger, $this->createMock(OrganisationService::class));
	}

	public function testTheAllowedCallIsHostLockedAndCarriesTheInjectedAuth(): void {
		$broker = $this->broker('alice', ['provider' => 'github', 'allowedApps' => ['hermiq']], $this->github());

		$result = $broker->request('cred-1', 'hermiq', 'post', '/repos/Conduction/issues', ['X-Trace' => 't-1'], '{"title":"x"}');

		$this->assertSame(201, $result['status']);
		$this->assertSame('{"number":1}', $result['body']);
		$calls = array_values(array_filter($this->trace, static fn (array $entry): bool => isset($entry['call'])));
		$this->assertCount(1, $calls);
		$this->assertSame('POST', $calls[0]['call']);
		$this->assertSame('https://api.github.com/repos/Conduction/issues', $calls[0]['uri']);
		$this->assertSame('t-1', $calls[0]['headers']['X-Trace']);
		$this->assertSame('token SECRET123', $calls[0]['headers']['Authorization']);
		$this->assertSame('{"title":"x"}', $calls[0]['body']);
	}

	public function testEachGuardRefusesInOrderWithoutAnOutboundCall(): void {
		$injectOnly = ['identifier' => 'generic-apikey', 'inject_only' => true, 'authScheme' => ['header' => 'Authorization', 'template' => '{secret}']];
		$cases = [
			'owner' => ['bob', ['provider' => 'github', 'allowedApps' => ['hermiq']], $this->github(), 'POST', '/repos/x/issues'],
			'allowed app' => ['alice', ['provider' => 'github', 'allowedApps' => ['other']], $this->github(), 'POST', '/repos/x/issues'],
			'inject-only' => ['alice', ['provider' => 'generic-apikey', 'allowedApps' => ['hermiq']], $injectOnly, 'GET', '/anything'],
			'allow-rule' => ['alice', ['provider' => 'github', 'allowedApps' => ['hermiq']], $this->github(), 'DELETE', '/repos/x/issues'],
		];
		$reasons = [];
		foreach ($cases as $name => [$owner, $credential, $provider, $method, $path]) {
			$this->trace = [];
			try {
				$this->broker($owner, $credential, $provider)->request('cred-1', 'hermiq', $method, $path);
				$this->fail($name . ' was allowed');
			} catch (CredentialAccessDeniedException $e) {
				$this->assertSame('Request not permitted', $e->getMessage(), $name);
			}

			$this->assertSame([], array_values(array_filter($this->trace, static fn (array $entry): bool => isset($entry['call']))), $name);
			$warnings       = array_filter($this->trace, static fn (array $entry): bool => ($entry['log'] ?? '') === 'warning');
			$reasons[$name] = array_values(array_map(static fn (array $entry): string => $entry['message'], $warnings));
		}

		$golden = json_decode((string)file_get_contents(__DIR__ . '/fixtures/credential-broker-request-refusals.json'), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$encoded = json_encode($reasons, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			file_put_contents(__DIR__ . '/fixtures/credential-broker-request-refusals.json', $encoded . "\n");
			$golden = $reasons;
		}

		$this->assertSame($golden, $reasons);
		$this->assertStringContainsString('inject-only provider cannot be proxied', implode(' ', $reasons['inject-only']));
	}
}//end class
