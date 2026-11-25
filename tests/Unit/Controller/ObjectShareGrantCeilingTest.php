<?php

/**
 * An object share, link or invitation never gives more than the sharer holds (REQ-RAH-002).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-nobody-grants-a-right-they-do-not-hold-req-rah-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\ObjectSharingController;
use OCA\OpenRegister\Controller\Support\ObjectSharingControllerServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\GrantExceedsGranterException;
use OCA\OpenRegister\Service\Authorization\GrantCeiling;
use OCA\OpenRegister\Service\Authorization\GrantedRight;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\MapperBackedSchemaCatalog;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectSharingController
 * @covers \OCA\OpenRegister\Service\Authorization\GrantCeiling
 * @covers \OCA\OpenRegister\Service\Authorization\GrantedRight
 * @uses   \OCA\OpenRegister\Exception\GrantExceedsGranterException
 */
final class ObjectShareGrantCeilingTest extends TestCase {

	/**
	 * The actions the sharer holds on the object.
	 *
	 * @var list<string>
	 */
	private array $held = ['read'];

	/**
	 * Whether the sharer is an administrator.
	 *
	 * @var boolean
	 */
	private bool $admin = false;

	/**
	 * Whether anybody is signed in.
	 *
	 * @var boolean
	 */
	private bool $signedIn = true;

	/**
	 * Whether the object's schema can be read.
	 *
	 * @var boolean
	 */
	private bool $schemaReadable = true;

	/**
	 * The questions the ceiling asked.
	 *
	 * @var list<PermissionAsk>
	 */
	private array $asked = [];

	/**
	 * The request parameters.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * The sharing writes, which a refused grant must never reach.
	 *
	 * @var ObjectSharingService&MockObject
	 */
	private ObjectSharingService&MockObject $sharing;

	protected function setUp(): void {
		$this->sharing = $this->createMock(ObjectSharingService::class);
	}//end setUp()

	public function testAShareBeyondTheSharersRightsIsRefused(): void {
		$this->params = ['type' => 'user', 'shareWith' => 'piet', 'permissions' => 3];
		$this->sharing->expects($this->never())->method('grantWithTerms');

		$response = $this->controller()->createShare('zaken', 'zaak', 'uuid-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(
			['error' => GrantExceedsGranterException::CODE, 'excess' => [['register' => '5', 'schema' => 'zaak', 'action' => 'update']]],
			$response->getData()
		);
	}//end testAShareBeyondTheSharersRightsIsRefused()

	public function testAShareWithinTheSharersRightsIsCreated(): void {
		$this->params = ['type' => 'user', 'shareWith' => 'piet', 'permissions' => 1];
		$this->sharing->expects($this->once())->method('grantWithTerms')->willReturn(['id' => 'share-1']);

		$response = $this->controller()->createShare('zaken', 'zaak', 'uuid-1');

		$this->assertSame(201, $response->getStatus());
	}//end testAShareWithinTheSharersRightsIsCreated()

	public function testTheCeilingAsksAboutTheObjectAndItsOwner(): void {
		$this->params = ['type' => 'user', 'shareWith' => 'piet', 'permissions' => 1];
		$this->sharing->method('grantWithTerms')->willReturn(['id' => 'share-1']);

		$this->controller()->createShare('zaken', 'zaak', 'uuid-1');

		$this->assertCount(1, $this->asked);
		$this->assertSame('uuid-1', $this->asked[0]->object?->getUuid(), 'Asked about the shared object, so owner rights count.');
		$this->assertSame('ann', $this->asked[0]->objectOwner);
		$this->assertSame('ann', $this->asked[0]->userId);
		$this->assertSame('read', $this->asked[0]->action);
	}//end testTheCeilingAsksAboutTheObjectAndItsOwner()

	public function testAnAdministratorMayShareAnything(): void {
		$this->admin = true;
		$this->held = [];
		$this->params = ['type' => 'user', 'shareWith' => 'piet', 'permissions' => 15];
		$this->sharing->expects($this->once())->method('grantWithTerms')->willReturn(['id' => 'share-1']);

		$this->assertSame(201, $this->controller()->createShare('zaken', 'zaak', 'uuid-1')->getStatus());
	}//end testAnAdministratorMayShareAnything()

