<?php

/**
 * A soft delete is never refused as a uniqueness breach.
 *
 * Soft delete writes the deletion marker through the mapper's update(), so it
 * dispatches ObjectUpdatingEvent. Once a schema's declared constraints
 * survived save, UniqueConstraintListener refused that update for any record
 * whose key a second record also holds, deleteObject() swallowed the refusal
 * and the endpoint answered 500. Those duplicates (left from before the
 * constraint was declared) are exactly the records somebody needs to delete.
 * Seen in CI's Newman run of the upsert collection (run 37230164290).
 *
 * The listener, the evaluator and the event are real; only the schema and
 * object mappers are doubles.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\UniqueConstraintListener;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Listener\UniqueConstraintListener
 */
class UniqueConstraintListenerDeleteTest extends TestCase {

	/**
	 * The real listener over a schema that refuses a duplicated key, with a twin holding it.
	 *
	 * @return UniqueConstraintListener
	 */
	private function listener(): UniqueConstraintListener {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setConfiguration(
			['uniqueConstraints' => [['name' => 'zaaksleutel', 'properties' => ['zaaknummer'], 'action' => 'refuse']]]
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$twin = new ObjectEntity();
		$twin->setUuid('twin-uuid');
		$objects = $this->createMock(MagicMapper::class);
		$objects->method('searchObjects')->willReturn([$twin]);

		return new UniqueConstraintListener(
			evaluator: new UniqueConstraintEvaluator(),
			schemas: $schemas,
			objects: $objects,
			logger: new NullLogger()
		);
	}//end listener()

	/**
	 * A record holding the duplicated key.
	 *
	 * @return ObjectEntity
	 */
	private function duplicate(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('own-uuid');
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setObject(['zaaknummer' => 'Z-DUP']);

		return $object;
	}//end duplicate()

	/**
	 * Soft-deleting a record whose key a twin also holds is not refused.
	 */
	public function testASoftDeleteIsNotRefused(): void {
		$old = $this->duplicate();
		$new = $this->duplicate();
		$new->setDeleted(['deleted' => '2026-10-04T20:00:00+00:00', 'deletedBy' => 'admin']);
		$event = new ObjectUpdatingEvent(newObject: $new, oldObject: $old);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testASoftDeleteIsNotRefused()

	/**
	 * CONTROL: a live update of the same record is still refused, so the test above is not passing on a dead listener.
	 */
	public function testALiveUpdateOfADuplicateIsStillRefused(): void {
		$event = new ObjectUpdatingEvent(newObject: $this->duplicate(), oldObject: $this->duplicate());

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(UniqueConstraintListener::ERROR_CODE, $event->getErrors()['code'] ?? null);
	}//end testALiveUpdateOfADuplicateIsStillRefused()
}//end class
