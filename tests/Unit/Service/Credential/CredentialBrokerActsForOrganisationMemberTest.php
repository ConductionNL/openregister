<?php

/**
 * CredentialBrokerActsForOrganisationMemberTest — a background task acts for an organisation member.
 *
 * Pins broker-acts-for-an-organisation-member: Nextcloud runs Assistant tasks from cron with
 * no user session, and hermiq forwards the task's user as `actingUserId`. For an
 * `organisation` credential the broker must admit that call only when the named user is an
 * existing, enabled member of the credential's organisation, and must keep every other
 * verdict: a session stays authoritative, a personal credential serves only its owner, the
 * HTTP endpoints never forward an acting user and refuse a caller without a session.
 *
 * Runs the REAL `request()` guard chain over the REAL provider catalogue (the `anthropic`
 * entry, host-locked to api.anthropic.com), so the admitted case proves the call is built
 * and sent to the right host with the organisation-vault secret injected.
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
 *
 * @spec openspec/changes/broker-acts-for-an-organisation-member/specs/credential-broker/spec.md#requirement-background-acting-user-resolution
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Controller\CredentialController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialAppTokenService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCA\OpenRegister\Service\Credential\CredentialStore;
use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\CredentialBrokerService
 * @covers \OCA\OpenRegister\Controller\CredentialController
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\Credential\ProviderCatalogue
 */
class CredentialBrokerActsForOrganisationMemberTest extends TestCase {
	private const UUID = '11111111-2222-3333-4444-555555555555';

	private const ORG = 'org-uuid-municipality';

	private const SECRET = 'DUMMY-ORG-KEY-NOT-REAL';

	/**
	 * A background task acting for an enabled member reaches api.anthropic.com with the org secret.
	 *
	 * On the old code the organisation guard ignored `actingUserId` on the sessionless path
	 * and denied ("requires a matching acting organisation"), so this test failed there.
	 */
	public function testSessionlessActingMemberIsAdmittedAndTheOrgSecretIsInjected(): void {
		$sent = [];
		$client = $this->createMock(IClient::class);
		$client->expects($this->once())->method('request')->willReturnCallback(
			function (string $method, string $url, array $options) use (&$sent): IResponse {
				$sent = ['method' => $method, 'url' => $url, 'headers' => ($options['headers'] ?? [])];
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getHeaders')->willReturn([]);
				$response->method('getBody')->willReturn('{"content":[]}');
				return $response;
			}
		);

		$store = $this->createMock(CredentialStore::class);
		$store->expects($this->once())->method('get')->with(self::UUID, 'organisation')->willReturn(self::SECRET);

		$orgService = $this->createMock(OrganisationService::class);
		$orgService->expects($this->once())->method('isMemberOfOrganisation')
			->with(self::ORG, 'bob')
			->willReturn(true);

		$broker = $this->makeBroker(
			sessionUid: null,
			client: $client,
			store: $store,
			orgService: $orgService,
			users: ['bob' => true]
		);

		$result = $broker->request(
			credentialId: self::UUID,
			appId: 'hermiq',
			method: 'POST',
			path: '/v1/messages',
			body: '{"model":"claude"}',
			actingUserId: 'bob'
		);

		$this->assertSame(200, $result['status']);
		$this->assertSame('POST', $sent['method']);
		$this->assertSame('https://api.anthropic.com/v1/messages', $sent['url']);
		$this->assertSame(self::SECRET, $sent['headers']['x-api-key'] ?? null);
		$this->assertStringNotContainsString(self::SECRET, json_encode($result));
	}

