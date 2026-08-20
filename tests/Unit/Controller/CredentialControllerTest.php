<?php

/**
 * CredentialControllerTest — the session-authenticated browser broker endpoint.
 *
 * Exercises CredentialController::sessionBrokerRequest() end-to-end against a REAL
 * CredentialBrokerService wired with mocked collaborators, so the four ordered broker
 * guards actually fire. The controller and the broker share ONE IUserSession mock: this
 * proves the owner IDOR guard evaluates against the current SESSION user on the session
 * path (a user must not be able to use a credential they do not own), and that the stored
 * secret never appears in any response body.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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

namespace Unit\Controller;

use OCA\OpenRegister\Controller\CredentialController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\CredentialController
 */
class CredentialControllerTest extends TestCase {
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
			],
		];
	}//end githubProvider()

	/**
	 * Build a CredentialController whose broker is a REAL CredentialBrokerService wired
	 * with mocked collaborators. The controller and the broker share one IUserSession
	 * mock so the owner guard evaluates against the session user on the session path.
	 *
	 * @param string|null $sessionUid The session user's UID, or null for anonymous.
	 * @param string $ownerUid The credential owner's UID.
	 * @param array<string, mixed> $credData The credential object's property bag.
	 * @param array<string,mixed>|null $provider The catalogue provider entry, or null.
	 * @param string|null $secret The stored secret, or null.
	 * @param bool $clientThrows Whether the outbound client throws (upstream failure).
	 * @param array<string, mixed> $params The request body params (appId, method, path, headers, body).
	 *
	 * @return CredentialController The wired controller.
	 */
	private function makeController(
		?string $sessionUid,
		string $ownerUid,
		array $credData,
		?array $provider,
		?string $secret,
		bool $clientThrows,
		array $params,
	): CredentialController {
		// Shared session — feeds BOTH the controller's currentUid() and the broker's owner guard.
		$session = $this->createMock(IUserSession::class);
		if ($sessionUid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
			$session->method('getUser')->willReturn($user);
		}

		// A real ObjectEntity — getOwner()/jsonSerialize() are magic accessors.
		$entity = new ObjectEntity();
		$entity->setOwner($ownerUid);
		$entity->setObject($credData);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($entity);

		$catalogue = $this->createMock(ProviderCatalogue::class);
		$catalogue->method('get')->willReturn($provider);

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturn($secret);

		$client = $this->createMock(IClient::class);
		if ($clientThrows === true) {
			$client->method('request')->willThrowException(new RuntimeException('boom'));
		} else {
			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn(200);
			$response->method('getHeaders')->willReturn(['Content-Type' => ['application/json']]);
			$response->method('getBody')->willReturn('{"full_name":"Conduction/openregister"}');
			$client->method('request')->willReturn($response);
		}

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$broker = new CredentialBrokerService(
			$objectService,
			$store,
			$catalogue,
			$session,
			$clientService,
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationService::class)
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return array_key_exists($key, $params) ? $params[$key] : $default;
			}
		);

		return new CredentialController(
			'openregister',
			$request,
			$session,
			$this->createMock(IGroupManager::class),
			$objectService,
			$store,
			$catalogue,
			$broker,
			$this->createMock(CredentialAppTokenService::class),
			$this->createMock(OrganisationService::class),
			new SharePrincipalDeriver()
		);
	}//end makeController()

	/**
	 * Owner + allowed app + allowed method/path → returns the broker result.
	 */
	public function testSessionOwnerAllowedAppReturnsBrokerResult(): void {
		$controller = $this->makeController(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123',
			false,
			['appId' => 'hermiq', 'method' => 'GET', 'path' => '/repos/Conduction/openregister']
		);

		$response = $controller->sessionBrokerRequest('cred-1');
		$data = $response->getData();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(200, $data['status']);
		$this->assertStringContainsString('full_name', $data['body']);
	}//end testSessionOwnerAllowedAppReturnsBrokerResult()

	/**
	 * Not the owner → owner IDOR guard denies → 403 (proves the session identity feeds the guard).
	 */
	public function testNonOwnerRejectedByOwnerGuard(): void {
		$controller = $this->makeController(
			'alice',
			'bob',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123',
			false,
			['appId' => 'hermiq', 'method' => 'GET', 'path' => '/repos/Conduction/openregister']
		);

		$response = $controller->sessionBrokerRequest('cred-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Request not permitted'], $response->getData());
	}//end testNonOwnerRejectedByOwnerGuard()

	/**
	 * appId not in allowedApps → allowed-app guard denies → 403.
	 */
	public function testAppNotAllowedRejected(): void {
		$controller = $this->makeController(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['someoneelse']],
			$this->githubProvider(),
			'SECRET123',
			false,
			['appId' => 'hermiq', 'method' => 'GET', 'path' => '/repos/Conduction/openregister']
		);

		$response = $controller->sessionBrokerRequest('cred-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Request not permitted'], $response->getData());
	}//end testAppNotAllowedRejected()

	/**
	 * Empty appId in the body → 403 before the broker is ever consulted.
	 */
	public function testEmptyAppIdRejected(): void {
		$controller = $this->makeController(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123',
			false,
			['appId' => '', 'method' => 'GET', 'path' => '/repos/Conduction/openregister']
		);

		$response = $controller->sessionBrokerRequest('cred-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Request not permitted'], $response->getData());
	}//end testEmptyAppIdRejected()

	/**
	 * Disallowed method/path → allow-rule guard denies → 403.
	 */
	public function testDisallowedMethodPathRejected(): void {
		$controller = $this->makeController(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123',
			false,
			// DELETE is not in the github allow-rules.
			['appId' => 'hermiq', 'method' => 'DELETE', 'path' => '/repos/Conduction/openregister']
		);

		$response = $controller->sessionBrokerRequest('cred-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'Request not permitted'], $response->getData());
	}//end testDisallowedMethodPathRejected()

	/**
	 * No session user → 401 before the broker is ever consulted.
	 */
	public function testNoSessionRejected(): void {
		$controller = $this->makeController(
			null,
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123',
			false,
			['appId' => 'hermiq', 'method' => 'GET', 'path' => '/repos/Conduction/openregister']
		);

		$response = $controller->sessionBrokerRequest('cred-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testNoSessionRejected()

	/**
	 * Transport-level upstream failure → broker throws CredentialUpstreamException → 502.
	 */
	public function testUpstreamFailureMapsTo502(): void {
		$controller = $this->makeController(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SECRET123',
			true,
			['appId' => 'hermiq', 'method' => 'GET', 'path' => '/repos/Conduction/openregister']
		);

		$response = $controller->sessionBrokerRequest('cred-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['message' => 'Upstream request failed'], $response->getData());
	}//end testUpstreamFailureMapsTo502()

	/**
	 * The stored secret never appears in any response body (happy path).
	 */
	public function testSecretNeverAppearsInResponse(): void {
		$controller = $this->makeController(
			'alice',
			'alice',
			['provider' => 'github', 'allowedApps' => ['hermiq']],
			$this->githubProvider(),
			'SUPERSECRETTOKEN',
			false,
			['appId' => 'hermiq', 'method' => 'GET', 'path' => '/repos/Conduction/openregister']
		);

		$response = $controller->sessionBrokerRequest('cred-1');
		$encoded = json_encode($response->getData());

		$this->assertIsString($encoded);
		$this->assertStringNotContainsString('SUPERSECRETTOKEN', $encoded);
	}//end testSecretNeverAppearsInResponse()

	/**
	 * PUT /api/credentials/{id} is the ROTATION path — it writes to the vault
	 * directly, bypassing CredentialBrokerService::mint() entirely, so it needs
	 * its own trim (credential-broker-upstream-diagnostics D3). Pins the exact
	 * incident: a secret rotated with a trailing newline must reach the vault
	 * trimmed, not byte-for-byte.
	 */
	public function testUpdateTrimsTrailingWhitespaceFromARotatedSecret(): void {
		$capturedSecret = null;
		$store = $this->createMock(CredentialStore::class);
		$store->method('put')->willReturnCallback(
			function (string $uuid, string $secret, string $scope) use (&$capturedSecret) {
				$capturedSecret = $secret;
			}
		);

		$controller = $this->makeUpdateController(
			ownerUid: 'alice',
			credData: ['name' => 'My GitHub', 'provider' => 'github', 'allowedApps' => ['hermiq']],
			params: ['secret' => "gho_rotated\n"],
			store: $store
		);

		$response = $controller->update('cred-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('gho_rotated', $capturedSecret);
	}//end testUpdateTrimsTrailingWhitespaceFromARotatedSecret()

	/**
	 * A whitespace-only rotated secret trims to '' and is treated as "no
	 * rotation requested" — the vault is never touched.
	 */
	public function testUpdateWithWhitespaceOnlySecretNeverTouchesTheVault(): void {
		$store = $this->createMock(CredentialStore::class);
		$store->expects($this->never())->method('put');

		$controller = $this->makeUpdateController(
			ownerUid: 'alice',
			credData: ['name' => 'My GitHub', 'provider' => 'github', 'allowedApps' => ['hermiq']],
			params: ['secret' => "  \n\t "],
			store: $store
		);

		$response = $controller->update('cred-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testUpdateWithWhitespaceOnlySecretNeverTouchesTheVault()

	/**
	 * Build a CredentialController for exercising update() — an owned personal
	 * credential, a stub saveObject() that echoes the merged property bag back,
	 * and a caller-supplied CredentialStore mock to assert the vault write.
	 *
	 * @param string $ownerUid The session/owner uid (owner guard passes).
	 * @param array<string, mixed> $credData The existing credential's property bag.
	 * @param array<string, mixed> $params The request body params (e.g. `secret`).
	 * @param CredentialStore&\PHPUnit\Framework\MockObject\MockObject $store The vault mock.
	 *
	 * @return CredentialController The wired controller.
	 */
	private function makeUpdateController(
		string $ownerUid,
		array $credData,
		array $params,
		CredentialStore $store,
	): CredentialController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($ownerUid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$entity = new ObjectEntity();
		$entity->setOwner($ownerUid);
		$entity->setObject($credData);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($entity);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) {
				$saved = new ObjectEntity();
				$saved->setObject($object);
				return $saved;
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return array_key_exists($key, $params) ? $params[$key] : $default;
			}
		);

		return new CredentialController(
			'openregister',
			$request,
			$session,
			$this->createMock(IGroupManager::class),
			$objectService,
			$store,
			$this->createMock(ProviderCatalogue::class),
			$this->createMock(CredentialBrokerService::class),
			$this->createMock(CredentialAppTokenService::class),
			$this->createMock(OrganisationService::class),
			new SharePrincipalDeriver()
		);
	}//end makeUpdateController()
}//end class
