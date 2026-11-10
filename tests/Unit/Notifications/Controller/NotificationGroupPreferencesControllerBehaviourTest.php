<?php

/**
 * Behaviour tests for NotificationGroupPreferencesController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed fixture properties are self-describing.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCA\OpenRegister\Notifications\Controller\NotificationGroupPreferencesController;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationGroupPreferencesControllerServices;
use OCP\Group\ISubAdmin;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(NotificationGroupPreferencesController::class)]
#[UsesClass(\OCA\OpenRegister\Db\Schema\ConfigurationEntryRules::class)]
#[UsesClass(\OCA\OpenRegister\Db\Schema\ConfigurationValidator::class)]
#[UsesClass(Schema::class)]
#[UsesClass(NotificationGroupPreferencesControllerServices::class)]
class NotificationGroupPreferencesControllerBehaviourTest extends TestCase {

	private NotificationPreferencesInterface&MockObject $prefs;

	private SchemaMapperInterface&MockObject $schemas;

	private IGroupManager&MockObject $groups;

	private ISubAdmin&MockObject $subAdmin;

	private IGroup&MockObject $group;

	private ?IUser $user = null;

	protected function setUp(): void {
		$this->prefs = $this->createMock(NotificationPreferencesInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->subAdmin = $this->createMock(ISubAdmin::class);
		$this->group = $this->createMock(IGroup::class);
		$this->groups->method('get')->willReturnCallback(fn (string $gid): ?IGroup => $gid === 'staff' ? $this->group : null);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->user = $user;
	}

	private function controller(array $params, ?IUserSession $session = null): NotificationGroupPreferencesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		if ($session === null) {
			$session = $this->createMock(IUserSession::class);
			$session->method('getUser')->willReturn($this->user);
		}

		return new NotificationGroupPreferencesController(
			$request,
			new NotificationGroupPreferencesControllerServices($this->prefs, $this->schemas, $session),
			$this->groups,
			$this->subAdmin
		);
	}

	private function schema(?int $id, ?string $slug, ?string $title, mixed $notifications): Schema {
		$schema = new Schema();
		if ($id !== null) {
			$schema->setId($id);
		}

		if ($slug !== null) {
			$schema->setSlug($slug);
		}

		if ($title !== null) {
			$schema->setTitle($title);
		}

		$schema->setConfiguration(['x-openregister-notifications' => $notifications]);
		return $schema;
	}

	public function testIndexAnonymousIsRefusedWith401(): void {
		$this->user = null;
		$response = $this->controller(['group' => 'staff'])->index();
		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Authentication required'], $response->getData());
	}

	public function testIndexWithoutGroupIs422(): void {
		$response = $this->controller(['group' => ''])->index();
		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'A "group" is required'], $response->getData());
	}

	public function testIndexWithUnknownGroupIs404(): void {
		$response = $this->controller(['group' => 'ghost'])->index();
		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Unknown group'], $response->getData());
	}

	public function testIndexByOutsiderIs403(): void {
		$this->group->method('inGroup')->willReturn(false);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->subAdmin->method('isSubAdminOfGroup')->willReturn(false);
		$this->prefs->expects($this->never())->method('getGroupDefault');
		$response = $this->controller(['group' => 'staff'])->index();
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Not a member of that group'], $response->getData());
	}

	public function testIndexFailsClosedWhenSubAdminLookupThrows(): void {
		$this->group->method('inGroup')->willReturn(false);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->subAdmin->method('isSubAdminOfGroup')->willThrowException(new RuntimeException('db down'));
		$response = $this->controller(['group' => 'staff'])->index();
		$this->assertSame(403, $response->getStatus());
	}

	public function testIndexBySubAdminIsAllowed(): void {
		$this->group->method('inGroup')->willReturn(false);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->subAdmin->expects($this->once())->method('isSubAdminOfGroup')->with($this->user, $this->group)->willReturn(true);
		$this->schemas->method('findAll')->willReturn([]);
		$response = $this->controller(['group' => 'staff'])->index();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['results' => [], 'total' => 0], $response->getData());
	}

	public function testIndexByAdminSkipsSubAdminLookup(): void {
		$this->group->method('inGroup')->willReturn(false);
		$this->groups->expects($this->once())->method('isAdmin')->with('alice')->willReturn(true);
		$this->subAdmin->expects($this->never())->method('isSubAdminOfGroup');
		$this->schemas->method('findAll')->willReturn([]);
		$this->assertSame(200, $this->controller(['group' => 'staff'])->index()->getStatus());
	}

	public function testIndexByMemberListsOnlyStoredDefaultsOfDeclaredNotifications(): void {
		$this->group->expects($this->once())->method('inGroup')->with($this->user)->willReturn(true);
		$this->groups->expects($this->never())->method('isAdmin');
		$this->schemas->method('findAll')->willReturn(
			[
				$this->schema(3, 'case', 'Case', ['assigned' => [], 'quiet' => []]),
				$this->schema(4, null, null, ['done' => []]),
				$this->schema(5, 'plain', 'Plain', 'not-a-map'),
			]
		);
		$asked = [];
		$this->prefs->method('getGroupDefault')->willReturnCallback(
			static function (string $groupId, string $schemaSlug, string $notificationKey, ?string $scope) use (&$asked): ?array {
				$asked[] = [$groupId, $schemaSlug, $notificationKey, $scope];
				return match ($notificationKey) {
					'quiet'  => null,
					'done'   => ['enabled' => false, 'channels' => ['push']],
					default  => [],
				};
			}
		);

		$response = $this->controller(['group' => 'staff', 'scope' => 'register:1'])->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[['staff', 'case', 'assigned', 'register:1'], ['staff', 'case', 'quiet', 'register:1'], ['staff', '4', 'done', 'register:1']],
			$asked
		);
		$this->assertSame(
			[
				'results' => [
					['group' => 'staff', 'schema' => 'case', 'schemaTitle' => 'Case', 'notification' => 'assigned', 'scope' => 'register:1', 'enabled' => true, 'channels' => null],
					['group' => 'staff', 'schema' => '4', 'schemaTitle' => '4', 'notification' => 'done', 'scope' => 'register:1', 'enabled' => false, 'channels' => ['push']],
				],
				'total' => 2,
			],
			$response->getData()
		);
	}

	public function testIndexWithoutScopeAnswersGlobal(): void {
		$this->group->method('inGroup')->willReturn(true);
		$this->schemas->method('findAll')->willReturn([$this->schema(3, 'case', 'Case', ['assigned' => []])]);
		$this->prefs->expects($this->once())->method('getGroupDefault')->with('staff', 'case', 'assigned', null)->willReturn(['enabled' => true]);
		$data = $this->controller(['group' => 'staff'])->index()->getData();
		$this->assertSame('global', $data['results'][0]['scope']);
	}

	public function testIndexAnswersEmptyWhenSchemasCannotBeRead(): void {
		$this->group->method('inGroup')->willReturn(true);
		$this->schemas->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->prefs->expects($this->never())->method('getGroupDefault');
		$this->assertSame(['results' => [], 'total' => 0], $this->controller(['group' => 'staff'])->index()->getData());
	}

	public function testUpdateAnonymousIsRefusedWith401(): void {
		$this->user = null;
		$this->prefs->expects($this->never())->method('setGroupDefault');
		$this->assertSame(401, $this->controller(['group' => 'staff', 'schema' => 'case', 'notification' => 'n'])->update()->getStatus());
	}

	public function testUpdateWithMissingFieldIs422(): void {
		$this->prefs->expects($this->never())->method('setGroupDefault');
		$response = $this->controller(['group' => 'staff', 'schema' => 'case'])->update();
		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'A "group", a "schema" and a "notification" are required'], $response->getData());
	}

	public function testUpdateUnknownGroupIs404(): void {
		$this->prefs->expects($this->never())->method('setGroupDefault');
		$response = $this->controller(['group' => 'ghost', 'schema' => 'case', 'notification' => 'n'])->update();
		$this->assertSame(404, $response->getStatus());
	}

	public function testUpdateByMemberWhoDoesNotAdministerIs403(): void {
		$this->group->method('inGroup')->willReturn(true);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->subAdmin->method('isSubAdminOfGroup')->willReturn(false);
		$this->prefs->expects($this->never())->method('setGroupDefault');
		$response = $this->controller(['group' => 'staff', 'schema' => 'case', 'notification' => 'n'])->update();
		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'You do not administer that group'], $response->getData());
	}

	public function testUpdateIs403WhenSessionUserVanishesBeforeSubAdminCheck(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnOnConsecutiveCalls($this->user, null);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->subAdmin->expects($this->never())->method('isSubAdminOfGroup');
		$response = $this->controller(['group' => 'staff', 'schema' => 'case', 'notification' => 'n'], $session)->update();
		$this->assertSame(403, $response->getStatus());
	}

	public function testUpdateBySubAdminStoresDefaultAndAnswersStoredValue(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$this->subAdmin->method('isSubAdminOfGroup')->willReturn(true);
		$this->prefs->expects($this->once())->method('setGroupDefault')
			->with('staff', 'case', 'assigned', ['enabled' => false, 'channels' => ['push']], null);
		$this->prefs->expects($this->once())->method('getGroupDefault')
			->with('staff', 'case', 'assigned', null)->willReturn(['enabled' => false]);

		$response = $this->controller(['group' => 'staff', 'schema' => 'case', 'notification' => 'assigned', 'enabled' => '0', 'channels' => ['push']])->update();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['group' => 'staff', 'schema' => 'case', 'notification' => 'assigned', 'scope' => 'global', 'default' => ['enabled' => false]],
			$response->getData()
		);
	}

	public function testUpdateWithResetClearsTheDefault(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->prefs->expects($this->once())->method('setGroupDefault')->with('staff', 'case', 'assigned', null, 's');
		$this->prefs->method('getGroupDefault')->willReturn(null);
		$response = $this->controller(['group' => 'staff', 'schema' => 'case', 'notification' => 'assigned', 'reset' => 'true', 'scope' => 's'])->update();
		$this->assertSame(
			['group' => 'staff', 'schema' => 'case', 'notification' => 'assigned', 'scope' => 's', 'default' => null],
			$response->getData()
		);
	}
}
