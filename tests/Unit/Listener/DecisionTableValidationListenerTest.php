<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\DecisionTableValidationListener;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Service\Dmn\DecisionTableStoreTest;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * A stored decision table is refused at save when the engine cannot run it.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
class DecisionTableValidationListenerTest extends TestCase {

	private function listener(string $slug, array $sameKey = [], ?string $registerSlug = 'decision-tables'): DecisionTableValidationListener {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsBySlug')->willReturn($sameKey);

		$registers = $this->createMock(RegisterMapper::class);
		if ($registerSlug === null) {
			$registers->method('find')->willThrowException(new DoesNotExistException('no register'));
		} else {
			$register = new Register();
			$register->setSlug($registerSlug);
			$registers->method('find')->willReturn($register);
		}

		return new DecisionTableValidationListener($schemas, new DecisionTableValidator(), $objects, $registers);
	}

	private function row(array $data, string $uuid = 'u-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('7');
		$object->setRegister('3');
		$object->setObject($data);
		return $object;
	}

	public function testAnExecutableTableIsSaved(): void {
		$event = new ObjectCreatingEvent($this->row(DecisionTableStoreTest::shippingTable()));
		$this->listener('decision-table')->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}

	public function testATableTheEngineCannotRunIsRefused(): void {
		$table = DecisionTableStoreTest::shippingTable();
		$table['rules'][0]['inputEntries'] = [];
		$event = new ObjectCreatingEvent($this->row($table));
		$this->listener('decision-table')->handle($event);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(DecisionTableValidationListener::ERROR_CODE, $event->getErrors()['code']);
	}

	public function testASecondTableUnderTheSameKeyIsRefused(): void {
		$other = $this->row(DecisionTableStoreTest::shippingTable(), 'u-2');
		$event = new ObjectCreatingEvent($this->row(DecisionTableStoreTest::shippingTable()));
		$this->listener('decision-table', [$other])->handle($event);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('already used by decision table u-2', $event->getErrors()['message']);
	}

	/**
	 * A key clash that cannot be checked is not "no clash": the save is refused, since a duplicate key lets the
	 * store pick either table at random.
	 *
	 * @return void
	 */
	public function testAKeyThatCannotBeCheckedRefusesTheSave(): void {
		$schema = new Schema();
		$schema->setSlug('decision-table');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsBySlug')->willThrowException(new \RuntimeException('search is down'));
		$register = new Register();
		$register->setSlug('decision-tables');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$listener = new DecisionTableValidationListener($schemas, new DecisionTableValidator(), $objects, $registers);

		$event = new ObjectCreatingEvent($this->row(DecisionTableStoreTest::shippingTable()));
		$listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(DecisionTableValidationListener::ERROR_CODE, $event->getErrors()['code']);
		$this->assertStringContainsString('the key "parcel-carrier" could not be checked', $event->getErrors()['message']);
	}//end testAKeyThatCannotBeCheckedRefusesTheSave()

	/**
	 * A schema or register that cannot be READ (as opposed to one that does not exist) cannot be told from the
	 * decision-table one, so the save is refused instead of waved through unvalidated.
	 *
	 * @return void
	 */
	public function testASchemaOrRegisterThatCannotBeReadRefusesTheSave(): void {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willThrowException(new \RuntimeException('database is down'));
		$listener = new DecisionTableValidationListener(
			$schemas,
			new DecisionTableValidator(),
			$this->createMock(ObjectService::class),
			$this->createMock(RegisterMapper::class)
		);
		$event = new ObjectCreatingEvent($this->row(['anything' => true]));
		$listener->handle($event);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('could not be checked against the decision-table rules', $event->getErrors()['message']);

		$schema = new Schema();
		$schema->setSlug('decision-table');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willThrowException(new \RuntimeException('database is down'));
		$listener = new DecisionTableValidationListener($schemas, new DecisionTableValidator(), $this->createMock(ObjectService::class), $registers);
		$event = new ObjectCreatingEvent($this->row(DecisionTableStoreTest::shippingTable()));
		$listener->handle($event);
		$this->assertTrue($event->isPropagationStopped());
	}//end testASchemaOrRegisterThatCannotBeReadRefusesTheSave()

	public function testUpdatingATableKeepsItsOwnKey(): void {
		$self  = $this->row(DecisionTableStoreTest::shippingTable());
		$event = new ObjectUpdatingEvent($this->row(DecisionTableStoreTest::shippingTable()), $self);
		$this->listener('decision-table', [$self])->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}

	public function testOtherSchemasAreLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->row(['anything' => true]));
		$this->listener('invoice')->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * A schema of the same slug in another app's register is not OpenRegister's
	 * table: its rows are left alone (buildiq's own `decision-table` until it moved).
	 *
	 * @return void
	 */
	public function testTheSameSlugInAnotherRegisterIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->row(['ruleSetId' => 'loan', 'hitPolicy' => 'first']));
		$this->listener('decision-table', [], 'buildiq')->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * A row whose register cannot be resolved is left alone.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRegisterIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->row(['anything' => true]));
		$this->listener('decision-table', [], null)->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}

	/**
	 * A row without a schema is left alone.
	 *
	 * @return void
	 */
	public function testARowWithoutASchemaIsLeftAlone(): void {
		$object = new ObjectEntity();
		$object->setUuid('u-9');
		$object->setObject(['anything' => true]);
		$event = new ObjectCreatingEvent($object);
		$this->listener('decision-table')->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}
}
