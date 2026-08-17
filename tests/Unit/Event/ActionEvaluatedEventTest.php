<?php

/**
 * The payload an audit listener actually reads.
 *
 * A listener records whatever this event carries, so a getter returning the
 * wrong thing writes the wrong thing into an audit trail — where it will be
 * believed. These assertions are the contract.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace Unit\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ActionEvaluatedEvent;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \OCA\OpenRegister\Event\ActionEvaluatedEvent
 */
class ActionEvaluatedEventTest extends TestCase {

	/**
	 * Build a schema.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('invoice');

		return $schema;
	}//end schema()

	/**
	 * Build an object.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('00000000-0000-0000-0000-000000000000');
		$object->setRegister('4');
		$object->setObject([]);

		return $object;
	}//end object()

	/**
	 * A granted decision, with an object, carries the whole payload.
	 *
	 * @covers ::__construct
	 * @covers ::getSchema
	 * @covers ::getAction
	 * @covers ::getActor
	 * @covers ::getObject
	 * @covers ::getObjectId
	 * @covers ::getRegister
	 * @covers ::isGranted
	 * @covers ::isRefused
	 *
	 * @return void
	 */
	public function testAGrantedDecisionCarriesEveryField(): void {
		$schema = $this->schema();
		$object = $this->object();

		$event = new ActionEvaluatedEvent(
			schema: $schema,
			action: 'sendMail',
			granted: true,
			actor: 'alice',
			object: $object
		);

		$this->assertSame($schema, $event->getSchema());
		$this->assertSame('sendMail', $event->getAction());
		$this->assertSame('alice', $event->getActor());
		$this->assertSame($object, $event->getObject());
		$this->assertSame('00000000-0000-0000-0000-000000000000', $event->getObjectId());
		$this->assertSame('4', $event->getRegister());
		$this->assertTrue($event->isGranted());
		$this->assertFalse($event->isRefused());
	}//end testAGrantedDecisionCarriesEveryField()

	/**
	 * 🔴 A refusal reports itself as one. `isRefused()` exists so a listener
	 * cannot subscribe to only the flattering half by accident, and it must
	 * never disagree with `isGranted()`.
	 *
	 * @covers ::isGranted
	 * @covers ::isRefused
	 *
	 * @return void
	 */
	public function testARefusalReportsItselfConsistently(): void {
		$event = new ActionEvaluatedEvent(
			schema: $this->schema(),
			action: 'delete',
			granted: false,
			actor: 'mallory'
		);

		$this->assertTrue($event->isRefused());
		$this->assertFalse($event->isGranted());
		$this->assertSame('mallory', $event->getActor());
	}//end testARefusalReportsItselfConsistently()

	/**
	 * ⚠️ Without an object there is no single register — a schema can belong to
	 * several — so null is the honest answer rather than a guess a listener
	 * could not tell apart from a fact.
	 *
	 * @covers ::getRegister
	 * @covers ::getObject
	 * @covers ::getObjectId
	 *
	 * @return void
	 */
	public function testASchemaLevelDecisionReportsNulls(): void {
		$event = new ActionEvaluatedEvent(
			schema: $this->schema(),
			action: 'read',
			granted: true,
			actor: 'alice'
		);

		$this->assertNull($event->getObject());
		$this->assertNull($event->getObjectId());
		$this->assertNull($event->getRegister());
	}//end testASchemaLevelDecisionReportsNulls()

	/**
	 * An anonymous principal is a null actor, not an empty string. A listener
	 * writing `""` into an audit column cannot distinguish "nobody was logged
	 * in" from "a user with a blank name".
	 *
	 * @covers ::getActor
	 *
	 * @return void
	 */
	public function testAnAnonymousActorIsNull(): void {
		$event = new ActionEvaluatedEvent(
			schema: $this->schema(),
			action: 'read',
			granted: false,
			actor: null
		);

		$this->assertNull($event->getActor());
	}//end testAnAnonymousActorIsNull()
}//end class
