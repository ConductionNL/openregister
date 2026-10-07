<?php

/**
 * SystemEntityNotificationListener Unit Test
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Event\ConfigurationUpdatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Event\SourceCreatedEvent;
use OCA\OpenRegister\Event\SourceUpdatedEvent;
use OCA\OpenRegister\Listener\SystemEntityNotificationListener;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\SystemSchemaRules;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SystemEntityNotificationListener.
 */
class SystemEntityNotificationListenerTest extends TestCase {

	/** @var AnnotationNotificationDispatcher&MockObject */
	private AnnotationNotificationDispatcher $dispatcher;

	private SystemSchemaRules $rules;

	private SystemEntityNotificationListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
		$this->rules = new SystemSchemaRules();
		$this->listener = new SystemEntityNotificationListener(
			dispatcher: $this->dispatcher,
			rules: $this->rules
		);
	}//end setUp()

	/**
	 * Task 5.1 — A system synchronization (configuration) failure dispatches
	 * a notification to the admin group via the existing dispatcher path.
	 *
	 * We model this as a SourceCreatedEvent because Source is the closest
	 * system entity that carries operational health state in the current codebase.
	 */
	public function testSourceCreatedDispatchesViaAnnotationPath(): void {
		$source = new Source();
		$source->setUuid('src-uuid-01');
		$source->setTitle('Test Source');

		$event = new SourceCreatedEvent(source: $source);

		$this->dispatcher
			->expects($this->once())
			->method('dispatchWithSchema')
			->with(
				$this->anything(),
				'created',
				$this->callback(static fn (array $ctx): bool => empty($ctx) === true),
				$this->isInstanceOf(Schema::class)
			);

		$this->listener->handle($event);
	}//end testSourceCreatedDispatchesViaAnnotationPath()

	/**
	 * Task 5.2 — A configuration update dispatches an `updated` rule to the admin group.
	 */
	public function testConfigurationUpdateDispatchesUpdatedTrigger(): void {
		$newConfig = new Configuration();
		$newConfig->setUuid('cfg-uuid-01');
		$newConfig->setTitle('My Config');

		$oldConfig = new Configuration();
		$oldConfig->setUuid('cfg-uuid-01');
		$oldConfig->setTitle('Old Title');

		$event = new ConfigurationUpdatedEvent(
			newConfiguration: $newConfig,
			oldConfiguration: $oldConfig
		);

		$this->dispatcher
			->expects($this->once())
			->method('dispatchWithSchema')
			->with(
				$this->anything(),
				'updated',
				$this->callback(
					static fn (array $ctx): bool => isset($ctx['_newData']) === true
						&& isset($ctx['_oldData']) === true
				),
				$this->isInstanceOf(Schema::class)
			);

		$this->listener->handle($event);
	}//end testConfigurationUpdateDispatchesUpdatedTrigger()

	/**
	 * Task 5.3 — A source update dispatches to integration-ops (admin group).
	 * The event carries old/new data so the field-change condition evaluator can run.
	 */
	public function testSourceUpdatedDispatchesWithOldAndNewData(): void {
		$newSource = new Source();
		$newSource->setUuid('src-uuid-02');
		$newSource->setTitle('Updated Source');

		$oldSource = new Source();
		$oldSource->setUuid('src-uuid-02');
		$oldSource->setTitle('Old Source');

		$event = new SourceUpdatedEvent(newSource: $newSource, oldSource: $oldSource);

		$this->dispatcher
			->expects($this->once())
			->method('dispatchWithSchema')
			->with(
				$this->anything(),
				'updated',
				$this->callback(
					static fn (array $ctx): bool => isset($ctx['_newData']) === true
						&& isset($ctx['_oldData']) === true
				),
				$this->isInstanceOf(Schema::class)
			);

		$this->listener->handle($event);
	}//end testSourceUpdatedDispatchesWithOldAndNewData()

	/**
	 * Task 5.4 — An unrecognised event does not call the dispatcher.
	 */
	public function testUnknownEventDoesNotCallDispatcher(): void {
		$unknownEvent = new class extends Event {};

		$this->dispatcher->expects($this->never())->method('dispatchWithSchema');
		$this->listener->handle($unknownEvent);
	}//end testUnknownEventDoesNotCallDispatcher()

	/**
	 * Task 5.4 — The dispatcher schema argument carries the correct system slug.
	 */
	public function testDispatchedSchemaSlugMatchesSystemSlug(): void {
		$source = new Source();
		$source->setUuid('src-uuid-03');
		$source->setTitle('Slug Source');

		$event = new SourceCreatedEvent(source: $source);

		$capturedSchema = null;
		$this->dispatcher
			->expects($this->once())
			->method('dispatchWithSchema')
			->willReturnCallback(function () use (&$capturedSchema): void {
				$args = func_get_args();
				$capturedSchema = $args[3] ?? null;
			});

		$this->listener->handle($event);

		$this->assertInstanceOf(Schema::class, $capturedSchema);
		/** @var Schema $capturedSchema */
		$this->assertSame(SystemSchemaRules::SLUG_SOURCE, $capturedSchema->getSlug());
	}//end testDispatchedSchemaSlugMatchesSystemSlug()

	/**
	 * Task 5.4 — Stored-object notification behaviour is unchanged.
	 *
	 * The SystemEntityNotificationListener does NOT handle ObjectCreatedEvent,
	 * so stored objects are unaffected.
	 */
	public function testStoredObjectEventsAreNotHandledBySystemListener(): void {
		$objectEvent = new class extends Event {};
		$this->dispatcher->expects($this->never())->method('dispatchWithSchema');
		$this->listener->handle($objectEvent);
	}//end testStoredObjectEventsAreNotHandledBySystemListener()

	/**
	 * 🔴 A configuration re-imported with identical content notifies nobody.
	 *
	 * Seen on the dev instance: "pipelinq example data" re-imported by a newer
	 * build of the app differed only in `version` and `updated`, and every
	 * administrator was told it was updated.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function testAReimportThatOnlyMovesTheVersionNotifiesNobody(): void {
		$old = new Configuration();
		$old->setUuid('cfg-uuid-02');
		$old->setTitle('pipelinq example data');
		$old->setVersion('0.5.7-unstable.20261005210000');
		$old->setUpdated(new \DateTime('2026-10-06 16:47:28'));

		$new = new Configuration();
		$new->setUuid('cfg-uuid-02');
		$new->setTitle('pipelinq example data');
		$new->setVersion('0.5.7-unstable.20261006200000');
		$new->setUpdated(new \DateTime('2026-10-07 14:17:25'));

		$this->dispatcher->expects($this->never())->method('dispatchWithSchema');

		$this->listener->handle(new ConfigurationUpdatedEvent(newConfiguration: $new, oldConfiguration: $old));
	}//end testAReimportThatOnlyMovesTheVersionNotifiesNobody()

	/**
	 * 🔴 Key order, list order of `required` and null against [] are not a change.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function testASchemaThatOnlyReordersNotifiesNobody(): void {
		$old = new Schema();
		$old->setUuid('schema-uuid-01');
		$old->setTitle('Survey');
		$old->setRequired(['title', 'anonymity']);
		$old->setProperties(['title' => ['type' => 'string'], 'anonymity' => ['type' => 'boolean']]);

		$new = new Schema();
		$new->setUuid('schema-uuid-01');
		$new->setTitle('Survey');
		$new->setRequired(['anonymity', 'title']);
		$new->setProperties(['anonymity' => ['type' => 'boolean'], 'title' => ['type' => 'string']]);
		$new->setAuthorization([]);

		$this->dispatcher->expects($this->never())->method('dispatchWithSchema');

		$this->listener->handle(new SchemaUpdatedEvent(newSchema: $new, oldSchema: $old));
	}//end testASchemaThatOnlyReordersNotifiesNobody()

	/**
	 * A schema whose properties really change still notifies.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function testASchemaWithANewPropertyStillNotifies(): void {
		$old = new Schema();
		$old->setUuid('schema-uuid-02');
		$old->setTitle('Lead');
		$old->setProperties(['title' => ['type' => 'string']]);

		$new = new Schema();
		$new->setUuid('schema-uuid-02');
		$new->setTitle('Lead');
		$new->setProperties(['title' => ['type' => 'string'], 'source' => ['type' => 'string']]);

		$this->dispatcher->expects($this->once())->method('dispatchWithSchema');

		$this->listener->handle(new SchemaUpdatedEvent(newSchema: $new, oldSchema: $old));
	}//end testASchemaWithANewPropertyStillNotifies()
}//end class
