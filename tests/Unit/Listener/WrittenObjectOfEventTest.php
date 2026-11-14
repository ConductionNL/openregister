<?php

/**
 * Tests for how three listeners find the written object on an event.
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
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Listener\AggregationCacheInvalidationListener;
use OCA\OpenRegister\Listener\AnnotationNotificationListener;
use OCA\OpenRegister\Listener\FacetCacheInvalidationListener;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleDispatcherInterface;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationCacheInterface;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\OpenRegister\Service\Object\FacetCacheVersion;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Each listener takes the object from getObject(), else from getNewObject(), else none.
 *
 * @covers \OCA\OpenRegister\Listener\AggregationCacheInvalidationListener
 * @covers \OCA\OpenRegister\Listener\AnnotationNotificationListener
 * @uses   \OCA\OpenRegister\Listener\NotificationTrigger
 * @covers \OCA\OpenRegister\Listener\FacetCacheInvalidationListener
 * @covers \OCA\OpenRegister\Listener\WrittenObjectOfEvent
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Event\ObjectCreatedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectDeletedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectTransitionedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatedEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef
 * @uses \OCA\OpenRegister\Service\Lifecycle\TransitionActor
 * @uses \OCA\OpenRegister\Service\Lifecycle\TransitionMove
 * @uses   \OCA\OpenRegister\Service\Deferral\DeferralOptions
 */
class WrittenObjectOfEventTest extends TestCase {

	/**
	 * The three listeners.
	 *
	 * @return array<string, object>
	 */
	private function listeners(): array {
		return [
			'facet' => new FacetCacheInvalidationListener($this->createMock(FacetCacheVersion::class)),
			'aggregation' => new AggregationCacheInvalidationListener($this->createMock(AggregationCacheInterface::class)),
			'notification' => new AnnotationNotificationListener(
				$this->createMock(NotificationRuleDispatcherInterface::class),
				$this->createMock(SchemaMapperInterface::class),
				$this->createMock(ListenerDeferralService::class)
			),
		];
	}

	/**
	 * Every listener finds the same object, or none, on each kind of event.
	 *
	 * @return void
	 */
	public function testEachListenerFindsTheWrittenObject(): void {
		$object = new ObjectEntity();
		$old = new ObjectEntity();
		$odd = new class extends Event {
			/**
			 * Not an object.
			 *
			 * @return string
			 */
			public function getObject(): string {
				return 'x';
			}
		};
		$both = new class ($object, $old) extends Event {
			/**
			 * Hold two objects.
			 *
			 * @param ObjectEntity $first Returned by getObject().
			 * @param ObjectEntity $second Returned by getNewObject().
			 */
			public function __construct(private ObjectEntity $first, private ObjectEntity $second) {
				parent::__construct();
			}

			/**
			 * The first object.
			 *
			 * @return ObjectEntity
			 */
			public function getObject(): ObjectEntity {
				return $this->first;
			}

			/**
			 * The second object.
			 *
			 * @return ObjectEntity
			 */
			public function getNewObject(): ObjectEntity {
				return $this->second;
			}
		};
		$fallsThrough = new class ($object) extends Event {
			/**
			 * Hold the post-write object.
			 *
			 * @param ObjectEntity $after Returned by getNewObject().
			 */
			public function __construct(private ObjectEntity $after) {
				parent::__construct();
			}

			/**
			 * Not an object.
			 *
			 * @return string
			 */
			public function getObject(): string {
				return 'x';
			}

			/**
			 * The post-write object.
			 *
			 * @return ObjectEntity
			 */
			public function getNewObject(): ObjectEntity {
				return $this->after;
			}
		};
		$events = [
			'getObject not an object, getNewObject is' => [$fallsThrough, $object],
			'updating' => [new ObjectUpdatingEvent($object, $old), $object],
			'both getters' => [$both, $object],
			'created' => [new ObjectCreatedEvent($object), $object],
			'updated' => [new ObjectUpdatedEvent($object, $old), $object],
			'deleted' => [new ObjectDeletedEvent($object), $object],
			'transitioned' => [new ObjectTransitionedEvent(object: $object, move: new TransitionMove(action: 'close', from: 'open', to: 'closed'), scope: new RegisterSchemaRef(register: 'r', schema: 's'), actor: new TransitionActor(userId: 'u')), $object],
			'plain' => [new Event(), null],
			'not an object' => [$odd, null],
		];
		foreach ($this->listeners() as $name => $listener) {
			$extract = new \ReflectionMethod($listener, 'extractObject');
			foreach ($events as $kind => [$event, $expected]) {
				$this->assertSame($expected, $extract->invoke($listener, $event), "$name / $kind");
			}
		}
	}
}
