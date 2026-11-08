<?php

/**
 * Golden test for the notification controllers that share the current-user helpers.
 *
 * Drives every action of the preference, group-preference, broadcast, template,
 * subscription and delivery-window controllers as an anonymous caller, a plain
 * user, a group member, a sub-admin and an administrator. It records the
 * response status and body and every call the action made on its collaborators,
 * so both the allowed and the refused outcomes are pinned. Re-record with
 * UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Notifications\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCA\OpenRegister\Kernel\Contracts\NotificationTemplatesInterface;
use OCA\OpenRegister\Notifications\Controller\NotificationBroadcastController;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationGroupPreferencesControllerServices;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationSubscriptionsControllerServices;
use OCA\OpenRegister\Notifications\Controller\NotificationDeliveryWindowController;
use OCA\OpenRegister\Notifications\Controller\NotificationGroupPreferencesController;
use OCA\OpenRegister\Notifications\Controller\NotificationPreferencesController;
use OCA\OpenRegister\Notifications\Controller\NotificationSubscriptionsController;
use OCA\OpenRegister\Notifications\Controller\NotificationTemplatesController;
use OCA\OpenRegister\Notifications\Db\NotificationBroadcast;
use OCA\OpenRegister\Notifications\Db\NotificationSubscription;
use OCA\OpenRegister\Notifications\Db\NotificationSubscriptionMapper;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationBroadcastService;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeliveryWindowService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Group\ISubAdmin;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Controller\NotificationBroadcastController
 * @covers \OCA\OpenRegister\Notifications\Controller\NotificationDeliveryWindowController
 * @covers \OCA\OpenRegister\Notifications\Controller\NotificationGroupPreferencesController
 * @covers \OCA\OpenRegister\Notifications\Controller\NotificationPreferencesController
 * @covers \OCA\OpenRegister\Notifications\Controller\NotificationSubscriptionsController
 * @covers \OCA\OpenRegister\Notifications\Controller\NotificationTemplatesController
 */