	/**
	 * A Nextcloud administrator who is NOT on the organisation's member list is refused on
	 * the background path, even though the session rule would let an admin in anywhere.
	 *
	 * Fails on the first version of this change, which used the session rule (admin passes).
	 */
	public function testSessionlessActingAdminWhoIsNotAMemberIsDenied(): void {
		$orgService = $this->createMock(OrganisationService::class);
		$orgService->method('userHasAccessToOrganisation')->willReturn(true);
		$orgService->method('hasAccessToOrganisation')->willReturn(true);
		$orgService->expects($this->once())->method('isMemberOfOrganisation')
			->with(self::ORG, 'admin')
			->willReturn(false);

		$broker = $this->makeBroker(
			sessionUid: null,
			client: $this->neverCalledClient(),
			store: $this->neverReadStore(),
			orgService: $orgService,
			users: ['admin' => true]
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: self::UUID, appId: 'hermiq', method: 'POST', path: '/v1/messages', actingUserId: 'admin');
	}

	/**
	 * A sessionless call acting for a user outside the organisation is denied before any secret read.
	 */
	public function testSessionlessActingNonMemberIsDenied(): void {
		$orgService = $this->createMock(OrganisationService::class);
		$orgService->method('isMemberOfOrganisation')->with(self::ORG, 'mallory')->willReturn(false);

		$broker = $this->makeBroker(
			sessionUid: null,
			client: $this->neverCalledClient(),
			store: $this->neverReadStore(),
			orgService: $orgService,
			users: ['mallory' => true]
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: self::UUID, appId: 'hermiq', method: 'POST', path: '/v1/messages', actingUserId: 'mallory');
	}

	/**
	 * An asserted user that does not exist is denied, and membership is never even asked.
	 */
	public function testSessionlessActingUnknownUserIsDenied(): void {
		$orgService = $this->createMock(OrganisationService::class);
		$orgService->expects($this->never())->method('isMemberOfOrganisation');

		$broker = $this->makeBroker(
			sessionUid: null,
			client: $this->neverCalledClient(),
			store: $this->neverReadStore(),
			orgService: $orgService,
			users: []
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: self::UUID, appId: 'hermiq', method: 'POST', path: '/v1/messages', actingUserId: 'ghost');
	}

	/**
	 * A disabled member is denied: a disabled account cannot spend the organisation's key.
	 */
	public function testSessionlessActingDisabledMemberIsDenied(): void {
		$orgService = $this->createMock(OrganisationService::class);
		$orgService->expects($this->never())->method('isMemberOfOrganisation');

		$broker = $this->makeBroker(
			sessionUid: null,
			client: $this->neverCalledClient(),
			store: $this->neverReadStore(),
			orgService: $orgService,
			users: ['bob' => false]
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: self::UUID, appId: 'hermiq', method: 'POST', path: '/v1/messages', actingUserId: 'bob');
	}

	/**
	 * With no user manager wired the new path fails closed rather than trusting the bare id.
	 */
	public function testSessionlessActingMemberWithoutAUserManagerIsDenied(): void {
		$orgService = $this->createMock(OrganisationService::class);
		$orgService->method('isMemberOfOrganisation')->willReturn(true);

		$broker = $this->makeBroker(
			sessionUid: null,
			client: $this->neverCalledClient(),
			store: $this->neverReadStore(),
			orgService: $orgService,
			users: null
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: self::UUID, appId: 'hermiq', method: 'POST', path: '/v1/messages', actingUserId: 'bob');
	}

	/**
	 * The member path does not bypass the app allow-list: an app not in allowedApps is still denied.
	 */
	public function testSessionlessActingMemberForAnAppNotAllowedIsDenied(): void {
		$orgService = $this->createMock(OrganisationService::class);
		$orgService->method('isMemberOfOrganisation')->willReturn(true);

		$broker = $this->makeBroker(
			sessionUid: null,
			client: $this->neverCalledClient(),
			store: $this->neverReadStore(),
			orgService: $orgService,
			users: ['bob' => true]
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: self::UUID, appId: 'someotherapp', method: 'POST', path: '/v1/messages', actingUserId: 'bob');
	}

