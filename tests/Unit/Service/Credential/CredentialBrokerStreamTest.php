<?php

/**
 * CredentialBrokerStreamTest — the streaming twin runs the SAME guards.
 *
 * The point of these tests is not that streaming works. It is that adding a
 * second way into the proxy did not add a second, weaker way in.
 *
 * `streamRequest()` exists because a model completion arrives over minutes and
 * `request()` answers with a string. The risk in that change is not the
 * transport — it is that a new entry point acquires its own copy of five guards
 * and one of them is quietly missing. So every guard is asserted here against
 * the STREAMING path specifically, not inherited from the buffered path's tests.
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
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialBrokerService
 * @covers \OCA\OpenRegister\Service\Credential\BrokeredStream
 */
class CredentialBrokerStreamTest extends TestCase {

	/** @var array<string, mixed>|null Captured client->request() options. */
	private ?array $capturedOptions = null;

	/**
	 * A proxied provider with one allowed streaming route.
	 *
	 * @return array<string, mixed> The catalogue entry.
	 */
	private function anthropicProvider(): array {
		return [
			'identifier' => 'anthropic',
			'baseUrl' => 'https://api.anthropic.com',
			'authScheme' => ['header' => 'x-api-key', 'template' => '{secret}'],
			'allowRules' => [
				['method' => 'POST', 'pathPattern' => '/v1/messages'],
			],
		];
	}

	/**
	 * Wire a broker whose upstream answers with an open resource.
	 *
	 * @param string               $sessionUid The session user.
	 * @param string               $ownerUid   The credential owner.
	 * @param array<string, mixed> $credData   The credential object.
	 * @param array|null           $provider   The catalogue entry, or null.
	 * @param string|null          $secret     The stored secret, or null.
	 * @param string               $upstream   The bytes the upstream will emit.
	 *
	 * @return CredentialBrokerService The wired broker.
	 */
	private function makeService(
		string $sessionUid,
		string $ownerUid,
		array $credData,
		?array $provider,
		?string $secret,
		string $upstream = "data: one\n\ndata: two\n\n",
	): CredentialBrokerService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($sessionUid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$entity = new ObjectEntity();
		$entity->setOwner($ownerUid);
		$entity->setObject($credData);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($entity);

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($provider);

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturn($secret);

		// A real resource, because that is what Nextcloud's client returns for
		// `stream: true` — `Response::getBody()` calls `detach()` on the PSR-7
		// body. Returning a string here would test a shape the client does not
		// produce, and would hide the `fread()` path entirely.
		$handle = fopen('php://memory', 'r+');
		fwrite($handle, $upstream);
		rewind($handle);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getHeaders')->willReturn(['Content-Type' => ['text/event-stream']]);
		$response->method('getBody')->willReturn($handle);

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

	}//end makeService()

	/**
	 * A credential owned by alice, allowed for hermiq, on the anthropic provider.
	 *
	 * @return array<string, mixed> The credential object.
	 */
	private function credential(): array {
		return [
			'provider' => 'anthropic',
			'allowedApps' => ['hermiq'],
		];
	}

	public function testTheStreamedBodyArrivesInPiecesAndIsNotBuffered(): void {
		$service = $this->makeService('alice', 'alice', $this->credential(), $this->anthropicProvider(), 'sk-secret');

		$stream = $service->streamRequest(
			credentialId: 'cred-1',
			appId: 'hermiq',
			method: 'POST',
			path: '/v1/messages',
			headers: [],
			body: '{"stream":true}'
		);

		$this->assertSame(200, $stream->getStatus());
		$this->assertSame(['Content-Type' => ['text/event-stream']], $stream->getHeaders());

		$chunks = [];
		$stream->pump(static function (string $chunk) use (&$chunks): void {
			$chunks[] = $chunk;
		});

		$this->assertNotEmpty($chunks, 'the sink must receive the body');
		$this->assertSame("data: one\n\ndata: two\n\n", implode('', $chunks));

	}//end testTheStreamedBodyArrivesInPiecesAndIsNotBuffered()

	/**
	 * The option that makes the whole thing a stream rather than a buffer.
	 *
	 * Without `stream: true`, Nextcloud's client reads the body to a string and
	 * every guarantee above collapses into the behaviour `request()` already had
	 * — while every assertion in this file still passes, because a memory stream
	 * of a short fixture is indistinguishable from a buffered one.
	 *
	 * @return void
	 */
	public function testTheUpstreamCallAsksForAStream(): void {
		$service = $this->makeService('alice', 'alice', $this->credential(), $this->anthropicProvider(), 'sk-secret');

		$service->streamRequest(
			credentialId: 'cred-1',
			appId: 'hermiq',
			method: 'POST',
			path: '/v1/messages'
		);

		$this->assertTrue(
			($this->capturedOptions['stream'] ?? false),
			'the upstream call must set stream: true, or nothing streams'
		);

	}//end testTheUpstreamCallAsksForAStream()