class A26NotificationControllersGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/a26-notification-controllers-golden.json';

	/**
	 * The callers: uid (null is anonymous), admin, member of the group, sub-admin of it.
	 */
	private const CALLERS = [
		'anonymous' => [null, false, false, false],
		'user'      => ['alice', false, false, false],
		'member'    => ['alice', false, true, false],
		'subadmin'  => ['alice', false, false, true],
		'admin'     => ['root', true, false, false],
	];

	/** @var array<int, string> */
	private array $calls = [];

	public function testControllerOutcomesMatchGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => [$build, $action, $args, $params]) {
			foreach (self::CALLERS as $caller => $who) {
				$actual[$name . ' as ' . $caller] = $this->outcome(build: $build, who: $who, action: $action, args: $args, params: $params);
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testControllerOutcomesMatchGolden()

	/**
	 * @return array<string, array{0: string, 1: string, 2: array, 3: array}>
	 */
	private function scenarios(): array {
		$pref = ['schema' => 'case', 'notification' => 'assigned'];
		$group = ['group' => 'staff', 'schema' => 'case', 'notification' => 'assigned'];
		return [
			'prefs index'                => ['prefs', 'index', [], []],
			'prefs index scoped'         => ['prefs', 'index', [], ['scope' => 'register:1']],
			'prefs update missing'       => ['prefs', 'update', [], ['schema' => 'case']],
			'prefs update empty'         => ['prefs', 'update', [], ['schema' => '', 'notification' => 'x']],
			'prefs update'               => ['prefs', 'update', [], $pref + ['enabled' => false, 'channels' => ['email'], 'scope' => 's']],
			'prefs update scalar chan'   => ['prefs', 'update', [], $pref + ['channels' => 'email']],
			'prefs reset bool'           => ['prefs', 'update', [], $pref + ['reset' => true]],
			'prefs reset string'         => ['prefs', 'update', [], $pref + ['reset' => 'true', 'scope' => 's']],
			'prefs reset other'          => ['prefs', 'update', [], $pref + ['reset' => '1']],
			'group index no group'       => ['group', 'index', [], []],
			'group index unknown'        => ['group', 'index', [], ['group' => 'ghost']],
			'group index'                => ['group', 'index', [], ['group' => 'staff', 'scope' => 's']],
			'group update missing'       => ['group', 'update', [], ['group' => 'staff']],
			'group update unknown'       => ['group', 'update', [], ['group' => 'ghost', 'schema' => 'case', 'notification' => 'n']],
			'group update'               => ['group', 'update', [], $group + ['enabled' => '0', 'channels' => ['push']]],
			'group reset'                => ['group', 'update', [], $group + ['reset' => 'true', 'scope' => 's']],
			'broadcast index'            => ['broadcast', 'index', [], ['limit' => '5', 'offset' => 'x']],
			'broadcast create no subj'   => ['broadcast', 'create', [], ['subject' => '']],
			'broadcast create bad date'  => ['broadcast', 'create', [], ['subject' => 'Hi', 'endsAt' => 'not a date']],
			'broadcast create no end'    => ['broadcast', 'create', [], ['subject' => 'Hi', 'body' => '']],
			'broadcast create invalid'   => ['broadcast', 'create', [], ['subject' => 'invalid', 'startsAt' => '2026-01-01', 'endsAt' => '2026-02-01']],
			'broadcast create'           => ['broadcast', 'create', [], ['subject' => 'Hi', 'body' => 'B', 'startsAt' => '2026-01-01', 'endsAt' => '2026-02-01']],
			'broadcast destroy unknown'  => ['broadcast', 'destroy', ['ghost'], []],
			'broadcast destroy'          => ['broadcast', 'destroy', ['b-1'], []],
			'broadcast active'           => ['broadcast', 'active', [], []],
			'broadcast ack unknown'      => ['broadcast', 'acknowledge', ['ghost'], []],
			'broadcast ack'              => ['broadcast', 'acknowledge', ['b-1'], []],
			'templates index'            => ['templates', 'index', [], []],
			'templates gaps'             => ['templates', 'gaps', [], []],
			'templates update missing'   => ['templates', 'update', ['object.created'], []],
			'templates update invalid'   => ['templates', 'update', ['bad.event'], ['template' => ['subject' => 'x']]],
			'templates update'           => ['templates', 'update', ['object.created'], ['template' => ['subject' => 'x']]],
			'templates reset'            => ['templates', 'update', ['object.created'], ['reset' => true]],
			'subs index'                 => ['subs', 'index', [], []],
			'subs create none'           => ['subs', 'create', [], []],
			'subs create register gone'  => ['subs', 'create', [], ['registerId' => '404']],
			'subs create schema gone'    => ['subs', 'create', [], ['registerId' => '1', 'schemaId' => '404']],
			'subs create'                => ['subs', 'create', [], ['registerId' => '1', 'schemaId' => 'abc']],
			'subs create both'           => ['subs', 'create', [], ['registerId' => 1, 'schemaId' => '2']],
			'subs destroy'               => ['subs', 'destroy', [], ['registerId' => '', 'schemaId' => '2']],
			'window index none'          => ['window', 'index', [], []],
			'window update off'          => ['window', 'update', [], ['enabled' => false]],
			'window update invalid'      => ['window', 'update', [], ['start' => 'bad']],
			'window update'              => ['window', 'update', [], ['start' => '22:00', 'end' => '07:00']],
		];
	}//end scenarios()

	private function outcome(string $build, array $who, string $action, array $args, array $params): array {
		$this->calls = [];
		$controller = $this->controller(name: $build, who: $who, params: $params);
		try {
			$response = $controller->$action(...$args);
		} catch (\Throwable $e) {
			return ['thrown' => get_class($e) . ': ' . $e->getMessage(), 'calls' => $this->calls];
		}

		$this->assertInstanceOf(JSONResponse::class, $response);
		return ['status' => $response->getStatus(), 'data' => $this->plain(value: $response->getData()), 'calls' => $this->calls];
	}//end outcome()

	private function controller(string $name, array $who, array $params): object {
		[$uid, $admin, $member, $subadmin] = $who;
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$groups = $this->groupManager(admin: $admin, member: $member);
		$subAdmin = $this->recording(class: ISubAdmin::class, returns: ['isSubAdminOfGroup' => $subadmin]);
		return match ($name) {
			'prefs'     => new NotificationPreferencesController('openregister', $request, $this->preferences(), $session),
			'group'     => new NotificationGroupPreferencesController(
				$request,
				new NotificationGroupPreferencesControllerServices($this->preferences(), $this->schemas(), $session),
				$groups,
				$subAdmin
			),
			'broadcast' => new NotificationBroadcastController($request, $this->broadcasts(), $groups, $session),
			'templates' => new NotificationTemplatesController($request, $this->templates(), $groups, $session),
			'subs'      => $this->subscriptions(request: $request, session: $session),
			'window'    => new NotificationDeliveryWindowController('openregister', $request, $this->window(), $session),
		};
	}//end controller()

	private function groupManager(bool $admin, bool $member): IGroupManager {
		$group = $this->recording(class: IGroup::class, returns: ['inGroup' => $member, 'getGID' => 'staff']);
		return $this->recording(
			class: IGroupManager::class,
			returns: [
				'isAdmin' => $admin,
				'get'     => static fn (string $gid): ?IGroup => $gid === 'staff' ? $group : null,
			]
		);
	}//end groupManager()

	private function preferences(): NotificationPreferencesInterface {
		return $this->recording(
			class: NotificationPreferencesInterface::class,
			returns: [
				'getEffectiveForUser' => [['schema' => 'case', 'enabled' => true]],
				'getOverride'         => ['enabled' => false],
				'getGroupDefault'     => static fn (string $groupId, string $schemaSlug, string $notificationKey): ?array => $notificationKey === 'quiet' ? null : ['channels' => ['email']],
			]
		);
	}//end preferences()

	private function schemas(): SchemaMapperInterface {
		$declared = new Schema();
		$declared->setId(3);
		$declared->setSlug('case');
		$declared->setTitle('Case');
		$declared->setConfiguration(['x-openregister-notifications' => ['assigned' => [], 'quiet' => [], 7 => []]]);
		$untitled = new Schema();
		$untitled->setId(4);
		$untitled->setConfiguration(['x-openregister-notifications' => ['done' => []]]);
		$plain = new Schema();
		$plain->setId(5);
		$plain->setConfiguration(['x-openregister-notifications' => 'not-a-map']);
		return $this->recording(class: SchemaMapperInterface::class, returns: ['findAll' => [$declared, $untitled, $plain], 'find' => $declared]);
	}//end schemas()

	private function broadcasts(): NotificationBroadcastService {
		$broadcast = $this->createMock(NotificationBroadcast::class);
		$broadcast->method('jsonSerialize')->willReturn(['uuid' => 'b-1']);
		return $this->recording(
			class: NotificationBroadcastService::class,
			returns: [
				'listAll'     => [['uuid' => 'b-1']],
				'send'        => static fn (string $subject): NotificationBroadcast => $subject === 'invalid' ? throw new InvalidArgumentException('window too long') : $broadcast,
				'withdraw'    => static fn (string $broadcastUuid): bool => $broadcastUuid === 'b-1',
				'activeFor'   => [$broadcast],
				'acknowledge' => static fn (string $broadcastUuid): bool => $broadcastUuid === 'b-1',
			]
		);
	}//end broadcasts()

	private function templates(): NotificationTemplatesInterface {
		return $this->recording(
			class: NotificationTemplatesInterface::class,
			returns: [
				'listAll'     => [['event' => 'object.created']],
				'gaps'        => ['object.deleted'],
				'edit'        => static fn (string $event): null => $event === 'bad.event' ? throw new InvalidArgumentException('unknown event') : null,
				'templateFor' => ['subject' => 'x'],
				'hasEdit'     => true,
			]
		);
	}//end templates()

	private function subscriptions(IRequest $request, IUserSession $session): NotificationSubscriptionsController {
		$entity = $this->createMock(NotificationSubscription::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 1]);
		$mapper = $this->createMock(NotificationSubscriptionMapper::class);
		$mapper->method('findByUser')->willReturnCallback(fn (string $userId): array => $this->log(call: 'mapper::findByUser', args: [$userId], result: [$entity]));
		$mapper->method('subscribe')->willReturnCallback(
			function (string $userId, ?int $registerId, ?int $schemaId) use ($entity): NotificationSubscription {
				$this->log(call: 'mapper::subscribe', args: [$userId, $registerId, $schemaId], result: null);
				if ($registerId === null && $schemaId === null) {
					throw new InvalidArgumentException('needs a scope');
				}

				return $entity;
			}
		);
		$mapper->method('unsubscribe')->willReturnCallback(fn (string $userId, ?int $registerId, ?int $schemaId): bool => $this->log(call: 'mapper::unsubscribe', args: [$userId, $registerId, $schemaId], result: true));
		$register = new Register();
		$schema = new Schema();
		$registers = $this->recording(
			class: RegisterMapperInterface::class,
			returns: ['find' => static fn (int|string $id): Register => (int) $id === 404 ? throw new RuntimeException('gone') : $register]
		);
		$schemas = $this->recording(
			class: SchemaMapperInterface::class,
			returns: ['find' => static fn (int|string $id): Schema => (int) $id === 404 ? throw new RuntimeException('gone') : $schema]
		);
		return new NotificationSubscriptionsController($request, new NotificationSubscriptionsControllerServices($mapper, $registers, $schemas), $session);
	}//end subscriptions()

	private function window(): NotificationDeliveryWindowService {
		$stored = null;
		return $this->recording(
			class: NotificationDeliveryWindowService::class,
			returns: [
				'getForUser' => static function () use (&$stored): ?array {
					return $stored;
				},
				'setForUser' => static function (string $userId, ?array $window) use (&$stored): void {
					if (($window['start'] ?? null) === 'bad') {
						throw new InvalidArgumentException('start must be HH:MM');
					}

					$stored = $window;
				},
			]
		);
	}//end window()

	/**
	 * A mock of the class whose every public method logs its call and answers the configured value.
	 *
	 * A configured closure is called with the arguments; an unconfigured method answers the
	 * empty value of its return type.
	 */
	private function recording(string $class, array $returns): MockObject {
		$mock = $this->createMock($class);
		$short = (new ReflectionClass($class))->getShortName();
		foreach ((new ReflectionClass($class))->getMethods() as $method) {
			if ($method->isPublic() === false || $method->isStatic() === true || $method->isConstructor() === true || $method->isFinal() === true) {
				continue;
			}

			$name = $method->getName();
			$type = $method->getReturnType();
			$mock->method($name)->willReturnCallback(
				function (mixed ...$args) use ($short, $name, $returns, $type): mixed {
					$answer = $returns[$name] ?? $this->emptyOf(type: $type);
					if ($answer instanceof \Closure === true) {
						$this->log(call: $short . '::' . $name, args: $args, result: null);
						return $answer(...$args);
					}

					return $this->log(call: $short . '::' . $name, args: $args, result: $answer);
				}
			);
		}

		return $mock;
	}//end recording()

	private function emptyOf(mixed $type): mixed {
		if ($type instanceof ReflectionNamedType === false || $type->allowsNull() === true) {
			return null;
		}

		return match ($type->getName()) {
			'array'  => [],
			'bool'   => false,
			'int'    => 0,
			'string' => '',
			default  => null,
		};
	}//end emptyOf()

	private function log(string $call, array $args, mixed $result): mixed {
		$this->calls[] = $call . '(' . json_encode($this->plain(value: $args), JSON_UNESCAPED_SLASHES) . ')';
		return $result;
	}//end log()

	private function plain(mixed $value): mixed {
		if (is_array($value) === true) {
			return array_map(fn (mixed $item): mixed => $this->plain(value: $item), $value);
		}

		if ($value instanceof DateTimeInterface === true) {
			return 'DateTime';
		}

		if ($value instanceof IUser === true) {
			return 'IUser:' . $value->getUID();
		}

		if ($value instanceof IGroup === true) {
			return 'IGroup';
		}

		if (is_object($value) === true) {
			return (string) (get_parent_class($value) ?: get_class($value));
		}

		return $value;
	}//end plain()
}//end class
