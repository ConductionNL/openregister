<?php

/**
 * Golden test for what the annotation notification listener dispatches or defers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
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

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Listener\AnnotationNotificationListener;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleDispatcherInterface;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Records every dispatch() and defer() for each event, schema state and deferral
 * setting, against a golden recorded before the listener was split.
 *
 * @covers \OCA\OpenRegister\Listener\AnnotationNotificationListener
 * @uses   \OCA\OpenRegister\Listener\NotificationTrigger
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectDeletedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectTransitionedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatedEvent
 */
class AnnotationNotificationListenerGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/Golden/annotation-notification-listener.json';

	/**
	 * An object on schema $schema with the given data.
	 *
	 * @param string $schema The schema reference.
	 * @param array  $data   The object data.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $schema, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setRegister('7');
		$object->setSchema($schema);
		$object->setVersion('1.2.0');
		$object->setObject($data);
		return $object;
	}

	/**
	 * Every event to replay, built around one object.
	 *
	 * @param string $schema The schema reference of the object.
	 *
	 * @return array<string, object>
	 */
	private function events(string $schema): array {
		$new = $this->object(schema: $schema, data: ['status' => 'closed']);
		$old = $this->object(schema: $schema, data: ['status' => 'open']);
		$unset = $this->object(schema: $schema, data: ['status' => 'x']);
		$unset->setObject(null);
		return [
			'created' => new ObjectCreatedEvent($new),
			'updated' => new ObjectUpdatedEvent($new, $old),
			'updated without old' => new ObjectUpdatedEvent($new),
			'updated, old has no data' => new ObjectUpdatedEvent($new, $unset),
			'transitioned' => new ObjectTransitionedEvent(object: $new, move: new TransitionMove(action: 'close', from: 'open', to: 'closed'), scope: new RegisterSchemaRef(register: '7', schema: $schema), actor: new TransitionActor(userId: 'u')),
			'deleted' => new ObjectDeletedEvent($new),
		];
	}

	/**
	 * The listener behaves as recorded in every combination.
	 *
	 * @return void
	 */
	public function testListenerMatchesTheGolden(): void {
		$actual = [];
		foreach (['declares' => 'with', 'declares none' => 'none', 'schema lookup fails' => 'boom', 'no schema' => ''] as $state => $schemaRef) {
			foreach (['inline' => false, 'deferred' => true] as $mode => $deferral) {
				foreach ($this->events(schema: $schemaRef) as $name => $event) {
					$calls = [];
					$dispatcher = $this->createMock(NotificationRuleDispatcherInterface::class);
					$dispatcher->method('dispatch')->willReturnCallback(function (...$args) use (&$calls) {
						$calls[] = 'dispatch ' . json_encode([$args[0]->getUuid(), ...array_slice($args, 1)]);
					});
					$deferrals = $this->createMock(ListenerDeferralService::class);
					$deferrals->method('isDeferralEnabled')->willReturn($deferral);
					$deferrals->method('defer')->willReturnCallback(function (...$args) use (&$calls) {
						$calls[] = 'defer ' . json_encode($args, JSON_UNESCAPED_SLASHES);
					});
					$schemas = $this->createMock(SchemaMapperInterface::class);
					$schemas->method('find')->willReturnCallback(function ($ref) {
						if ($ref === 'boom') {
							throw new RuntimeException('no schema');
						}

						$schema = new Schema();
						$schema->setConfiguration($ref === 'with' ? ['x-openregister-notifications' => ['r' => []]] : ['x-openregister-notifications' => []]);
						return $schema;
					});
					(new AnnotationNotificationListener($dispatcher, $schemas, $deferrals))->handle($event);
					$actual["$state / $mode / $name"] = $calls;
				}
			}
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}
}
