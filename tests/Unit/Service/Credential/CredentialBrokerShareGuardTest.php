<?php

/**
 * CredentialBrokerShareGuardTest — Guard 1c, the share-principal admit branch.
 *
 * Pins the whole contract of sharing a brokered credential:
 *
 *  - a named user, and a member of a named group, are admitted;
 *  - nobody else is, and the branch fails closed on every malformed input;
 *  - a share NEVER crosses the tenant edge — a principal outside the
 *    credential's organisation is denied even when named;
 *  - every PRE-EXISTING verdict is unchanged, which is the property that makes
 *    adding a branch to an ADR-004 Rule 4 guard chain safe;
 *  - the later guards still run, so a share is not a bypass;
 *  - and the secret is never returned to a recipient — a share grants USE, not
 *    sight (ADR-004 Rule 1).
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
 * @spec openspec/changes/shared-credentials-and-flows/specs/credential-broker/spec.md#requirement-share-principal-broker-guard
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
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CredentialBrokerShareGuardTest extends TestCase {

	private const UUID = '11111111-1111-1111-1111-111111111111';

	private const SECRET = 'YOUR_API_KEY_HERE';

	private const OWNER = 'owner-user';

	private const RECIPIENT = 'shared-user';

	private const ORG = 'org-uuid-1';

	// ── admits ──

	public function testNamedUserIsAdmitted(): void {
		$response = $this->brokerCall(
			sessionUid: self::RECIPIENT,
			sharedWith: [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]
		);

		$this->assertSame(200, $response['status']);
	}

	/**
	 * An entry that omits `permission` counts as the schema default (`use`).
	 * The schema default is not applied to stored data, so the guard has to.
	 */
	public function testEntryWithoutPermissionIsTreatedAsUse(): void {
		$response = $this->brokerCall(
			sessionUid: self::RECIPIENT,
			sharedWith: [['type' => 'user', 'id' => self::RECIPIENT]]
		);

		$this->assertSame(200, $response['status']);
	}

	public function testMemberOfNamedGroupIsAdmitted(): void {
		$response = $this->brokerCall(
			sessionUid: self::RECIPIENT,
			sharedWith: [['type' => 'group', 'id' => 'finance', 'permission' => 'use']],
			userGroups: ['finance']
		);

		$this->assertSame(200, $response['status']);
	}

	// ── denies ──

	public function testUnsharedUserIsDenied(): void {
		$this->expectException(CredentialAccessDeniedException::class);

		$this->brokerCall(
			sessionUid: 'stranger',
			sharedWith: [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]
		);
	}

	public function testNonMemberOfNamedGroupIsDenied(): void {
		$this->expectException(CredentialAccessDeniedException::class);

		$this->brokerCall(
			sessionUid: 'stranger',
			sharedWith: [['type' => 'group', 'id' => 'finance', 'permission' => 'use']],
			userGroups: ['legal']
		);
	}

	public function testMalformedEntriesAdmitNobody(): void {
		$this->expectException(CredentialAccessDeniedException::class);

		$this->brokerCall(
			sessionUid: self::RECIPIENT,
			sharedWith: [
				['type' => 'everyone', 'id' => self::RECIPIENT],
				['type' => 'user', 'id' => ''],
				['type' => 'user'],
				'not-an-entry',
			]
		);
	}

	public function testAnonymousCallerIsDenied(): void {
		$this->expectException(CredentialAccessDeniedException::class);

		$this->brokerCall(
			sessionUid: null,
			sharedWith: [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]
		);
	}

	/**
	 * A group share admits nobody when no group manager is wired, rather than
	 * everybody. The optional collaborator must fail CLOSED.
	 */
	public function testGroupShareAdmitsNobodyWithoutAGroupManager(): void {
		$this->expectException(CredentialAccessDeniedException::class);

		$this->brokerCall(
			sessionUid: self::RECIPIENT,
			sharedWith: [['type' => 'group', 'id' => 'finance', 'permission' => 'use']],
			userGroups: ['finance'],
			wireGroupManager: false
		);
	}

	// ── the tenant edge ──

	public function testShareCannotCrossATenantBoundary(): void {
		$this->expectException(CredentialAccessDeniedException::class);

		$this->brokerCall(
			sessionUid: self::RECIPIENT,
			sharedWith: [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']],
			organisation: self::ORG,
			hasOrgAccess: false
		);
	}

	public function testShareIsAdmittedInsideTheTenant(): void {
		$response = $this->brokerCall(
			sessionUid: self::RECIPIENT,
			sharedWith: [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']],
			organisation: self::ORG,
			hasOrgAccess: true
		);

		$this->assertSame(200, $response['status']);
	}

	// ── pre-existing verdicts unchanged ──

	public function testOwnerIsStillAdmittedWithNoShareList(): void {
		$response = $this->brokerCall(sessionUid: self::OWNER, sharedWith: null);

		$this->assertSame(200, $response['status']);
	}

	public function testNonOwnerIsStillDeniedWithNoShareList(): void {
		$this->expectException(CredentialAccessDeniedException::class);

		$this->brokerCall(sessionUid: 'stranger', sharedWith: null);
	}

	public function testEmptyShareListChangesNothing(): void {
		$this->expectException(CredentialAccessDeniedException::class);

		$this->brokerCall(sessionUid: 'stranger', sharedWith: []);
	}

	// ── a share is not a bypass ──

	public function testShareRecipientIsStillSubjectToAllowedApps(): void {
		$this->expectException(CredentialAccessDeniedException::class);

		$this->brokerCall(
			sessionUid: self::RECIPIENT,
			sharedWith: [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']],
			allowedApps: ['some-other-app']
		);
	}

	/**
	 * A share grants USE, never sight: the recipient gets the upstream response
	 * and the secret is injected server-side (ADR-004 Rule 1).
	 */
	public function testShareRecipientNeverReceivesTheSecret(): void {
		$response = $this->brokerCall(
			sessionUid: self::RECIPIENT,
			sharedWith: [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]
		);

		$this->assertStringNotContainsString(self::SECRET, json_encode($response));
	}

	/**
	 * Drive one brokered call and return its result.
	 *
	 * @param string|null $sessionUid Logged-in uid, or null for anonymous.
	 * @param array<int,mixed>|null $sharedWith The credential's share list.
	 * @param string[] $userGroups Groups the session user belongs to.
	 * @param string $organisation The credential's organisation (empty = none).
	 * @param bool $hasOrgAccess What OrganisationService reports.
	 * @param string[] $allowedApps The credential's allowedApps.
	 * @param bool $wireGroupManager Whether a group manager is injected.
	 *
	 * @return array<string, mixed> The broker result.
	 */
	private function brokerCall(
		?string $sessionUid,
		?array $sharedWith,
		array $userGroups = [],
		string $organisation = '',
		bool $hasOrgAccess = true,
		array $allowedApps = ['openregister'],
		bool $wireGroupManager = true,
	): array {
		$data = [
			'name' => 'shared credential',
			'provider' => 'github',
			'allowedApps' => $allowedApps,
		];

		if ($sharedWith !== null) {
			$data['sharedWith'] = $sharedWith;
		}

		if ($organisation !== '') {
			$data['organisation'] = $organisation;
		}

		$credential = new ObjectEntity();
		$credential->setUuid(self::UUID);
		$credential->setOwner(self::OWNER);
		$credential->setObject($data);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($credential);

		$store = $this->createMock(CredentialStore::class);
		$store->method('get')->willReturn(self::SECRET);

		$user = null;
		if ($sessionUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = null;
		if ($wireGroupManager === true) {
			$groupManager = $this->createMock(IGroupManager::class);
			$groupManager->method('getUserGroupIds')->willReturn($userGroups);
		}

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$organisationService = $this->createMock(OrganisationService::class);
		$organisationService->method('hasAccessToOrganisation')->willReturn($hasOrgAccess);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('openregister')->willReturn(dirname(__DIR__, 4));
		$catalogue = new ProviderCatalogue($appManager, $this->createMock(LoggerInterface::class));

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$response->method('getBody')->willReturn('{"ok":true}');
		$response->method('getHeaders')->willReturn([]);

		$client = $this->createMock(IClient::class);
		$client->method('request')->willReturn($response);

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$broker = new CredentialBrokerService(
			$objectService,
			$store,
			$catalogue,
			$userSession,
			$clientService,
			$this->createMock(LoggerInterface::class),
			$organisationService,
			$groupManager,
			$userManager
		);

		return $broker->request(
			credentialId: self::UUID,
			appId: 'openregister',
			method: 'GET',
			path: '/user/repos',
			headers: [],
			body: null
		);
	}
}
