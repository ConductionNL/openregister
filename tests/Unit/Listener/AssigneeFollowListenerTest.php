<?php

/**
 * Unit tests for AssigneeFollowListener.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Watcher;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AssigneeFollowListener;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who follows on assignment, and who does not.
 *
 * Every refusal is asserted with `never()` on the follow, against a control
 * (the first test) in which the same fixture DOES follow, so a refusal cannot
 * pass because the listener never got as far as looking.
 *
 * @coversDefaultClass \OCA\OpenRegister\Listener\AssigneeFollowListener
 */
class AssigneeFollowListenerTest extends TestCase {

	/**
	 * The follow primitive.
	 *
	 * @var WatcherService&MockObject
	 */
	private $watchers;

	/**
	 * Which uids are Nextcloud users.
	 *
	 * @var array<int, string>
	 */
	private array $users = ['jan', 'piet'];

	/**
	 * Whether the read check admits the assignee.
	 *
	 * @var boolean
	 */
	private bool $mayRead = true;

	/**
	 * The schema's property map.
	 *
	 * @var array<string, mixed>
	 */
	private array $properties = [
		'title' => ['type' => 'string'],
		'assignee' => ['type' => 'string', 'x-openregister-role' => 'assignee'],
	];

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->watchers = $this->createMock(originalClassName: WatcherService::class);

	}//end setUp()

	/**
	 * The listener over the current fixture.
	 *
	 * @return AssigneeFollowListener
	 */
	private function makeListener(): AssigneeFollowListener {
		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getProperties')->willReturn($this->properties);

		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn (string $uid): bool => in_array($uid, $this->users, true));

		$permissions = $this->createMock(originalClassName: PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(fn (): bool => $this->mayRead);

		return new AssigneeFollowListener(
			$schemaMapper,
			new SemanticRoleHandler(),
			$permissions,
			$users,
			$this->watchers,
			$this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end makeListener()

	/**
	 * An object with the given assignee.
	 *
	 * @param string|null $assignee The assignee value.
	 *
	 * @return ObjectEntity
	 */
	private function makeObject(?string $assignee): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-case-1');
		$object->setSchema('12');
		$object->setRegister('3');
		$object->setObject(['title' => 'Bouwvergunning', 'assignee' => $assignee]);

		return $object;

	}//end makeObject()

	/**
	 * A new assignee who may read the case follows it with notifications on.
	 *
	 * @return void
	 */
	public function testANewAssigneeFollowsWithNotificationsOn(): void {
		$object = $this->makeObject(assignee: 'jan');
		$this->watchers->expects($this->once())
			->method('followAssigned')
			->with($object, 'jan', '3', '12')
			->willReturn(new Watcher());

		$this->makeListener()->handle(new ObjectCreatedEvent($object));

	}//end testANewAssigneeFollowsWithNotificationsOn()

	/**
	 * A reassignment follows for the new assignee.
	 *
	 * @return void
	 */
	public function testAReassignmentFollowsForTheNewAssignee(): void {
		$this->watchers->expects($this->once())
			->method('followAssigned')
			->with($this->anything(), 'piet')
			->willReturn(new Watcher());

		$this->makeListener()->handle(
			new ObjectUpdatedEvent($this->makeObject(assignee: 'piet'), $this->makeObject(assignee: 'jan'))
		);

	}//end testAReassignmentFollowsForTheNewAssignee()

	/**
	 * A save that leaves the assignee as it was writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnchangedAssigneeIsLeftAlone(): void {
		$this->watchers->expects($this->never())->method('followAssigned');

		$this->makeListener()->handle(
			new ObjectUpdatedEvent($this->makeObject(assignee: 'jan'), $this->makeObject(assignee: 'jan'))
		);

	}//end testAnUnchangedAssigneeIsLeftAlone()

	/**
	 * A group id in the assignee property is not a follower.
	 *
	 * @return void
	 */
	public function testAValueThatIsNotAUserIsSkipped(): void {
		$this->watchers->expects($this->never())->method('followAssigned');

		$this->makeListener()->handle(new ObjectCreatedEvent($this->makeObject(assignee: 'behandelaars')));

	}//end testAValueThatIsNotAUserIsSkipped()

	/**
	 * An assignee who may not read the case does not follow it.
	 *
	 * @return void
	 */
	public function testAnAssigneeWithoutReadIsSkipped(): void {
		$this->mayRead = false;
		$this->watchers->expects($this->never())->method('followAssigned');

		$this->makeListener()->handle(new ObjectCreatedEvent($this->makeObject(assignee: 'jan')));

	}//end testAnAssigneeWithoutReadIsSkipped()

	/**
	 * A schema that declares no assignee role follows nobody.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheRoleIsSkipped(): void {
		$this->properties = ['assignee' => ['type' => 'string']];
		$this->watchers->expects($this->never())->method('followAssigned');

		$this->makeListener()->handle(new ObjectCreatedEvent($this->makeObject(assignee: 'jan')));

	}//end testASchemaWithoutTheRoleIsSkipped()

	/**
	 * A failing follow never fails the save.
	 *
	 * @return void
	 */
	public function testAFailingFollowIsSwallowed(): void {
		$this->watchers->method('followAssigned')->willThrowException(new \RuntimeException('db down'));

		$this->makeListener()->handle(new ObjectCreatedEvent($this->makeObject(assignee: 'jan')));

		$this->addToAssertionCount(1);

	}//end testAFailingFollowIsSwallowed()
}//end class
