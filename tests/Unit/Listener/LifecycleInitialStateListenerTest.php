<?php

/**
 * OpenRegister LifecycleInitialStateListenerTest
 *
 * Tests the create-time lifecycle initial-state listener: the static-initial
 * behaviour (unchanged) plus the new dynamic-initial resolution from a related
 * object, and the empty-only guard.
 *
 * @category Test
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

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Listener\LifecycleInitialStateListener;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Listener\LifecycleInitialStateListener
 * @uses \OCA\OpenRegister\Listener\LifecycleCreate
 * @uses \OCA\OpenRegister\Listener\LifecycleDeclaration
 * @uses \OCA\OpenRegister\Listener\LifecycleEditReader
 * @uses \OCA\OpenRegister\Listener\LifecycleInitialReference
 */
class LifecycleInitialStateListenerTest extends TestCase {

	/** @var SchemaMapper&MockObject */
	private $schemaMapper;

	/** @var ReferenceResolver&MockObject */
	private $references;

	/** @var LoggerInterface&MockObject */
	private $logger;

	private LifecycleInitialStateListener $listener;

	/**
	 * Wire the listener with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->references = $this->createMock(ReferenceResolver::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new LifecycleInitialStateListener(
			new \OCA\OpenRegister\Listener\LifecycleEditReader(new \OCA\OpenRegister\Service\Lifecycle\SchemaReading($this->schemaMapper, $this->logger), new \OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolver(new \OCA\OpenRegister\Service\Lifecycle\LifecycleActionContext())),
			$this->references,
			$this->logger
		);

	}//end setUp()

	/**
	 * Build a schema carrying the given configuration block.
	 *
	 * @param array<string, mixed> $config The schema configuration.
	 *
	 * @return Schema
	 */
	private function schema(array $config): Schema {
		$schema = new Schema();
		$schema->setConfiguration($config);
		return $schema;
	}//end schema()

