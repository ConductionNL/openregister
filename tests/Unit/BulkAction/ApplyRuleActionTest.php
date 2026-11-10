<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BulkAction
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BulkAction;

use OCA\OpenRegister\BulkAction\BulkObjectAccess;
use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\ApplyRuleAction;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleInventoryServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Rules\ApplyRuleServices;
use OCA\OpenRegister\Service\Object\ObjectPatch;

/**
 * Pins what the apply-rule bulk action validates, skips, previews and writes.
 */
class ApplyRuleActionTest extends TestCase {

	/**
	 * The write path.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The rule lookup.
	 *
	 * @var RuleInventoryServiceInterface&MockObject
	 */
	private RuleInventoryServiceInterface&MockObject $inventory;

	/**
	 * The calculation evaluator.
	 *
	 * @var CalculationEvaluatorInterface&MockObject
	 */
	private CalculationEvaluatorInterface&MockObject $evaluator;

	/**
	 * System under test.
	 *
	 * @var ApplyRuleAction
	 */
	private ApplyRuleAction $action;

	/**
	 * Build the action over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->inventory = $this->createMock(RuleInventoryServiceInterface::class);
		$this->evaluator = $this->createMock(CalculationEvaluatorInterface::class);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());
		$this->action = new ApplyRuleAction(
			new ApplyRuleServices($this->inventory, $this->evaluator),
			new BulkObjectAccess($this->objects, $schemas, $this->createMock(LoggerInterface::class))
		);
	}//end setUp()

	/**
	 * A calculation rule on the key "total".
	 *
	 * @param string $kind    The rule kind.
	 * @param bool   $enabled Whether the rule is on.
	 *
	 * @return RuleDescriptor
	 */
	private function rule(string $kind, bool $enabled=true): RuleDescriptor {
		return new RuleDescriptor(origin: new \OCA\OpenRegister\Service\Rules\RuleOrigin(kind: $kind, schemaSlug: 'orders', key: 'total', source: 'schema'), label: 'Total', effect: new \OCA\OpenRegister\Service\Rules\RuleEffect(actions: [], condition: ['+' => [1, 2]]), enabled: $enabled);
	}//end rule()

	/**
	 * An object carrying the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * Missing parameters are refused.
	 *
	 * @return void
	 */
	public function testMissingParametersAreRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->action->validateParameters(['schema' => 'orders']);
	}//end testMissingParametersAreRefused()

	/**
	 * A rule the schema does not declare is refused.
	 *
	 * @return void
	 */
	public function testAnUndeclaredRuleIsRefused(): void {
		$this->inventory->method('find')->willReturn(null);
		$this->expectException(InvalidArgumentException::class);
		$this->action->validateParameters(['schema' => 'orders', 'ruleId' => 'r-1']);
	}//end testAnUndeclaredRuleIsRefused()

	/**
	 * A non-calculation rule is skipped and nothing is written.
	 *
	 * @return void
	 */
	public function testANonCalculationRuleIsSkipped(): void {
		$this->inventory->method('find')->willReturn($this->rule(RuleVocabulary::KIND_STATE_FIELD_RULE));
		$this->objects->expects($this->never())->method('patchObject');
		$result = $this->action->apply(step: new BulkStep(object: $this->object([]), parameters: ['schema' => 'orders', 'ruleId' => 'r-1'], commit: true, actor: null));
		$this->assertSame(BulkActionResult::skipped('x')->getOutcome(), $result->getOutcome());
	}//end testANonCalculationRuleIsSkipped()

	/**
	 * An object already carrying the derived value is skipped.
	 *
	 * @return void
	 */
	public function testAnAgreeingObjectIsSkipped(): void {
		$this->inventory->method('find')->willReturn($this->rule(RuleVocabulary::KIND_CALCULATION));
		$this->evaluator->method('evaluate')->willReturn(3);
		$this->objects->expects($this->never())->method('patchObject');
		$result = $this->action->apply(step: new BulkStep(object: $this->object(['total' => 3]), parameters: ['schema' => 'orders', 'ruleId' => 'r-1'], commit: true, actor: null));
		$this->assertFalse($result->isApplied());
	}//end testAnAgreeingObjectIsSkipped()

	/**
	 * A dry run reports applied without writing.
	 *
	 * @return void
	 */
	public function testADryRunWritesNothing(): void {
		$this->inventory->method('find')->willReturn($this->rule(RuleVocabulary::KIND_CALCULATION));
		$this->evaluator->method('evaluate')->willReturn(3);
		$this->objects->expects($this->never())->method('patchObject');
		$result = $this->action->apply(step: new BulkStep(object: $this->object(['total' => 1]), parameters: ['schema' => 'orders', 'ruleId' => 'r-1'], commit: false, actor: null));
		$this->assertTrue($result->isApplied());
	}//end testADryRunWritesNothing()

	/**
	 * A commit writes the derived value onto the object.
	 *
	 * @return void
	 */
	public function testACommitWritesTheDerivedValue(): void {
		$this->inventory->method('find')->willReturn($this->rule(RuleVocabulary::KIND_CALCULATION));
		$this->evaluator->method('evaluate')->willReturn(3);
		$this->objects->expects($this->once())->method('patchObject')
			->with($this->callback(static fn (ObjectPatch $patch): bool => $patch->objectId === 'o-1' && $patch->data === ['total' => 3]));
		$result = $this->action->apply(step: new BulkStep(object: $this->object(['total' => 1]), parameters: ['schema' => 'orders', 'ruleId' => 'r-1'], commit: true, actor: null));
		$this->assertTrue($result->isApplied());
	}//end testACommitWritesTheDerivedValue()
}//end class
