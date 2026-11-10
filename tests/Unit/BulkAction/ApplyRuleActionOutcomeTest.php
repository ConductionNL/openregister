<?php

/**
 * Pins every outcome ApplyRuleAction::apply() gives, with its message.
 *
 * Covers the branches ApplyRuleActionTest leaves open: a rule that is no
 * longer declared, a switched-off rule, an evaluator failure, a write that
 * throws (logged), and the actor and object the write is made with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BulkAction;

use OCA\OpenRegister\BulkAction\ApplyRuleAction;
use OCA\OpenRegister\BulkAction\BulkObjectAccess;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Rules\ApplyRuleServices;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleEffect;
use OCA\OpenRegister\Service\Rules\RuleInventoryServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleOrigin;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Service\Object\ObjectPatch;

class ApplyRuleActionOutcomeTest extends TestCase {

	private ObjectOperationsInterface&MockObject $objects;

	private RuleInventoryServiceInterface&MockObject $inventory;

	private CalculationEvaluatorInterface&MockObject $evaluator;

	private LoggerInterface&MockObject $logger;

	private ApplyRuleAction $action;

	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->inventory = $this->createMock(RuleInventoryServiceInterface::class);
		$this->evaluator = $this->createMock(CalculationEvaluatorInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());
		$this->action = new ApplyRuleAction(
			new ApplyRuleServices($this->inventory, $this->evaluator),
			new BulkObjectAccess($this->objects, $schemas, $this->logger)
		);
	}//end setUp()

	/**
	 * A rule on the key "total".
	 *
	 * @param string $kind    The rule kind.
	 * @param bool   $enabled Whether it is switched on.
	 *
	 * @return RuleDescriptor
	 */
	private function rule(string $kind, bool $enabled=true): RuleDescriptor {
		return new RuleDescriptor(origin: new RuleOrigin(kind: $kind, schemaSlug: 'orders', key: 'total', source: 'schema'), label: 'Total', effect: new RuleEffect(actions: [], condition: ['+' => [1, 2]]), enabled: $enabled);
	}//end rule()

	/**
	 * Object o-1 on register 3, schema 7.
	 *
	 * @param mixed $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function object(mixed $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('o-1');
		$object->setRegister('3');
		$object->setSchema('7');
		$object->setObject($data);
		return $object;
	}//end object()

	public function testARuleNoLongerDeclaredFailsNamingIt(): void {
		$this->inventory->method('find')->willReturn(null);
		$result = $this->action->apply(step: new BulkStep(object: $this->object([]), parameters: ['schema' => 'orders', 'ruleId' => 'r-9'], commit: true, actor: null));
		$this->assertSame(BulkJobMember::OUTCOME_FAILED, $result->getOutcome());
		$this->assertSame('The rule "r-9" is no longer declared.', $result->getReason());
	}//end testARuleNoLongerDeclaredFailsNamingIt()

	public function testANonCalculationRuleIsSkippedNamingItsKind(): void {
		$this->inventory->method('find')->willReturn($this->rule(RuleVocabulary::KIND_STATE_FIELD_RULE));
		$this->evaluator->expects($this->never())->method('evaluate');
		$result = $this->action->apply(step: new BulkStep(object: $this->object([]), parameters: ['schema' => 'orders', 'ruleId' => 'r-1'], commit: true, actor: null));
		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertStringContainsString('A rule of kind "' . RuleVocabulary::KIND_STATE_FIELD_RULE . '" derives no value', (string)$result->getReason());
	}//end testANonCalculationRuleIsSkippedNamingItsKind()

	public function testASwitchedOffRuleIsSkippedBeforeEvaluating(): void {
		$this->inventory->method('find')->willReturn($this->rule(RuleVocabulary::KIND_CALCULATION, false));
		$this->evaluator->expects($this->never())->method('evaluate');
		$this->objects->expects($this->never())->method('patchObject');
		$result = $this->action->apply(step: new BulkStep(object: $this->object([]), parameters: ['schema' => 'orders', 'ruleId' => 'r-1'], commit: true, actor: null));
		$this->assertSame(BulkJobMember::OUTCOME_SKIPPED, $result->getOutcome());
		$this->assertSame('The rule is switched off.', $result->getReason());
	}//end testASwitchedOffRuleIsSkippedBeforeEvaluating()

	public function testAnEvaluatorFailureFailsTheMemberWithItsMessage(): void {
		$this->inventory->method('find')->willReturn($this->rule(RuleVocabulary::KIND_CALCULATION));
		$this->evaluator->method('evaluate')->willThrowException(new RuntimeException('division by zero'));
		$this->objects->expects($this->never())->method('patchObject');
		$result = $this->action->apply(step: new BulkStep(object: $this->object(['total' => 1]), parameters: ['schema' => 'orders', 'ruleId' => 'r-1'], commit: true, actor: null));
		$this->assertSame(BulkJobMember::OUTCOME_FAILED, $result->getOutcome());
		$this->assertSame('division by zero', $result->getReason());
	}//end testAnEvaluatorFailureFailsTheMemberWithItsMessage()

	public function testTheCommitWritesAsTheJobsActorOnTheObjectsRegisterAndSchema(): void {
		$this->inventory->method('find')->willReturn($this->rule(RuleVocabulary::KIND_CALCULATION));
		$this->evaluator->method('evaluate')->willReturn(3);
		$actor = $this->createMock(IUser::class);
		$seen = [];
		$this->objects->expects($this->once())->method('patchObject')->willReturnCallback(
			function (ObjectPatch $patch, bool $_rbac = true, bool $_multitenancy = true) use (&$seen): ObjectEntity {
				$args = [$patch->objectId, $patch->data, $patch->target->register, $patch->target->schema, $_rbac, $_multitenancy, $patch->currentUser];
				$seen = $args;
				return new ObjectEntity();
			}
		);
		$result = $this->action->apply(step: new BulkStep(object: $this->object(['total' => 1]), parameters: ['schema' => 'orders', 'ruleId' => 'r-1'], commit: true, actor: $actor));
		$this->assertSame(BulkJobMember::OUTCOME_APPLIED, $result->getOutcome(), (string)$result->getReason());
		// patchObject(objectId, data, register, schema, _rbac, _multitenancy, currentUser).
		$this->assertSame(['o-1', ['total' => 3], '3', '7', true, true, $actor], $seen);
	}//end testTheCommitWritesAsTheJobsActorOnTheObjectsRegisterAndSchema()

	public function testAWriteThatThrowsFailsTheMemberAndIsLogged(): void {
		$this->inventory->method('find')->willReturn($this->rule(RuleVocabulary::KIND_CALCULATION));
		$this->evaluator->method('evaluate')->willReturn(3);
		$this->objects->method('patchObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('warning')
			->with('[ApplyRuleAction] Write failed for object', ['action' => ApplyRuleAction::ID, 'object' => 'o-1', 'error' => 'locked']);
		$result = $this->action->apply(step: new BulkStep(object: $this->object(['total' => 1]), parameters: ['schema' => 'orders', 'ruleId' => 'r-1'], commit: true, actor: null));
		$this->assertSame(BulkJobMember::OUTCOME_FAILED, $result->getOutcome());
		$this->assertSame('locked', $result->getReason());
	}//end testAWriteThatThrowsFailsTheMemberAndIsLogged()
}//end class
