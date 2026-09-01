<?php

/**
 * CredentialBrokerServiceTest — the four ordered guards + secret-injecting call.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
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

namespace Unit\Service\Credential;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialBrokerService
 */
class CredentialBrokerServiceTest extends TestCase {
	/** @var array<string, mixed>|null Captured client->request() options. */
	private ?array $capturedOptions = null;

	/**
	 * The github catalogue entry used across the happy-path tests.
	 *
	 * @return array<string, mixed>
	 */
	private function githubProvider(): array {
		return [
			'identifier' => 'github',
			'baseUrl' => 'https://api.github.com',
			'authScheme' => ['header' => 'Authorization', 'template' => 'token {secret}'],
			'allowRules' => [
				['method' => 'GET', 'pathPattern' => '/repos/*'],
				['method' => 'GET', 'pathPattern' => '/user/repos'],
			],
		];
	}

	/**
	 * Wire a broker with fully-mocked collaborators.
	 *
	 * @param array<string, mixed> $credData
	 */
	private function makeService(
		string $sessionUid,
		string $ownerUid,
		array $credData,
		?array $provider,
		?string $secret,
	): CredentialBrokerService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($sessionUid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		// A real ObjectEntity — getOwner()/jsonSerialize() are magic accessors
		// that cannot be stubbed on a mock.
		$entity = new ObjectEntity();
		$entity->setOwner($ownerUid);
		$entity->setObject($credData);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($entity);

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($provider);

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturn($secret);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn(['Content-Type' => ['application/json']]);
		$response->method('getBody')->willReturn('{"full_name":"Conduction/openregister"}');

		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturnCallback(
			function (string $method, string $uri, array $options) use ($response) {
				$this->capturedOptions = $options;
				return $response;
			}
		);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new CredentialBrokerService(
			$objectService,
			$store,
			$catalogue,
			$session,
			$clientService,
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationService::class)
		);
	}

	/** @var array<string, mixed>|null Captured logger->error() context. */
	private ?array $capturedLogContext = null;

	/**
	 * Wire a broker whose outbound client throws a transport-level exception,
	 * with a logger mock that captures the context passed to error().
	 *
	 * @param \Throwable $transportException The exception the client throws.
	 */
	private function makeServiceWithTransportFailure(\Throwable $transportException): CredentialBrokerService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$entity = new ObjectEntity();
		$entity->setOwner('alice');
		$entity->setObject(['provider' => 'github', 'allowedApps' => ['hermiq']]);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($entity);

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($this->githubProvider());

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturn('SUPERSECRETTOKEN' . "\n");

		$client = $this->createMock(IClient::class);
		$client->method('request')->willThrowException($transportException);
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []) {
				$this->capturedLogContext = $context;
			}
		);

		return new CredentialBrokerService(
			$objectService,
			$store,
			$catalogue,
			$session,
			$clientService,
			$logger,
			$this->createMock(OrganisationService::class)
		);
	}

	/**
	 * The thrown exception carries the real, secret-free reason — not the
	 * hardcoded generic literal the broker used to throw.
	 */
	public function testUpstreamFailureExceptionCarriesRedactedRealReason(): void {
		$secret = 'SUPERSECRETTOKEN' . "\n";
		$transportException = new InvalidArgumentException(
			'"token ' . $secret . '" is not valid header value'
		);
		$service = $this->makeServiceWithTransportFailure($transportException);

		try {
			$service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/openregister');
			$this->fail('Expected CredentialUpstreamException');
		} catch (CredentialUpstreamException $e) {
			// The real reason survives...
			$this->assertStringContainsString('is not valid header value', $e->getMessage());
			$this->assertStringContainsString(InvalidArgumentException::class, $e->getMessage());
			// ...but the secret never appears in the exception message.
			$this->assertStringNotContainsString('SUPERSECRETTOKEN', $e->getMessage());
		}
	}

	/**
	 * The server-side log line carries the same redacted reason — never the raw
	 * secret, even when the underlying transport exception's own message
	 * embedded it (the exact openregister broker-error-swallow scenario).
	 */
	public function testUpstreamFailureLogLineNeverContainsTheRawSecret(): void {
		$secret = 'SUPERSECRETTOKEN' . "\n";
		$transportException = new InvalidArgumentException(
			'"token ' . $secret . '" is not valid header value'
		);
		$service = $this->makeServiceWithTransportFailure($transportException);

		try {
			$service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/openregister');
		} catch (CredentialUpstreamException $e) {
			// Expected — assert the log capture below.
		}

		$this->assertNotNull($this->capturedLogContext);
		$logged = (string)json_encode($this->capturedLogContext);
		$this->assertStringNotContainsString('SUPERSECRETTOKEN', $logged);
		$this->assertStringContainsString('is not valid header value', $logged);
	}

	public function testOwnerGuardRejectsNonOwner(): void {
		$service = $this->makeService(
			'alice',
			'bob',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123'
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/openregister');
	}

	public function testDisallowedAppRejected(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['someoneelse']],
			$this->githubProvider(),
			'SECRET123'
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/openregister');
	}

	public function testDisallowedMethodPathRejected(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123'
		);

		// DELETE is not in the github allow-rules.
		$this->expectException(CredentialAccessDeniedException::class);
		$service->request('cred-1', 'hermiq', 'DELETE', '/repos/Conduction/openregister');
	}

	public function testPathTraversalRejected(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123'
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->request('cred-1', 'hermiq', 'GET', '/repos/../../admin');
	}

	public function testHappyPathInjectsAuthAndReturnsUpstream(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123'
		);

		$result = $service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/openregister');

		$this->assertSame(200, $result['status']);
		$this->assertStringContainsString('full_name', $result['body']);

		// The provider auth scheme injected the secret into the Authorization header.
		$this->assertSame('token SECRET123', $this->capturedOptions['headers']['Authorization']);
	}

	public function testSecretNeverAppearsInReturnValue(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SUPERSECRETTOKEN'
		);

		$result = $service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/openregister');
		$encoded = json_encode($result);

		$this->assertIsString($encoded);
		$this->assertStringNotContainsString('SUPERSECRETTOKEN', $encoded);
	}

	/**
	 * A generic inject-only catalogue entry (no baseUrl, no allowRules).
	 *
	 * @return array<string, mixed>
	 */
	private function genericProvider(): array {
		return [
			'identifier' => 'generic-apikey',
			'inject_only' => true,
			'authScheme' => ['header' => 'Authorization', 'template' => '{secret}'],
		];
	}

	public function testResolveInjectableReturnsSecretForOwnerAndAllowedApp(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'generic-apikey', 'allowedApps' => ['openconnector']],
			$this->genericProvider(),
			'VAULT-KEY-XYZ'
		);

		$secret = $service->resolveInjectable('cred-1', 'openconnector');

		$this->assertSame('VAULT-KEY-XYZ', $secret);
	}

	public function testResolveInjectableEnforcesOwnerGuard(): void {
		$service = $this->makeService(
			'alice',
			'bob',
			['provider' => 'generic-apikey', 'allowedApps' => ['openconnector']],
			$this->genericProvider(),
			'VAULT-KEY-XYZ'
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->resolveInjectable('cred-1', 'openconnector');
	}

	public function testResolveInjectableEnforcesAllowedAppsGuard(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'generic-apikey', 'allowedApps' => ['someoneelse']],
			$this->genericProvider(),
			'VAULT-KEY-XYZ'
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->resolveInjectable('cred-1', 'openconnector');
	}

	public function testResolveInjectableReturnsNullForProxyCredential(): void {
		// A normal host-locked provider is NOT inject-only: its secret stays inside OR,
		// so resolveInjectable signals "use the proxy path" by returning null.
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['openconnector']],
			$this->githubProvider(),
			'SECRET123'
		);

		$this->assertNull($service->resolveInjectable('cred-1', 'openconnector'));
	}

	public function testResolveInjectableDeniesWhenNoSecretStored(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'generic-apikey', 'allowedApps' => ['openconnector']],
			$this->genericProvider(),
			null
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->resolveInjectable('cred-1', 'openconnector');
	}

	public function testRequestRefusesToProxyAnInjectOnlyProvider(): void {
		// Guards 1 + 2 pass, but an inject-only provider must never be proxied — it has no
		// host to lock, so request() fails closed rather than becoming an open proxy.
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'generic-apikey', 'allowedApps' => ['openconnector']],
			$this->genericProvider(),
			'VAULT-KEY-XYZ'
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->request('cred-1', 'openconnector', 'GET', '/anything');
	}

	/**
	 * A commit COMPARISON is not traversal, and it is the path the safety rail needs.
	 *
	 * GitHub's diff endpoint is `/repos/{o}/{r}/compare/{base}...{head}`. The
	 * guard used to reject any `..` SUBSTRING, so every commit comparison was
	 * denied — which made `hydra-flows-first-port` task 2.5's mandatory rail
	 * ("diff the produced tree against the base before moving the ref")
	 * impossible to build over the brokered path.
	 */
	public function testACommitComparisonIsNotTraversal(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123'
		);

		$result = $service->request(
			'cred-1',
			'hermiq',
			'GET',
			'/repos/Conduction/openregister/compare/ba3ed4f4...4f75bc84'
		);

		$this->assertSame(200, $result['status']);
	}

	/**
	 * Real traversal is still denied — a segment that IS `..`.
	 */
	public function testTraversalSegmentIsStillDenied(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123'
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/../../etc/passwd');
	}

	/**
	 * And still denied when the traversal segment arrives percent-encoded.
	 *
	 * The guard decodes ONCE before checking, so `%2e%2e` is caught. Pinned
	 * separately because a segment-based check would be trivially bypassable
	 * if it ran before the decode.
	 */
	public function testEncodedTraversalSegmentIsStillDenied(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123'
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/%2e%2e/%2e%2e/etc/passwd');
	}

	/**
	 * A segment that merely CONTAINS dots is a literal name and is allowed.
	 */
	public function testASegmentContainingDotsIsAllowed(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123'
		);

		$result = $service->request('cred-1', 'hermiq', 'GET', '/repos/Conduction/some..name');

		$this->assertSame(200, $result['status']);
	}
}//end class