	/**
	 * Build an object bound to schema "s1" / register "r1" with the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema('s1');
		$object->setRegister('r1');
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * Static initial still works: empty status field is set to "open".
	 *
	 * @return void
	 */
	public function testStaticInitialIsApplied(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'open']])
		);

		$object = $this->object(['title' => 'x']);
		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertSame('open', $object->getObject()['status']);

	}//end testStaticInitialIsApplied()

	/**
	 * A caller-supplied value is never overridden.
	 *
	 * @return void
	 */
	public function testACreateInAnotherStateIsRefused(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'open']])
		);
		// A static-initial path must not consult the reference resolver.
		$this->references->expects($this->never())->method('resolveAll');

		$object = $this->object(['status' => 'closed']);
		$event = new ObjectCreatingEvent($object);
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('lifecycle-invalid-initial', $event->getErrors()['code']);
		$this->assertSame('closed', $event->getErrors()['attempted']);
		$this->assertSame('closed', $object->getObject()['status']);

	}//end testACreateInAnotherStateIsRefused()

	public function testACreateInTheInitialStatePasses(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'open']])
		);

		$event = new ObjectCreatingEvent($this->object(['status' => 'open']));
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());

	}//end testACreateInTheInitialStatePasses()

	public function testTheSystemMayCreateInAnyState(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'open']])
		);

		$event = new ObjectCreatingEvent($this->object(['status' => 'closed']));
		\OCA\OpenRegister\Service\SystemOperationContext::run(fn () => $this->listener->handle($event));

		$this->assertFalse($event->isPropagationStopped());

		$after = new ObjectCreatingEvent($this->object(['status' => 'closed']));
		$this->listener->handle($after);
		$this->assertTrue($after->isPropagationStopped(), 'outside the system operation the same create is refused');

	}//end testTheSystemMayCreateInAnyState()

	public function testThePropertyAliasNamesTheField(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['x-openregister-lifecycle' => ['property' => 'status', 'initial' => 'open']])
		);

		$event = new ObjectCreatingEvent($this->object(['status' => 'closed']));
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());

	}//end testThePropertyAliasNamesTheField()

	/**
	 * Dynamic initial resolves the state from a related object (dict form).
	 *
	 * @return void
	 */
	public function testDynamicInitialFromReferenceDict(): void {
		$config = [
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
			],
			'x-openregister-references' => [
				'caseType' => ['schema' => 'ct', 'mode' => 'relatedObject', 'field' => 'caseTypeId'],
			],
		];
		$this->schemaMapper->method('find')->willReturn($this->schema($config));

		$this->references->expects($this->once())
			->method('resolveAll')
			->willReturn(['caseType' => ['initialStatus' => 'open']]);

		$object = $this->object(['caseTypeId' => 'ct-1']);
		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertSame('open', $object->getObject()['status']);

	}//end testDynamicInitialFromReferenceDict()

	/**
	 * Dynamic initial also accepts the "@ref.<ref>.<field>" token form.
	 *
	 * @return void
	 */
	public function testDynamicInitialFromRefToken(): void {
		$config = [
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => '@ref.caseType.initialStatus',
			],
			'x-openregister-references' => [
				'caseType' => ['schema' => 'ct', 'mode' => 'relatedObject', 'field' => 'caseTypeId'],
			],
		];
		$this->schemaMapper->method('find')->willReturn($this->schema($config));

		$this->references->method('resolveAll')->willReturn(['caseType' => ['initialStatus' => 'intake']]);

		$object = $this->object(['caseTypeId' => 'ct-1']);
		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertSame('intake', $object->getObject()['status']);

	}//end testDynamicInitialFromRefToken()

	/**
	 * An unresolvable dynamic reference is a logged no-op (field stays unset).
	 *
	 * @return void
	 */
	public function testADynamicInitialIsComparedWithTheCallersValue(): void {
		$config = [
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => '@ref.caseType.initialStatus',
			],
			'x-openregister-references' => [
				'caseType' => ['schema' => 'ct', 'mode' => 'relatedObject', 'field' => 'caseTypeId'],
			],
		];
		$this->schemaMapper->method('find')->willReturn($this->schema($config));
		$this->references->method('resolveAll')->willReturn(['caseType' => ['initialStatus' => 'intake']]);

		$refused = new ObjectCreatingEvent($this->object(['caseTypeId' => 'ct-1', 'status' => 'closed']));
		$this->listener->handle($refused);
		$this->assertTrue($refused->isPropagationStopped());
		$this->assertSame('intake', $refused->getErrors()['initial']);

		$allowed = new ObjectCreatingEvent($this->object(['caseTypeId' => 'ct-1', 'status' => 'intake']));
		$this->listener->handle($allowed);
		$this->assertFalse($allowed->isPropagationStopped());

	}//end testADynamicInitialIsComparedWithTheCallersValue()

	public function testAnUnresolvableInitialRefusesACallersValue(): void {
		$config = [
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
			],
			'x-openregister-references' => [
				'caseType' => ['schema' => 'ct', 'mode' => 'relatedObject', 'field' => 'caseTypeId'],
			],
		];
		$this->schemaMapper->method('find')->willReturn($this->schema($config));
		$this->references->method('resolveAll')->willReturn(['caseType' => null]);

		$event = new ObjectCreatingEvent($this->object(['caseTypeId' => 'missing', 'status' => 'closed']));
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('lifecycle-invalid-initial', $event->getErrors()['code']);

	}//end testAnUnresolvableInitialRefusesACallersValue()

	public function testUnresolvableReferenceIsNoOp(): void {
		$config = [
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
			],
			'x-openregister-references' => [
				'caseType' => ['schema' => 'ct', 'mode' => 'relatedObject', 'field' => 'caseTypeId'],
			],
		];
		$this->schemaMapper->method('find')->willReturn($this->schema($config));
		$this->references->method('resolveAll')->willReturn(['caseType' => null]);

		$object = $this->object(['caseTypeId' => 'missing']);
		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertArrayNotHasKey('status', $object->getObject());

	}//end testUnresolvableReferenceIsNoOp()

	/**
	 * A reference name not declared on the schema is a logged no-op.
	 *
	 * @return void
	 */
	public function testUndeclaredReferenceIsNoOp(): void {
		$config = [
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => ['from' => 'ghost', 'field' => 'initialStatus'],
			],
		];
		$this->schemaMapper->method('find')->willReturn($this->schema($config));
		$this->references->expects($this->never())->method('resolveAll');

		$object = $this->object(['caseTypeId' => 'ct-1']);
		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertArrayNotHasKey('status', $object->getObject());

	}//end testUndeclaredReferenceIsNoOp()

	/**
	 * No lifecycle annotation → completely unaffected.
	 *
	 * @return void
	 */
	public function testNoLifecycleAnnotationIsNoOp(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema([]));

		$object = $this->object(['title' => 'x']);
		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertArrayNotHasKey('status', $object->getObject());

	}//end testNoLifecycleAnnotationIsNoOp()

	public function testADeclaredCreateStateMayBeChosen(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'open', 'createStates' => ['published', 'archived']]])
		);

		$published = new ObjectCreatingEvent($this->object(['status' => 'published']));
		$this->listener->handle($published);
		$this->assertFalse($published->isPropagationStopped());

		$closed = new ObjectCreatingEvent($this->object(['status' => 'closed']));
		$this->listener->handle($closed);
		$this->assertTrue($closed->isPropagationStopped());
		$this->assertSame(['published', 'archived'], $closed->getErrors()['allowed']);

	}//end testADeclaredCreateStateMayBeChosen()

	public function testACreateStateCountsWhenTheInitialCannotBeResolved(): void {
		$config = [
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'initial' => ['from' => 'caseType', 'field' => 'initialStatus'],
				'createStates' => ['imported'],
			],
			'x-openregister-references' => [
				'caseType' => ['schema' => 'ct', 'mode' => 'relatedObject', 'field' => 'caseTypeId'],
			],
		];
		$this->schemaMapper->method('find')->willReturn($this->schema($config));
		$this->references->method('resolveAll')->willReturn(['caseType' => null]);

		$event = new ObjectCreatingEvent($this->object(['caseTypeId' => 'missing', 'status' => 'imported']));
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());

	}//end testACreateStateCountsWhenTheInitialCannotBeResolved()

	public function testAValueOnlyLooselyEqualToTheInitialIsRefused(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'open', 'createStates' => ['published']]])
		);

		$event = new ObjectCreatingEvent($this->object(['status' => true]));
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());

	}//end testAValueOnlyLooselyEqualToTheInitialIsRefused()

	public function testAMalformedCreateStatesListAllowsNothingExtra(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'open', 'createStates' => 'published']])
		);

		$event = new ObjectCreatingEvent($this->object(['status' => 'published']));
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());

	}//end testAMalformedCreateStatesListAllowsNothingExtra()

	public function testAHistoricImportKeepsTheState(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'open']])
		);

		$event = new ObjectCreatingEvent($this->object(['status' => 'closed']));
		\OCA\OpenRegister\Service\Lifecycle\KeepLifecycleStatesScope::run(fn () => $this->listener->handle($event));

		$this->assertFalse($event->isPropagationStopped());

	}//end testAHistoricImportKeepsTheState()

	public function testAMapOfCreateStatesAllowsNothingExtra(): void {
		$this->schemaMapper->method('find')->willReturn(
			$this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'initial' => 'open', 'createStates' => ['first' => 'published']]])
		);

		$event = new ObjectCreatingEvent($this->object(['status' => 'published']));
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors()['allowed']);

	}//end testAMapOfCreateStatesAllowsNothingExtra()

}//end class
