<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableStore;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNode;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Service\Dmn\DecisionTableStoreTest;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * The decision-table step with a stored table named by tableRef.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
class DecisionTableNodeStoredTableTest extends TestCase {

	private function node(bool $withStore = true): DecisionTableNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);
		$urls = $this->createMock(IURLGenerator::class);

		$entity = new ObjectEntity();
		$entity->setUuid('u-1');
		$entity->setObject(DecisionTableStoreTest::shippingTable());
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsBySlug')->willReturn([$entity]);

		$store = null;
		if ($withStore === true) {
			$store = new DecisionTableStore($objects, new DecisionTableValidator(), new DecisionTableEvaluator());
		}

		return new DecisionTableNode(
			engine: new DecisionTableEvaluator(),
			validator: new DecisionTableValidator(),
			l10n: $l10n,
			urls: $urls,
			store: $store,
		);
	}

	public function testAStepDecidesWithAStoredTable(): void {
		$out = $this->node()->execute(
			items: [FlowItems::item(json: ['parcel' => ['kg' => 7]])],
			config: [
				'tableRef' => 'parcel-carrier',
				'inputMapping' => ['weight' => 'parcel.kg'],
				'resultKey' => 'decision',
			],
			context: []
		);

		$json = $out[0][FlowItems::JSON];
		$this->assertSame('freight', $json['carrier']);
		$this->assertSame(['heavy'], $json['decision']['matchedRuleIds']);
		$this->assertSame('parcel-carrier', $json['decision']['tableKey']);
	}

	public function testAStoredTableIsAcceptedAtSave(): void {
		$this->node()->validateConfig(['tableRef' => 'u-1', 'outputMapping' => ['carrier' => 'shipping.carrier']]);
		$this->addToAssertionCount(1);
	}

	public function testAnUnknownReferenceIsRefusedAtSave(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('No decision table has the key or id "missing"');
		$this->node()->validateConfig(['tableRef' => 'missing']);
	}

	public function testAMappingTheStoredTableDoesNotDeclareIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node()->validateConfig(['tableRef' => 'parcel-carrier', 'inputMapping' => ['volume' => 'parcel.m3']]);
	}

	public function testBothAnInlineAndAStoredTableAreRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('not both');
		$this->node()->validateConfig(['tableRef' => 'parcel-carrier', 'table' => DecisionTableStoreTest::shippingTable()]);
	}

	public function testNeitherIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('needs a table');
		$this->node()->validateConfig([]);
	}

	public function testWithoutAStoreAReferenceIsRefusedNotIgnored(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('not available');
		$this->node(withStore: false)->validateConfig(['tableRef' => 'parcel-carrier']);
	}
}
