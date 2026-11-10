<?php

/**
 * Golden tests for the read check AssigneeFollowListener asks before following.
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
 * @spec openspec/specs/object-interactions/spec.md#requirement-being-assigned-an-object-follows-it-with-notifications-on
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AssigneeFollowListener;
use OCA\OpenRegister\Listener\Support\AssigneeFollowServices;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Schemas\SemanticRoleHandler;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the exact read question asked, for an allowed and a refused assignee.
 *
 * @coversDefaultClass \OCA\OpenRegister\Listener\AssigneeFollowListener
 */
class AssigneeFollowListenerAccessGoldenTest extends TestCase {

	/**
	 * Run one reassignment from piet to jan and record every call made.
	 *
	 * @param bool $allowed What the read check answers.
	 *
	 * @return array<int, array<int, mixed>> The recorded calls.
	 */
	private function reassign(bool $allowed): array {
		$calls = [];
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(['assignee' => ['type' => 'string', 'x-openregister-role' => 'assignee']]);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function (...$args) use (&$calls, $schema): Schema {
				$calls[] = ['find', $args[0], $args[2], $args[3]];
				return $schema;
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['jan', 'piet'], true));

		$permissions = $this->createMock(PermissionHandler::class);
		$permissions->method('hasPermission')->willReturnCallback(
			static function (...$args) use (&$calls, $allowed, $schema): bool {
				$calls[] = ['hasPermission', $args[0] === $schema, $args[1], $args[2], $args[3], $args[4], $args[5]->getUuid(), ($args[6] ?? null)];
				return $allowed;
			}
		);

		$watchers = $this->createMock(WatcherService::class);
		$watchers->method('followAssigned')->willReturnCallback(
			static function (...$args) use (&$calls): void {
				$calls[] = ['followAssigned', $args[0]->getUuid(), $args[1], $args[2], $args[3]];
			}
		);

		$listener = new AssigneeFollowListener(
			$schemaMapper,
			new AssigneeFollowServices(new SemanticRoleHandler(), $users, $watchers),
			$permissions,
			$this->createMock(LoggerInterface::class)
		);
		$listener->handle(new ObjectUpdatedEvent($this->object(assignee: 'jan'), $this->object(assignee: 'piet')));

		return $calls;

	}//end reassign()

	/**
	 * An object owned by alice with the given assignee.
	 *
	 * @param string $assignee The assignee value.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $assignee): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('uuid-case-1');
		$object->setSchema('12');
		$object->setRegister('3');
		$object->setOwner('alice');
		$object->setObject(['assignee' => $assignee]);

		return $object;

	}//end object()

	/**
	 * The expected read question.
	 *
	 * @return array<int, mixed> The recorded hasPermission call.
	 */
	private static function readQuestion(): array {
		return [
			'hasPermission',
			true, 'read', 'jan', 'alice', true, 'uuid-case-1', null,
		];

	}//end readQuestion()

	/**
	 * An allowed assignee is asked about, then followed.
	 *
	 * @return void
	 */
	public function testAllowedAssigneeIsAskedThenFollowed(): void {
		$this->assertSame(
			[
				['find', '12', false, false],
				self::readQuestion(),
				['followAssigned', 'uuid-case-1', 'jan', '3', '12'],
			],
			$this->reassign(allowed: true)
		);

	}//end testAllowedAssigneeIsAskedThenFollowed()

	/**
	 * A refused assignee is asked the same question, and not followed.
	 *
	 * @return void
	 */
	public function testRefusedAssigneeIsAskedAndNotFollowed(): void {
		$this->assertSame(
			[
				['find', '12', false, false],
				self::readQuestion(),
			],
			$this->reassign(allowed: false)
		);

	}//end testRefusedAssigneeIsAskedAndNotFollowed()
}//end class