	/**
	 * The member path does not bypass the allow-rules: a path outside them is still denied.
	 */
	public function testSessionlessActingMemberOutsideTheAllowRulesIsDenied(): void {
		$orgService = $this->createMock(OrganisationService::class);
		$orgService->method('isMemberOfOrganisation')->willReturn(true);

		$broker = $this->makeBroker(
			sessionUid: null,
			client: $this->neverCalledClient(),
			store: $this->neverReadStore(),
			orgService: $orgService,
			users: ['bob' => true]
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: self::UUID, appId: 'hermiq', method: 'DELETE', path: '/v1/files/x', actingUserId: 'bob');
	}

	/**
	 * With a session, an asserted member never rescues a session user outside the organisation.
	 */
	public function testSessionNonMemberIsNotRescuedByAnAssertedMember(): void {
		$orgService = $this->createMock(OrganisationService::class);
		$orgService->method('hasAccessToOrganisation')->with(self::ORG)->willReturn(false);
		$orgService->expects($this->never())->method('isMemberOfOrganisation');

		$broker = $this->makeBroker(
			sessionUid: 'mallory',
			client: $this->neverCalledClient(),
			store: $this->neverReadStore(),
			orgService: $orgService,
			users: ['bob' => true]
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: self::UUID, appId: 'hermiq', method: 'POST', path: '/v1/messages', actingUserId: 'bob');
	}

	/**
	 * A PERSONAL credential owned by alice is refused for a background task acting for bob,
	 * even when bob is a member of every organisation: membership never opens a personal key.
	 */
	public function testPersonalCredentialForAnotherActingUserIsDenied(): void {
		$orgService = $this->createMock(OrganisationService::class);
		$orgService->method('isMemberOfOrganisation')->willReturn(true);
		$orgService->method('hasAccessToOrganisation')->willReturn(true);

		$broker = $this->makeBroker(
			sessionUid: null,
			client: $this->neverCalledClient(),
			store: $this->neverReadStore(),
			orgService: $orgService,
			users: ['bob' => true],
			personal: true
		);

		$this->expectException(CredentialAccessDeniedException::class);
		$broker->request(credentialId: self::UUID, appId: 'hermiq', method: 'POST', path: '/v1/messages', actingUserId: 'bob');
	}

	/**
	 * An unauthenticated HTTP call to either broker endpoint is refused before the broker runs,
	 * whatever acting-user field it carries.
	 */
	public function testUnauthenticatedHttpCallNeverReachesTheBroker(): void {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->expects($this->never())->method('request');

		$controller = $this->makeController(broker: $broker, sessionUid: null, params: ['actingUserId' => 'bob']);

		$this->assertSame(401, $controller->brokerRequest(self::UUID)->getStatus());
		$this->assertSame(401, $controller->sessionBrokerRequest(self::UUID)->getStatus());
	}

	/**
	 * The session endpoint, like the token endpoint, never forwards an acting user from the body.
	 */
	public function testSessionHttpEndpointNeverForwardsAnActingUser(): void {
		$captured = 'not-called';
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->expects($this->once())->method('request')->willReturnCallback(
			function (
				string $credentialId,
				string $appId,
				string $method,
				string $path,
				array $headers = [],
				?string $body = null,
				?string $actingUserId = null,
			) use (&$captured): array {
				$captured = $actingUserId;
				return ['status' => 200, 'headers' => [], 'body' => '{}'];
			}
		);

		$controller = $this->makeController(
			broker: $broker,
			sessionUid: 'alice',
			params: ['appId' => 'hermiq', 'actingUserId' => 'bob', 'actingOrganisationId' => self::ORG]
		);

		$this->assertSame(200, $controller->sessionBrokerRequest(self::UUID)->getStatus());
		$this->assertNull($captured, 'The session endpoint must never forward an acting user');
	}

	/**
	 * A client that must never be called.
	 *
	 * @return IClient
	 */
	private function neverCalledClient(): IClient {
		$client = $this->createMock(IClient::class);
		$client->expects($this->never())->method('request');
		return $client;
	}

