<?php

/**
 * CredentialShareApiTest — the owner-only share management surface.
 *
 * Pins: only the owner may read or replace a share list; a recipient cannot
 * widen it or re-share onward; the derived principal lists are recomputed
 * server-side and a client-supplied value is discarded; malformed entries are
 * REJECTED rather than silently dropped, so an owner who mistypes a principal is
 * told instead of believing they granted access they did not; and no response
 * carries secret material.
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
 *
 * @spec openspec/changes/shared-credentials-and-flows/specs/credential-sharing/spec.md#requirement-a-brokered-credential-carries-a-principal-share-list
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
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CredentialShareApiTest extends TestCase {

	private const UUID = '22222222-2222-2222-2222-222222222222';

	private const OWNER = 'owner-user';

	private const RECIPIENT = 'shared-user';

	private const SECRET = 'YOUR_API_KEY_HERE';

	/**
	 * The last object handed to saveObject, so a test can assert what was stored.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $savedObject = null;

	// ── reading the share list ──

	public function testOwnerCanReadTheShareList(): void {
		$controller = $this->makeController(
			sessionUid: self::OWNER,
			stored: ['sharedWith' => [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]]
		);

		$response = $controller->shares(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData()['sharedWith']);
	}

	public function testNonOwnerCannotReadTheShareList(): void {
		$controller = $this->makeController(sessionUid: 'stranger', stored: []);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->shares(self::UUID)->getStatus());
	}

	/**
	 * A recipient is not an owner: being shared with does not confer management.
	 */
	public function testRecipientCannotReadTheShareList(): void {
		$controller = $this->makeController(
			sessionUid: self::RECIPIENT,
			stored: ['sharedWith' => [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]]
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->shares(self::UUID)->getStatus());
	}

	// ── replacing the share list ──

	public function testOwnerCanReplaceTheShareList(): void {
		$controller = $this->makeController(
			sessionUid: self::OWNER,
			stored: [],
			params: [
				'sharedWith' => [
					['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use'],
					['type' => 'group', 'id' => 'finance', 'permission' => 'use'],
				],
			]
		);

		$response = $controller->updateShares(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([self::RECIPIENT], $response->getData()['sharedUsers']);
		$this->assertSame(['finance'], $response->getData()['sharedGroups']);
	}

	/**
	 * The derived lists are recomputed from `sharedWith`. A client-supplied value
	 * must be discarded — otherwise a caller could grant itself access without
	 * appearing in the share list at all.
	 */
	public function testClientSuppliedDerivedListsAreDiscarded(): void {
		$controller = $this->makeController(
			sessionUid: self::OWNER,
			stored: [],
			params: [
				'sharedWith' => [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']],
				'sharedUsers' => ['mallory'],
				'sharedGroups' => ['admin'],
			]
		);

		$controller->updateShares(self::UUID);

		$this->assertSame([self::RECIPIENT], $this->savedObject['sharedUsers']);
		$this->assertSame([], $this->savedObject['sharedGroups']);
	}

	public function testRevocationIsAReplaceWithoutThePrincipal(): void {
		$controller = $this->makeController(
			sessionUid: self::OWNER,
			stored: ['sharedWith' => [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]],
			params: ['sharedWith' => []]
		);

		$response = $controller->updateShares(self::UUID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['sharedUsers']);
		$this->assertSame([], $this->savedObject['sharedUsers']);
	}

	public function testNonOwnerCannotReplaceTheShareList(): void {
		$controller = $this->makeController(
			sessionUid: 'stranger',
			stored: [],
			params: ['sharedWith' => [['type' => 'user', 'id' => 'stranger', 'permission' => 'use']]]
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->updateShares(self::UUID)->getStatus());
		$this->assertNull($this->savedObject, 'nothing may be written on a refused request');
	}

	/**
	 * A recipient must not be able to widen the list or re-share onward.
	 */
	public function testRecipientCannotWidenTheShareList(): void {
		$controller = $this->makeController(
			sessionUid: self::RECIPIENT,
			stored: ['sharedWith' => [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]],
			params: [
				'sharedWith' => [
					['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use'],
					['type' => 'user', 'id' => 'accomplice', 'permission' => 'use'],
				],
			]
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->updateShares(self::UUID)->getStatus());
		$this->assertNull($this->savedObject);
	}

	/**
	 * REJECT a malformed entry rather than dropping it: silently storing a
	 * shorter list would leave the owner believing they granted access they did
	 * not.
	 */
	public function testMalformedEntryIsRejectedNotSilentlyDropped(): void {
		$controller = $this->makeController(
			sessionUid: self::OWNER,
			stored: [],
			params: [
				'sharedWith' => [
					['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use'],
					['type' => 'everyone', 'id' => 'x'],
				],
			]
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->updateShares(self::UUID)->getStatus());
		$this->assertNull($this->savedObject);
	}

	public function testNonArrayShareListIsRejected(): void {
		$controller = $this->makeController(
			sessionUid: self::OWNER,
			stored: [],
			params: ['sharedWith' => 'alice']
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->updateShares(self::UUID)->getStatus());
	}

	public function testMissingShareListIsRejected(): void {
		$controller = $this->makeController(sessionUid: self::OWNER, stored: [], params: []);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->updateShares(self::UUID)->getStatus());
	}

	/**
	 * No share response may carry secret material (ADR-004 Rule 1).
	 */
	public function testShareResponsesAreSecretFree(): void {
		$controller = $this->makeController(
			sessionUid: self::OWNER,
			stored: ['sharedWith' => [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]]
		);

		$body = json_encode($controller->shares(self::UUID)->getData());

		$this->assertStringNotContainsString(self::SECRET, $body);
	}

	// ── shared with me ──

	public function testRecipientSeesACredentialSharedWithThem(): void {
		$controller = $this->makeController(
			sessionUid: self::RECIPIENT,
			stored: ['sharedWith' => [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]]
		);

		$response = $controller->sharedWithMe();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}

	public function testRecipientSeesACredentialSharedWithTheirGroup(): void {
		$controller = $this->makeController(
			sessionUid: self::RECIPIENT,
			stored: ['sharedWith' => [['type' => 'group', 'id' => 'finance', 'permission' => 'use']]],
			userGroups: ['finance']
		);

		$this->assertSame(1, $controller->sharedWithMe()->getData()['total']);
	}

	public function testUnsharedUserSeesNothing(): void {
		$controller = $this->makeController(
			sessionUid: 'stranger',
			stored: ['sharedWith' => [['type' => 'user', 'id' => self::RECIPIENT, 'permission' => 'use']]]
		);

		$this->assertSame(0, $controller->sharedWithMe()->getData()['total']);
	}

	/**
	 * The owner's own credentials belong to the ordinary index; this endpoint is
	 * about what OTHERS shared with the caller.
	 */
	public function testOwnerDoesNotSeeTheirOwnCredentialHere(): void {
		$controller = $this->makeController(
			sessionUid: self::OWNER,
			stored: ['sharedWith' => [['type' => 'user', 'id' => self::OWNER, 'permission' => 'use']]]
		);

		$this->assertSame(0, $controller->sharedWithMe()->getData()['total']);
	}

	public function testAnonymousIsUnauthorized(): void {
		$controller = $this->makeController(sessionUid: null, stored: []);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->sharedWithMe()->getStatus());
	}

	/**
	 * Build a controller over one stored credential.
	 *
	 * @param string|null $sessionUid The logged-in uid, or null.
	 * @param array<string, mixed> $stored Extra properties on the stored credential.
	 * @param array<string, mixed> $params Request parameters.
	 * @param string[] $userGroups Groups the session user belongs to.
	 *
	 * @return CredentialController
	 */
	private function makeController(
		?string $sessionUid,
		array $stored,
		array $params = [],
		array $userGroups = [],
	): CredentialController {
		$this->savedObject = null;

		$data = array_merge(
			['name' => 'shared credential', 'provider' => 'github', 'allowedApps' => ['openregister']],
			$stored
		);

		$credential = new ObjectEntity();
		$credential->setUuid(self::UUID);
		$credential->setOwner(self::OWNER);
		$credential->setObject($data);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($credential);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([$credential]);
		$objectService->method('saveObject')->willReturnCallback(
			function (...$args) use ($credential): ObjectEntity {
				// Named or positional: the object body is the first argument.
				$object = ($args[0] ?? []);
				if (is_array($object) === true) {
					$this->savedObject = $object;
					$saved = new ObjectEntity();
					$saved->setUuid(self::UUID);
					$saved->setOwner(self::OWNER);
					$saved->setObject($object);
					return $saved;
				}

				return $credential;
			}
		);

		$user = null;
		if ($sessionUid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($sessionUid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($params) {
				return array_key_exists($key, $params) === true ? $params[$key] : $default;
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($userGroups);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('openregister')->willReturn(dirname(__DIR__, 3));

		return new CredentialController(
			'openregister',
			$request,
			$session,
			$groupManager,
			$objectService,
			$this->createMock(CredentialStore::class),
			new ProviderCatalogue($appManager, $this->createMock(LoggerInterface::class)),
			$this->createMock(CredentialBrokerService::class),
			$this->createMock(CredentialAppTokenService::class),
			$this->createMock(OrganisationService::class),
			new SharePrincipalDeriver()
		);
	}
}