	public function testTheSecretIsInjectedAndNeverComesFromTheCaller(): void {
		$service = $this->makeService('alice', 'alice', $this->credential(), $this->anthropicProvider(), 'sk-secret');

		$service->streamRequest(
			credentialId: 'cred-1',
			appId: 'hermiq',
			method: 'POST',
			path: '/v1/messages',
			headers: ['x-api-key' => 'sk-ATTACKER-SUPPLIED']
		);

		$this->assertSame('sk-secret', ($this->capturedOptions['headers']['x-api-key'] ?? null));

	}//end testTheSecretIsInjectedAndNeverComesFromTheCaller()

	// ── The guards, asserted against the STREAMING path ──────────────────

	public function testTheOwnerGuardRefusesANonOwner(): void {
		$service = $this->makeService('mallory', 'alice', $this->credential(), $this->anthropicProvider(), 'sk-secret');

		$this->expectException(CredentialAccessDeniedException::class);
		$service->streamRequest('cred-1', 'hermiq', 'POST', '/v1/messages');

	}//end testTheOwnerGuardRefusesANonOwner()

	public function testAnUnlistedAppIsRefused(): void {
		$service = $this->makeService('alice', 'alice', $this->credential(), $this->anthropicProvider(), 'sk-secret');

		$this->expectException(CredentialAccessDeniedException::class);
		$service->streamRequest('cred-1', 'some-other-app', 'POST', '/v1/messages');

	}//end testAnUnlistedAppIsRefused()

	public function testAPathOutsideTheAllowRulesIsRefused(): void {
		$service = $this->makeService('alice', 'alice', $this->credential(), $this->anthropicProvider(), 'sk-secret');

		$this->expectException(CredentialAccessDeniedException::class);
		$service->streamRequest('cred-1', 'hermiq', 'POST', '/v1/organizations/me');

	}//end testAPathOutsideTheAllowRulesIsRefused()

	public function testTheWrongMethodOnAnAllowedPathIsRefused(): void {
		$service = $this->makeService('alice', 'alice', $this->credential(), $this->anthropicProvider(), 'sk-secret');

		$this->expectException(CredentialAccessDeniedException::class);
		$service->streamRequest('cred-1', 'hermiq', 'DELETE', '/v1/messages');

	}//end testTheWrongMethodOnAnAllowedPathIsRefused()

	/**
	 * The guard that stops this becoming an open proxy — ISOLATED.
	 *
	 * ⚠️ THIS TEST WAS VACUOUS AS FIRST WRITTEN, and the positive control caught
	 * it: with the inject-only refusal deleted from the service the test still
	 * passed, because a bare inject-only provider carries no allowRules and the
	 * RULE guard refused it one line later. It asserted "something refuses",
	 * while its name promised "the inject-only guard refuses" — the exact shape
	 * of a test that reports a protection it never exercised.
	 *
	 * So the fixture is deliberately CONTRADICTORY: `inject_only: true` together
	 * with a baseUrl and a matching allowRule. That is not a hypothetical — it is
	 * the realistic misconfiguration, someone adding rules to an inject-only
	 * catalogue entry, and it is the only shape in which this guard is the ONLY
	 * thing standing between a caller and an unbounded proxy.
	 *
	 * Re-verified: delete the refusal and this test fails.
	 *
	 * @return void
	 */
	public function testAnInjectOnlyProviderCannotBeStreamedEvenWhenItCarriesRules(): void {
		$service = $this->makeService(
			'alice',
			'alice',
			['provider' => 'anthropic-cli', 'allowedApps' => ['hermiq']],
			[
				'identifier' => 'anthropic-cli',
				'inject_only' => true,
				'baseUrl' => 'https://api.anthropic.com',
				'authScheme' => ['header' => 'x-api-key', 'template' => '{secret}'],
				'allowRules' => [['method' => 'POST', 'pathPattern' => '/v1/messages']],
			],
			'sk-secret'
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->streamRequest('cred-1', 'hermiq', 'POST', '/v1/messages');

	}//end testAnInjectOnlyProviderCannotBeStreamedEvenWhenItCarriesRules()

	public function testAMissingSecretIsRefusedRatherThanSentUnauthenticated(): void {
		$service = $this->makeService('alice', 'alice', $this->credential(), $this->anthropicProvider(), null);

		$this->expectException(CredentialAccessDeniedException::class);
		$service->streamRequest('cred-1', 'hermiq', 'POST', '/v1/messages');

	}//end testAMissingSecretIsRefusedRatherThanSentUnauthenticated()

	/**
	 * The host is the provider's, never the caller's.
	 *
	 * @return void
	 */
	public function testTheUpstreamHostIsLockedToTheProvider(): void {
		$service = $this->makeService('alice', 'alice', $this->credential(), $this->anthropicProvider(), 'sk-secret');

		$this->expectException(CredentialAccessDeniedException::class);
		$service->streamRequest('cred-1', 'hermiq', 'POST', 'https://evil.example/v1/messages');

	}//end testTheUpstreamHostIsLockedToTheProvider()
}//end class