	/**
	 * A store that must never be read.
	 *
	 * @return CredentialStore
	 */
	private function neverReadStore(): CredentialStore {
		$store = $this->createMock(CredentialStore::class);
		$store->expects($this->never())->method('get');
		return $store;
	}

	/**
	 * Build a broker over an `anthropic` credential allowed for hermiq.
	 *
	 * @param string|null              $sessionUid Session user id, or null for sessionless.
	 * @param IClient                  $client     The (mock) HTTP client.
	 * @param CredentialStore          $store      The (mock) secret store.
	 * @param OrganisationService      $orgService The (mock) organisation service.
	 * @param array<string, bool>|null $users      uid => enabled, resolvable by the user manager; null wires none.
	 * @param bool                     $personal   True for a personal credential owned by alice.
	 *
	 * @return CredentialBrokerService
	 */
	private function makeBroker(
		?string $sessionUid,
		IClient $client,
		CredentialStore $store,
		OrganisationService $orgService,
		?array $users,
		bool $personal = false,
	): CredentialBrokerService {
		$data = [
			'name' => 'Claude for the municipality',
			'provider' => 'anthropic',
			'owner' => 'alice',
			'allowedApps' => ['hermiq'],
		];
		if ($personal === false) {
			$data['scope'] = 'organisation';
			$data['organisation'] = self::ORG;
		}

		$credential = new ObjectEntity();
		$credential->setUuid(self::UUID);
		$credential->setOwner('alice');
		$credential->setObject($data);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($credential);

		$sessionUser = null;
		if ($sessionUid !== null) {
			$sessionUser = $this->createMock(IUser::class);
			$sessionUser->method('getUID')->willReturn($sessionUid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($sessionUser);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('openregister')->willReturn(dirname(__DIR__, 4));
		$catalogue = new ProviderCatalogue($appManager, $this->createMock(LoggerInterface::class));

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$userManager = null;
		if ($users !== null) {
			$userManager = $this->createMock(IUserManager::class);
			$userManager->method('get')->willReturnCallback(
				function (string $uid) use ($users): ?IUser {
					if (array_key_exists($uid, $users) === false) {
						return null;
					}

					$user = $this->createMock(IUser::class);
					$user->method('getUID')->willReturn($uid);
					$user->method('isEnabled')->willReturn($users[$uid]);
					return $user;
				}
			);
		}

		return new CredentialBrokerService(
			$objectService,
			$store,
			$catalogue,
			$session,
			$clientService,
			$this->createMock(LoggerInterface::class),
			$orgService,
			$this->createMock(IGroupManager::class),
			$userManager
		);
	}

	/**
	 * Build the credential controller around a (mock) broker.
	 *
	 * @param CredentialBrokerService $broker     The broker.
	 * @param string|null             $sessionUid The session user, or null for none.
	 * @param array<string, mixed>    $params     Extra request params.
	 *
	 * @return CredentialController
	 */
	private function makeController(CredentialBrokerService $broker, ?string $sessionUid, array $params): CredentialController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('token-placeholder');
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				$all = array_merge(['method' => 'POST', 'path' => '/v1/messages', 'headers' => [], 'body' => null], $params);
				return ($all[$key] ?? $default);
			}
		);

		$tokenService = $this->createMock(CredentialAppTokenService::class);
		$tokenService->method('verify')->willReturn(['appId' => 'hermiq', 'credentialId' => self::UUID]);

		$user = null;
		if ($sessionUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new CredentialController(
			'openregister',
			$request,
			$userSession,
			$this->createMock(IGroupManager::class),
			$this->createMock(ObjectService::class),
			$this->createMock(CredentialStore::class),
			$this->createMock(ProviderCatalogue::class),
			$broker,
			$tokenService,
			$this->createMock(OrganisationService::class),
			new SharePrincipalDeriver(),
			$this->createMock(LoggerInterface::class)
		);
	}
}//end class