	public function testALinkBeyondTheSharersRightsIsRefused(): void {
		$this->params = ['permissions' => 2];
		$this->sharing->expects($this->never())->method('createLink');

		$this->assertSame(403, $this->controller()->createLink('zaken', 'zaak', 'uuid-1')->getStatus());
	}//end testALinkBeyondTheSharersRightsIsRefused()

	public function testAnInvitationBeyondTheSharersRightsIsRefused(): void {
		$this->params = ['email' => 'piet@example.org', 'permissions' => 3];
		$this->sharing->expects($this->never())->method('inviteByEmail');

		$this->assertSame(403, $this->controller()->inviteByEmail('zaken', 'zaak', 'uuid-1')->getStatus());
	}//end testAnInvitationBeyondTheSharersRightsIsRefused()

	public function testALinkWithinTheSharersRightsIsCreated(): void {
		$this->sharing->expects($this->once())->method('createLink')->willReturn(['token' => 't']);

		$this->assertSame(201, $this->controller()->createLink('zaken', 'zaak', 'uuid-1')->getStatus());
	}//end testALinkWithinTheSharersRightsIsCreated()

	public function testNobodySignedInIsRefused(): void {
		$this->signedIn = false;
		$this->params = ['type' => 'user', 'shareWith' => 'piet', 'permissions' => 1];
		$this->sharing->expects($this->never())->method('grantWithTerms');

		$this->assertSame(403, $this->controller()->createShare('zaken', 'zaak', 'uuid-1')->getStatus());
	}//end testNobodySignedInIsRefused()

	public function testAnUnreadableSchemaRefusesRatherThanGuessing(): void {
		$this->schemaReadable = false;
		$this->params = ['type' => 'user', 'shareWith' => 'piet', 'permissions' => 1];
		$this->sharing->expects($this->never())->method('grantWithTerms');

		$response = $this->controller()->createShare('zaken', 'zaak', 'uuid-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'The grant could not be checked against your own rights'], $response->getData());
	}//end testAnUnreadableSchemaRefusesRatherThanGuessing()

	public function testWithoutTheCeilingWiredTheOwnerCheckDecides(): void {
		$this->params = ['type' => 'user', 'shareWith' => 'piet', 'permissions' => 3];
		$this->sharing->expects($this->once())->method('grantWithTerms')->willReturn(['id' => 'share-1']);

		$this->assertSame(201, $this->controller(wired: false)->createShare('zaken', 'zaak', 'uuid-1')->getStatus());
	}//end testWithoutTheCeilingWiredTheOwnerCheckDecides()

	public function testAGrantedRightNamesItsObjectlessFormAsBefore(): void {
		$schema = new Schema();
		$schema->setSlug('zaak');

		$this->assertNull((new GrantedRight(schema: $schema, action: 'read'))->object);
		$this->assertSame(['register' => null, 'schema' => 'zaak', 'action' => 'read'], (new GrantedRight(schema: $schema, action: 'read'))->named());
	}//end testAGrantedRightNamesItsObjectlessFormAsBefore()

	private function controller(bool $wired = true): ObjectSharingController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, mixed $default = null): mixed => ($this->params[$key] ?? $default));

		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setOwner('ann');
		$object->setSchema('7');
		$object->setRegister('5');
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('setObject')->willReturnSelf();
		$objectService->method('getObject')->willReturn($object);

		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('zaak');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			function () use ($schema): Schema {
				if ($this->schemaReadable === false) {
					throw new RuntimeException('database gone');
				}

				return $schema;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ann');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function () use ($user): ?IUser {
				if ($this->signedIn === false) {
					return null;
				}

				return $user;
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (): bool => $this->admin);
		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (PermissionAsk $ask): bool {
				$this->asked[] = $ask;
				return in_array($ask->action, $this->held, true);
			}
		);

		$ceiling = null;
		if ($wired === true) {
			$ceiling = new GrantCeiling(groups: $groups, permissions: $permissions);
		}

		return new ObjectSharingController(
			$request,
			new ObjectSharingControllerServices(
				objectService: $objectService,
				catalog: new MapperBackedSchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $schemas),
				sharing: $this->sharing,
				logger: new NullLogger(),
				ceiling: $ceiling,
				userSession: $session,
			)
		);
	}//end controller()
}//end class
