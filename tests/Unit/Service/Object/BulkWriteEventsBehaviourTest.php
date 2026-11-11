<?php

/**
 * BulkWriteEvents: when bulk writes fire pre-write events, and what a listener's answer does to the row.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Object\BulkSafeguardRun;
use OCA\OpenRegister\Service\Object\BulkWriteEvents;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * The opt-in precedence and the screening of one row.
 *
 * @covers \OCA\OpenRegister\Service\Object\BulkWriteEvents
 * @uses \OCA\OpenRegister\Service\Object\BulkSafeguardRun
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Register\RegisterSchemaIds
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses \OCA\OpenRegister\Db\JsonArrayColumn
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses \OCA\OpenRegister\Event\ObjectUpdatingEvent
 */
class BulkWriteEventsBehaviourTest extends TestCase {

	/**
	 * The service with an instance setting and an optional listener.
	 *
	 * @param string        $instance The app-config value.
	 * @param callable|null $listener What the listener does with the event.
	 *
	 * @return BulkWriteEvents
	 */
	private function events(string $instance = '', ?callable $listener = null): BulkWriteEvents {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('openregister', 'fire_events_on_bulk_writes', '')->willReturn($instance);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event) use ($listener): void {
				if ($listener !== null) {
					$listener($event);
				}
			}
		);

		return new BulkWriteEvents($dispatcher, $config);
	}//end events()

	/**
	 * A schema with a configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema
	 */
	private function schema(array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * A register with a configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Register
	 */
	private function register(array $configuration = []): Register {
		$register = new Register();
		$register->setId(3);
		$register->setConfiguration($configuration);

		return $register;
	}//end register()

	/**
	 * The schema wins over the register, the register over the instance, the instance over the lifecycle default.
	 *
	 * @return void
	 */
	public function testThePrecedenceOfTheOptIn(): void {
		$on = [BulkWriteEvents::CONFIG_KEY => true];
		$off = [BulkWriteEvents::CONFIG_KEY => false];
		$lifecycle = ['x-openregister-lifecycle' => ['states' => []]];

		$this->assertFalse($this->events(instance: 'true')->appliesTo($this->schema(configuration: $off), $this->register(configuration: $on)));
		$this->assertTrue($this->events(instance: 'false')->appliesTo($this->schema(), $this->register(configuration: $on)));
		$this->assertFalse($this->events(instance: 'true')->appliesTo($this->schema(), $this->register(configuration: $off)));
		$this->assertTrue($this->events(instance: 'true')->appliesTo($this->schema(), null));
		$this->assertFalse($this->events(instance: 'false')->appliesTo($this->schema(configuration: $lifecycle), null));
		$this->assertTrue($this->events()->appliesTo($this->schema(configuration: $lifecycle + [BulkWriteEvents::CONFIG_KEY => 'yes']), null));
		$this->assertFalse($this->events(instance: 'maybe')->appliesTo($this->schema(), $this->register()));
	}//end testThePrecedenceOfTheOptIn()

	/**
	 * A new row fires a creating event on an owned, uuid-stamped entity; the listener's changes are merged and @self kept.
	 *
	 * @return void
	 */
	public function testANewRowFiresCreatingAndTakesTheListenersChanges(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('carol');
		$run = new BulkSafeguardRun($user);
		$run->rowSchema = $this->schema();
		$run->rowRegister = $this->register();
		$seen = null;
		$events = $this->events(
			listener: function (Event $event) use (&$seen): void {
				$seen = $event;
				$event->setModifiedData(['title' => 'Cleaned']);
			}
		);

		[$row, $denial] = $events->screen(['title' => 'raw', 'n' => 1, '@self' => ['register' => 3]], $run);

		$this->assertInstanceOf(ObjectCreatingEvent::class, $seen);
		$entity = $seen->getObject();
		$this->assertSame('carol', $entity->getOwner());
		$this->assertSame('7', $entity->getSchema());
		$this->assertSame('3', $entity->getRegister());
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$run->rowUuid);
		$this->assertSame($run->rowUuid, $entity->getUuid());
		$this->assertNull($denial);
		$this->assertSame('Cleaned', $row['title']);
		$this->assertSame(1, $row['n']);
		$this->assertSame(['register' => 3], $row['@self']);
	}//end testANewRowFiresCreatingAndTakesTheListenersChanges()

	/**
	 * An existing row fires an updating event on a copy of the stored object, keeps its uuid and owner, and has no @self added.
	 *
	 * @return void
	 */
	public function testAnExistingRowFiresUpdatingOnACopy(): void {
		$existing = new ObjectEntity();
		$existing->setOwner('dave');
		$run = new BulkSafeguardRun(null);
		$run->rowUuid = 'u-9';
		$run->rowExisting = $existing;
		$run->rowSchema = $this->schema();
		$seen = null;
		$events = $this->events(
			listener: function (Event $event) use (&$seen): void {
				$seen = $event;
			}
		);

		[$row, $denial] = $events->screen(['title' => 'new'], $run);

		$this->assertInstanceOf(ObjectUpdatingEvent::class, $seen);
		$this->assertSame($existing, $seen->getOldObject());
		$this->assertNotSame($existing, $seen->getNewObject());
		$this->assertSame('dave', $seen->getNewObject()->getOwner());
		$this->assertSame('u-9', $seen->getNewObject()->getUuid());
		$this->assertSame('', (string)$seen->getNewObject()->getRegister());
		$this->assertNull($denial);
		$this->assertArrayNotHasKey('@self', $row);
		$this->assertSame('new', $row['title']);
	}//end testAnExistingRowFiresUpdatingOnACopy()

	/**
	 * A stopped event returns the row untouched with the listener's message, or the default denial for the action.
	 *
	 * @return void
	 */
	public function testAStoppedEventDeniesTheRow(): void {
		$stopWith = fn (array $errors): callable => function (Event $event) use ($errors): void {
			$event->setErrors($errors);
			$event->stopPropagation();
		};
		$row = ['title' => 'raw', '@self' => ['x' => 1]];
		$create = new BulkSafeguardRun(null);
		$create->rowSchema = $this->schema();
		$update = new BulkSafeguardRun(null);
		$update->rowSchema = $this->schema();
		$update->rowExisting = new ObjectEntity();

		$this->assertSame([$row, 'Title is locked'], $this->events(listener: $stopWith(['message' => 'Title is locked']))->screen($row, $create));
		$this->assertSame([$row, 'Object creation rejected by hook'], $this->events(listener: $stopWith([]))->screen($row, $create));
		$this->assertSame([$row, 'Object update rejected by hook'], $this->events(listener: $stopWith([]))->screen($row, $update));
	}//end testAStoppedEventDeniesTheRow()
}//end class
