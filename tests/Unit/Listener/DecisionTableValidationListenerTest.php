<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\DecisionTableValidationListener;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Service\Dmn\DecisionTableStoreTest;
use PHPUnit\Framework\TestCase;

/**
 * A stored decision table is refused at save when the engine cannot run it.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
class DecisionTableValidationListenerTest extends TestCase {

	private function listener(string $slug, array $sameKey = []): DecisionTableValidationListener {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsBySlug')->willReturn($sameKey);

		return new DecisionTableValidationListener($schemas, new DecisionTableValidator(), $objects);
	}

	private function row(array $data, string $uuid = 'u-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema('7');
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
}
