<?php

/**
 * The post-save listener records, and does nothing else.
 *
 * A transition applied inside `ObjectUpdatedEvent` would be lost to the second
 * write a file-bearing save makes, and would invert event order for a named
 * transition. So the listener notes the object and the pass decides later.
 * These tests pin down what is recorded and what is not: a schema declaring no
 * `autoWhen` costs one lookup and nothing more, two events for one object
 * collapse into one decision, and the first `previous` of the pass is the one
 * that survives.
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
 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AutoTransitionRecordListener;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPass;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionRunner;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelector;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Recording written objects into the pass.
 */
class AutoTransitionRecordListenerTest extends TestCase {

	private const AUTO_WHEN = ['!!' => ['var' => 'object.motivering']];

	private SchemaMapper&MockObject $schemaMapper;

	private AutoTransitionRunner&MockObject $runner;

	private AutoTransitionPass $pass;

	private AutoTransitionRecordListener $listener;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->runner = $this->createMock(AutoTransitionRunner::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->pass = new AutoTransitionPass($this->runner, $logger);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn([]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');
		$selector = new AutoTransitionSelector(
			new LifecycleConditionEvaluator(
				$this->createMock(IUserSession::class),
				$groupManager,
				$l10n,
				$logger
			),
			$logger
		);

		$this->listener = new AutoTransitionRecordListener($this->schemaMapper, $selector, $this->pass);
	}//end setUp()

	/**
	 * Make the mapper answer with a schema carrying (or not) an `autoWhen`.
	 *
	 * @param bool $declaresAutoWhen Whether the transition carries `autoWhen`.
	 *
	 * @return void
	 */
	private function schemaDeclaring(bool $declaresAutoWhen): void {
		$transition = ['from' => ['in-behandeling'], 'to' => 'besloten'];
		if ($declaresAutoWhen === true) {
			$transition['autoWhen'] = self::AUTO_WHEN;
		}

		$schema = new Schema();
		$schema->setSlug('bezwaar');
		$schema->setConfiguration(
			[
				'x-openregister-lifecycle' => [
					'field' => 'status',
					'initial' => 'in-behandeling',
					'transitions' => ['beslissen' => $transition],
				],
			]
		);

		$this->schemaMapper->method('find')->willReturn($schema);
	}//end schemaDeclaring()

	/**
	 * An object entity carrying the given data.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-1');
		$entity->setRegister('1');
		$entity->setSchema('2');
		$entity->setObject($data);

		return $entity;
	}//end object()

	/**
	 * @return void
	 */
	public function testTheListenerRecordsAndNeverDecidesOrApplies(): void {
		// 🔴 THE LISTENER'S ONE JOB, AND IT WAS UNGUARDED.
		// A mutation that made the listener drain immediately after recording
		// reddened nothing, because every test here only asserted WHAT gets
		// recorded. The listener must not decide or apply: at this point the
		// triggering write is unfinished, so a move made here would be
		// overwritten by a file-bearing save's second update and would run
		// before the audit row exists.
		$this->schemaDeclaring(true);
		$this->runner->expects($this->never())->method('decide');
		$this->runner->expects($this->never())->method('fire');
		$this->runner->expects($this->never())->method('flushQueued');

		$this->pass->enter();
		$this->listener->handle(new ObjectUpdatedEvent($this->object(['status' => 'in-behandeling']), null));
	}//end testTheListenerRecordsAndNeverDecidesOrApplies()

	/**
	 * @return void
	 */
	public function testASchemaWithoutAutoWhenRecordsNothing(): void {
		$this->schemaDeclaring(false);
		$this->runner->expects($this->never())->method('decide');

		$this->pass->enter();
		$this->listener->handle(new ObjectUpdatedEvent($this->object(['status' => 'in-behandeling']), null));
		$this->pass->leave();
	}//end testASchemaWithoutAutoWhenRecordsNothing()

	/**
	 * @return void
	 */
	public function testTwoEventsForOneObjectRecordOnceWithTheFirstPrevious(): void {
		// A file-bearing save dispatches ObjectUpdatedEvent twice; the second
		// carries the object it just wrote as `old`. The pass must decide once,
		// against the state before the write that STARTED it.
		$this->schemaDeclaring(true);

		$this->runner->expects($this->once())
			->method('decide')
			->with(
				uuid: 'uuid-1',
				register: '1',
				schema: '2',
				previous: ['status' => 'ontvangen', 'id' => 'uuid-1']
			)
			->willReturn(null);

		$this->pass->enter();
		$this->listener->handle(
			new ObjectUpdatedEvent(
				$this->object(['status' => 'in-behandeling']),
				$this->object(['status' => 'ontvangen'])
			)
		);
		$this->listener->handle(
			new ObjectUpdatedEvent(
				$this->object(['status' => 'in-behandeling', 'bijlage' => 'f-1']),
				$this->object(['status' => 'in-behandeling'])
			)
		);
		$this->pass->leave();
	}//end testTwoEventsForOneObjectRecordOnceWithTheFirstPrevious()

	/**
	 * @return void
	 */
	public function testACreateRecordsAnEmptyPrevious(): void {
		$this->schemaDeclaring(true);

		$this->runner->expects($this->once())
			->method('decide')
			->with(uuid: 'uuid-1', register: '1', schema: '2', previous: [])
			->willReturn(null);

		$this->pass->enter();
		$this->listener->handle(new ObjectCreatedEvent($this->object(['status' => 'in-behandeling'])));
		$this->pass->leave();
	}//end testACreateRecordsAnEmptyPrevious()

	/**
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$this->runner->expects($this->never())->method('decide');

		$this->pass->enter();
		$this->listener->handle(new \OCP\EventDispatcher\Event());
		$this->pass->leave();
	}//end testAnUnrelatedEventIsIgnored()
}//end class
