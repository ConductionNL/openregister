<?php

/**
 * Pins every activity ActivityService publishes: subject, type, object, link and who receives it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use ArrayObject;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Service\ActivityService;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\ActivityEntries
 * @covers \OCA\OpenRegister\Notifications\Service\ActivityEntry
 * @covers \OCA\OpenRegister\Notifications\Service\ActivityService
 * @covers \OCA\OpenRegister\Notifications\Service\ActivitySubject
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ActivityServiceGoldenTest extends TestCase {
	private const BASE = 'https://cloud/apps/openregister/';

	private IManager&MockObject $manager;
	private IUserSession&MockObject $session;
	private IURLGenerator&MockObject $urls;
	private LoggerInterface&MockObject $logger;

	/** @var list<array<string, mixed>> */
	private array $published = [];

	/** @var array<int, ArrayObject<string, mixed>> */
	private array $seen = [];

	/** @var list<IEvent> */
	private array $events = [];

	protected function setUp(): void {
		$this->manager = $this->createMock(IManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->urls    = $this->createMock(IURLGenerator::class);
		$this->logger  = $this->createMock(LoggerInterface::class);

		$this->urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => ($route === 'openregister.dashboard.page' ? self::BASE : 'wrong-route')
		);
		$this->urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud' . $url);
		$this->manager->method('generateEvent')->willReturnCallback(fn (): IEvent => $this->event());
		$this->manager->method('publish')->willReturnCallback(
			function (IEvent $event): void {
				$this->published[] = $this->seen[spl_object_id($event)]->getArrayCopy();
			}
		);
	}//end setUp()

	/**
	 * An event that remembers what was set on it, apart from the time.
	 */
	private function event(): IEvent {
		$event = $this->createMock(IEvent::class);
		$seen  = new ArrayObject();
		$setters = [
			'setApp' => static fn (string $app) => $seen['app'] = $app,
			'setType' => static fn (string $type) => $seen['type'] = $type,
			'setAuthor' => static fn (string $author) => $seen['author'] = $author,
			'setSubject' => static fn (string $subject, array $parameters) => $seen['subject'] = [$subject, $parameters],
			'setObject' => static fn (string $type, int $id, string $name) => $seen['object'] = [$type, $id, $name],
			'setAffectedUser' => static fn (string $user) => $seen['affected'] = $user,
			'setLink' => static fn (string $link) => $seen['link'] = $link,
		];
		foreach ($setters as $setter => $remember) {
			$event->method($setter)->willReturnCallback(
				static function (mixed ...$arguments) use ($remember, $event): IEvent {
					$remember(...$arguments);
					return $event;
				}
			);
		}

		$event->method('setTimestamp')->willReturnSelf();
		$this->seen[spl_object_id($event)] = $seen;
		$this->events[] = $event;
		return $event;
	}//end event()

	private function login(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end login()

	private function service(?SchemaMapper $schemas = null, ?DeepLinkRegistryService $links = null): ActivityService {
		return new ActivityService($this->manager, $this->session, new \OCA\OpenRegister\Notifications\Service\ActivityEntries($this->urls, $this->logger, $schemas, $links), $this->logger);
	}//end service()

	private function object(?string $owner = 'owner'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setId(11);
		$object->setUuid('uuid-1');
		$object->setName('Case');
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setOwner($owner);
		$object->setObject(['title' => 'x']);
		return $object;
	}//end object()

	private function register(?string $title = 'Reg'): Register {
		$register = new Register();
		$register->setId(3);
		$register->setUuid('reg-uuid');
		$register->setTitle($title);
		$register->setOwner('owner');
		return $register;
	}//end register()

	private function schema(?string $title = 'Sch'): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setUuid('sch-uuid');
		$schema->setTitle($title);
		$schema->setOwner('owner');
		return $schema;
	}//end schema()

	private function expected(string $subject, array $parameters, array $object, string $link, string $affected = 'alice'): array {
		[$kind] = explode('_', $subject);
		$seen = [
			'app' => 'openregister',
			'type' => 'openregister_' . $kind . 's',
			'author' => 'alice',
			'subject' => [$subject, $parameters],
			'object' => $object,
			'affected' => $affected,
		];
		if ($link !== '') {
			$seen['link'] = $link;
		}

		return $seen;
	}//end expected()

	/**
	 * Every entity activity, to the author and to a different owner.
	 *
	 * @dataProvider entityActivities
	 */
	public function testAnEntityActivityReachesAuthorAndOwner(string $method, string $entity, string $subject, array $object, string $link, string $title): void {
		$this->login('alice');

		$this->service()->$method($this->$entity());

		$this->assertEquals(
			[
				$this->expected($subject, ['title' => $title], $object, $link),
				$this->expected($subject, ['title' => $title], $object, $link, 'owner'),
			],
			$this->published
		);
	}//end testAnEntityActivityReachesAuthorAndOwner()

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function entityActivities(): array {
		$object   = ['object', 11, 'Case'];
		$register = ['register', 3, 'Reg'];
		$schema   = ['schema', 7, 'Sch'];
		return [
			'object created'   => ['publishObjectCreated', 'object', 'object_created', $object, self::BASE . '#/registers/3/schemas/7/objects/uuid-1', 'Case'],
			'object updated'   => ['publishObjectUpdated', 'object', 'object_updated', $object, self::BASE . '#/registers/3/schemas/7/objects/uuid-1', 'Case'],
			'object deleted'   => ['publishObjectDeleted', 'object', 'object_deleted', $object, '', 'Case'],
			'register created' => ['publishRegisterCreated', 'register', 'register_created', $register, self::BASE . '#/registers/3', 'Reg'],
			'register updated' => ['publishRegisterUpdated', 'register', 'register_updated', $register, self::BASE . '#/registers/3', 'Reg'],
			'register deleted' => ['publishRegisterDeleted', 'register', 'register_deleted', $register, '', 'Reg'],
			'schema created'   => ['publishSchemaCreated', 'schema', 'schema_created', $schema, self::BASE . '#/schemas/7', 'Sch'],
			'schema updated'   => ['publishSchemaUpdated', 'schema', 'schema_updated', $schema, self::BASE . '#/schemas/7', 'Sch'],
			'schema deleted'   => ['publishSchemaDeleted', 'schema', 'schema_deleted', $schema, '', 'Sch'],
		];
	}//end entityActivities()

	/**
	 * A title falls back to the uuid, then to 'Unknown'.
	 */
	public function testTheTitleFallsBack(): void {
		$this->login('alice');
		$noTitle = $this->register(title: '');
		$service = $this->service();

		$service->publishRegisterUpdated($noTitle);
		$this->assertSame('reg-uuid', $this->published[0]['object'][2]);

		$bare = new Schema();
		$bare->setId(7);
		$bare->setUuid('');
		$bare->setOwner('alice');
		$service->publishSchemaUpdated($bare);
		$this->assertSame('Unknown', end($this->published)['object'][2]);
		$this->assertSame(['title' => 'Unknown'], end($this->published)['subject'][1]);
	}//end testTheTitleFallsBack()

	/**
	 * The author alone receives it when the owner is the author, absent or empty.
	 *
	 * @dataProvider soleRecipients
	 */
	public function testNoSecondEventWithoutAnotherOwner(?string $owner): void {
		$this->login('alice');

		$this->service()->publishObjectCreated($this->object(owner: $owner));

		$this->assertCount(1, $this->published);
		$this->assertSame('alice', $this->published[0]['affected']);
	}//end testNoSecondEventWithoutAnotherOwner()

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public static function soleRecipients(): array {
		return ['same' => ['alice'], 'none' => [null], 'empty' => ['']];
	}//end soleRecipients()

	/**
	 * In system context the owner receives it once, with an empty author.
	 */
	public function testSystemContextGoesToTheOwnerOnce(): void {
		$this->service()->publishObjectCreated($this->object());

		$this->assertCount(1, $this->published);
		$this->assertSame('owner', $this->published[0]['affected']);
		$this->assertSame('', $this->published[0]['author']);
	}//end testSystemContextGoesToTheOwnerOnce()

	/**
	 * Without author and owner nothing is published.
	 *
	 * @dataProvider noOwners
	 */
	public function testNoRecipientPublishesNothing(?string $owner): void {
		$this->manager->expects($this->never())->method('generateEvent');

		$this->service()->publishObjectDeleted($this->object(owner: $owner));
	}//end testNoRecipientPublishesNothing()

	/**
	 * @return array<string, array{0: string|null}>
	 */
	public static function noOwners(): array {
		return ['none' => [null], 'empty' => ['']];
	}//end noOwners()

	/**
	 * A failure while publishing is logged, not thrown.
	 */
	public function testAFailureIsLogged(): void {
		$this->login('alice');
		$manager = $this->createMock(IManager::class);
		$manager->method('generateEvent')->willThrowException(new RuntimeException('down'));
		$this->manager = $manager;
		$this->logger->expects($this->once())->method('error')->with(
			'Failed to publish OpenRegister activity',
			['subject' => 'schema_deleted', 'type' => 'openregister_schemas', 'exception' => 'down']
		);

		$this->service()->publishSchemaDeleted($this->schema());
	}//end testAFailureIsLogged()

	/**
	 * An object activity names its schema, trimmed, read outside RBAC and tenancy.
	 */
	public function testAnObjectActivityNamesItsSchema(): void {
		$this->login('alice');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->expects($this->once())->method('find')->with('7', [], false, false)->willReturn($this->schema(title: '  Sch '));

		$this->service(schemas: $schemas)->publishObjectDeleted($this->object(owner: 'alice'));

		$this->assertSame(['title' => 'Case', 'schema' => 'Sch'], $this->published[0]['subject'][1]);
	}//end testAnObjectActivityNamesItsSchema()

	/**
	 * A blank schema title adds nothing; a failed read is logged and adds nothing.
	 */
	public function testASchemaWithoutATitleAddsNothing(): void {
		$this->login('alice');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnOnConsecutiveCalls($this->schema(title: '  '), $this->throwException(new RuntimeException('gone')));
		$this->logger->expects($this->once())->method('debug')->with(
			'[ActivityService] Schema title not resolved for an object activity',
			['schema' => '7', 'exception' => 'gone']
		);

		$service = $this->service(schemas: $schemas);
		$service->publishObjectDeleted($this->object(owner: 'alice'));
		$service->publishObjectDeleted($this->object(owner: 'alice'));

		$this->assertSame(['title' => 'Case'], $this->published[0]['subject'][1]);
		$this->assertSame(['title' => 'Case'], $this->published[1]['subject'][1]);
	}//end testASchemaWithoutATitleAddsNothing()

	/**
	 * An object without a schema reference is not looked up.
	 */
	public function testAnObjectWithoutASchemaIsNotLookedUp(): void {
		$this->login('alice');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->expects($this->never())->method('find');
		$object = $this->object(owner: 'alice');
		$object->setSchema('');

		$this->service(schemas: $schemas)->publishObjectDeleted($object);

		$this->assertCount(1, $this->published);
	}//end testAnObjectWithoutASchemaIsNotLookedUp()

	/**
	 * An owning app's deep link wins; a relative one is made absolute, an absolute one kept.
	 *
	 * @dataProvider deepLinks
	 */
	public function testADeepLink(?string $owned, string $link): void {
		$this->login('alice');
		$links = $this->createMock(DeepLinkRegistryService::class);
		$links->expects($this->once())->method('resolveUrl')
			->with(3, 7, ['title' => 'x', 'id' => 'uuid-1', 'uuid' => 'uuid-1'])
			->willReturn($owned);

		$this->service(links: $links)->publishObjectCreated($this->object(owner: 'alice'));

		$this->assertSame($link, $this->published[0]['link']);
	}//end testADeepLink()

	/**
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function deepLinks(): array {
		$default = self::BASE . '#/registers/3/schemas/7/objects/uuid-1';
		return [
			'none'     => [null, $default],
			'empty'    => ['', $default],
			'absolute' => ['https://other/x', 'https://other/x'],
			'relative' => ['/apps/dossiq/1', 'https://cloud/apps/dossiq/1'],
		];
	}//end deepLinks()

	/**
	 * Register or schema slugs cannot name a deep link.
	 */
	public function testSlugsSkipTheDeepLink(): void {
		$this->login('alice');
		$links = $this->createMock(DeepLinkRegistryService::class);
		$links->expects($this->never())->method('resolveUrl');
		$object = $this->object(owner: 'alice');
		$object->setRegister('crm');

		$this->service(links: $links)->publishObjectCreated($object);

		$this->assertSame(self::BASE . '#/registers/crm/schemas/7/objects/uuid-1', $this->published[0]['link']);
	}//end testSlugsSkipTheDeepLink()
}//end class
