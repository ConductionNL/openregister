<?php

/**
 * Unit tests for EntryMentionService — naming somebody pulls them in, unless
 * they could not see the case in the first place.
 *
 * The rule under test is the one that matters: a mention cannot grant access.
 * The others cover what counts as a mention at all.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Timeline;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Watcher;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Timeline\EntryMentionService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * EntryMentionServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class EntryMentionServiceTest extends TestCase {
	/**
	 * Watcher primitive mock.
	 *
	 * @var WatcherService&MockObject
	 */
	private WatcherService&MockObject $watchers;

	/**
	 * User manager mock.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Schema mapper mock.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * RBAC verdict mock.
	 *
	 * @var PermissionHandler&MockObject
	 */
	private PermissionHandler&MockObject $permissions;

	/**
	 * Notification manager mock.
	 *
	 * @var INotificationManager&MockObject
	 */
	private INotificationManager&MockObject $notifications;

	/**
	 * Service under test.
	 *
	 * @var EntryMentionService
	 */
	private EntryMentionService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->watchers = $this->createMock(WatcherService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->permissions = $this->createMock(PermissionHandler::class);
		$this->notifications = $this->createMock(INotificationManager::class);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$this->notifications->method('createNotification')->willReturn($notification);

		$this->service = new EntryMentionService(
			$this->watchers,
			$this->userManager,
			$this->userSession,
			$this->schemaMapper,
			$this->permissions,
			$this->notifications,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setSchema('3');

		return $object;
	}

	private function signIn(string $uid = 'handler'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	private function everybodyExists(): void {
		$this->userManager->method('userExists')->willReturn(true);
	}

	private function allowRead(bool $allowed): void {
		$this->schemaMapper->method('find')->willReturn($this->createMock(Schema::class));
		$this->permissions->method('hasPermission')->willReturn($allowed);
	}

	public function testATokenIsAMentionOnlyWhenItNamesSomebodyReal(): void {
		$this->userManager->method('userExists')->willReturnCallback(
			static fn (string $uid): bool => ($uid === 'jurist')
		);

		$this->assertSame(['jurist'], $this->service->parse('Even met @jurist overleggen, niet met @niemand'));
	}

	public function testAnEmailAddressIsNotAMention(): void {
		$this->everybodyExists();

		$this->assertSame([], $this->service->parse('Antwoord gestuurd naar info@conduction.nl'));
	}

	public function testTheSamePersonNamedTwiceIsOneMention(): void {
		$this->everybodyExists();

		$this->assertSame(['jurist'], $this->service->parse('@jurist, en nogmaals @jurist'));
	}

	public function testATextWithNoMentionsProducesNone(): void {
		$this->assertSame([], $this->service->parse('Gewoon een notitie'));
		$this->assertSame([], $this->service->parse(null));
		$this->assertSame([], $this->service->parse('   '));
	}

	public function testTheJuristIsPulledInAndStaysIn(): void {
		$this->signIn();
		$this->everybodyExists();
		$this->allowRead(true);

		$this->watchers->expects($this->once())->method('subscribeMentioned')
			->willReturn(new Watcher());
		$this->notifications->expects($this->once())->method('notify');

		$pulled = $this->service->apply($this->object(), 'entry-a', 'Graag jouw blik hierop @jurist');

		$this->assertSame(['jurist'], $pulled);
	}

	public function testAMentionCannotGrantAccess(): void {
		$this->signIn();
		$this->everybodyExists();
		$this->allowRead(false);

		// Neither notified nor subscribed: naming somebody is otherwise a way
		// to tell them a case exists and what it is called.
		$this->watchers->expects($this->never())->method('subscribeMentioned');
		$this->notifications->expects($this->never())->method('notify');

		$this->assertSame([], $this->service->apply($this->object(), 'entry-a', 'Kijk jij hier even naar @buitenstaander'));
	}

	public function testAnUnresolvableSchemaRefusesTheMention(): void {
		$this->signIn();
		$this->everybodyExists();
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('gone'));
		$this->permissions->expects($this->never())->method('hasPermission');
		$this->watchers->expects($this->never())->method('subscribeMentioned');

		$this->assertSame([], $this->service->apply($this->object(), 'entry-a', '@jurist'));
	}

	public function testNamingYourselfIsNotAMention(): void {
		$this->signIn('handler');
		$this->everybodyExists();
		$this->allowRead(true);
		$this->watchers->expects($this->never())->method('subscribeMentioned');

		$this->assertSame([], $this->service->apply($this->object(), 'entry-a', 'Nota bene voor @handler zelf'));
	}

	public function testASubscriptionThatFailsDoesNotProduceANotification(): void {
		$this->signIn();
		$this->everybodyExists();
		$this->allowRead(true);
		$this->watchers->method('subscribeMentioned')->willThrowException(new \RuntimeException('no row'));
		$this->notifications->expects($this->never())->method('notify');

		$this->assertSame([], $this->service->apply($this->object(), 'entry-a', '@jurist'));
	}
}
