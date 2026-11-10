<?php

/**
 * OpenRegister StateFieldRuleRecordGoldenTest
 *
 * Characterisation of what StateFieldRuleListener writes to the rule run log
 * for a refused save, and that the log never changes the refusal itself.
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
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\StateFieldRuleListener;
use OCA\OpenRegister\Listener\Support\StateFieldRulePlatform;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluatorInterface;
use OCA\OpenRegister\Service\Lifecycle\StateFieldLists;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolverInterface;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;
use OCA\OpenRegister\Service\Rules\RuleDescriptor;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;
use OCA\OpenRegister\Service\Rules\RuleRunSubject;
use OCA\OpenRegister\Service\Rules\RuleTrace;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Golden run-log rows of a refused state field rule.
 */
class StateFieldRuleRecordGoldenTest extends TestCase {

	/**
	 * Every row recorded, as [subject, trace].
	 *
	 * @var array<int, array{0: RuleRunSubject, 1: RuleTrace}>
	 */
	private array $rows = [];

	/**
	 * Run the listener once and answer [stopped, errors].
	 *
	 * @param array<string,mixed>|null $old       The stored data, or null for a create.
	 * @param array<string,mixed>      $new       The saved data.
	 * @param array<string,mixed>      $setup     Keys: slug, condition, throws.
	 *
	 * @return array{0: bool, 1: array<string,mixed>}
	 */
	private function save(?array $old, array $new, array $setup): array {
		$schema = new Schema();
		$schema->setSlug($setup['slug']);
		$schemas = $this->createStub(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		$open = new StateFieldRules(state: 'open', fields: new StateFieldLists(hidden: ['secret'], readOnly: ['ref'], required: ['name']), messages: ['ref' => 'Ref is fixed.']);
		$resolver = $this->createStub(StateFieldRuleResolverInterface::class);
		$resolver->method('annotationOf')->willReturn(['field' => 'status', 'states' => []]);
		$resolver->method('stateOf')->willReturnCallback(static fn (array $annotation, array $data): ?string => ($data['status'] ?? null));
		$resolver->method('resolveFromAnnotation')->willReturnCallback(
			static fn (array $annotation, array $data, ?string $state=null): StateFieldRules => ($state === 'open' ? $open : new StateFieldRules())
		);
		$conditions = $this->createStub(StateConditionEvaluatorInterface::class);
		$conditions->method('refusal')->willReturn($setup['condition'] ?? null);

		$recorder = $this->createStub(RuleRunRecorderInterface::class);
		$recorder->method('record')->willReturnCallback(
			function (RuleRunSubject $subject, RuleTrace $trace) use ($setup): void {
				if (($setup['throws'] ?? false) === true) {
					throw new RuntimeException('log down');
				}

				$this->rows[] = [$subject, $trace];
			}
		);

		$listener = new StateFieldRuleListener(new StateFieldRulePlatform($schemas, $recorder, new NullLogger()), $resolver, $conditions);

		$newObject = new ObjectEntity();
		$newObject->setUuid('u-1');
		$newObject->setRegister('5');
		$newObject->setSchema('7');
		$newObject->setObject($new);
		$event = new ObjectCreatingEvent($newObject);
		if ($old !== null) {
			$oldObject = new ObjectEntity();
			$oldObject->setObject($old);
			$event = new ObjectUpdatingEvent($newObject, $oldObject);
		}

		$listener->handle($event);
		return [$event->isPropagationStopped(), $event->getErrors()];
	}//end save()

	/**
	 * A read-only refusal records one refused row against the state's rule.
	 *
	 * @return void
	 */
	public function testARefusalRecordsItsStateFieldAndMessage(): void {
		$result = $this->save(['status' => 'open', 'ref' => 'a'], ['status' => 'open', 'ref' => 'b', 'name' => 'n'], ['slug' => 'zaak']);

		self::assertSame([true, ['code' => 'state-field-read-only', 'field' => 'ref', 'state' => 'open', 'message' => 'Ref is fixed.']], $result);
		self::assertCount(1, $this->rows);
		[$subject, $trace] = $this->rows[0];
		self::assertSame(RuleDescriptor::idFor(kind: RuleVocabulary::KIND_STATE_FIELD_RULE, schemaSlug: 'zaak', key: 'open'), $subject->ruleId);
		self::assertSame('zaak', $subject->schemaSlug);
		self::assertSame('u-1', $subject->objectUuid);
		self::assertSame('5', $subject->registerSlug);
		self::assertSame(RuleVocabulary::VERDICT_REFUSED, $trace->getVerdict());
		self::assertSame('ref', $trace->getOperand());
		self::assertSame('Ref is fixed.', $trace->getMessage());
	}//end testARefusalRecordsItsStateFieldAndMessage()

	/**
	 * A required refusal on create records the end state's rule.
	 *
	 * @return void
	 */
	public function testACreateRefusalRecordsTheEndState(): void {
		$result = $this->save(null, ['status' => 'open'], ['slug' => 'zaak']);

		self::assertSame([true, ['code' => 'state-field-required', 'field' => 'name', 'state' => 'open', 'message' => 'Field "name" is required in state "open".']], $result);
		self::assertCount(1, $this->rows);
		self::assertSame('name', $this->rows[0][1]->getOperand());
	}//end testACreateRefusalRecordsTheEndState()

	/**
	 * A condition refusal names its clause; one without a state is not recorded.
	 *
	 * @return void
	 */
	public function testAConditionRefusalNamesItsClause(): void {
		$condition = ['code' => 'condition', 'state' => 'done', 'clause' => 'outcome', 'message' => 'm'];
		self::assertSame([true, $condition], $this->save(['status' => 'open'], ['status' => 'done'], ['slug' => 'zaak', 'condition' => $condition]));
		self::assertSame('outcome', $this->rows[0][1]->getOperand());
		self::assertSame(RuleDescriptor::idFor(kind: RuleVocabulary::KIND_STATE_FIELD_RULE, schemaSlug: 'zaak', key: 'done'), $this->rows[0][0]->ruleId);

		$this->rows = [];
		$stateless = ['code' => 'condition'];
		self::assertSame([true, $stateless], $this->save(['status' => 'open'], ['status' => 'done'], ['slug' => 'zaak', 'condition' => $stateless]));
		self::assertSame([], $this->rows);
	}//end testAConditionRefusalNamesItsClause()

	/**
	 * Without a slug nothing is recorded, and a failing log keeps the refusal.
	 *
	 * @return void
	 */
	public function testTheLogNeverChangesTheRefusal(): void {
		$refusal = [true, ['code' => 'state-field-hidden', 'field' => 'secret', 'state' => 'open', 'message' => 'Field "secret" cannot be written in state "open".']];

		self::assertSame($refusal, $this->save(['status' => 'open'], ['status' => 'open', 'secret' => 'x', 'name' => 'n'], ['slug' => '']));
		self::assertSame([], $this->rows);

		self::assertSame($refusal, $this->save(['status' => 'open'], ['status' => 'open', 'secret' => 'x', 'name' => 'n'], ['slug' => 'zaak', 'throws' => true]));
		self::assertSame([], $this->rows);
	}//end testTheLogNeverChangesTheRefusal()

	/**
	 * An allowed save records nothing.
	 *
	 * @return void
	 */
	public function testAnAllowedSaveRecordsNothing(): void {
		self::assertSame([false, []], $this->save(['status' => 'open', 'ref' => 'a'], ['status' => 'open', 'ref' => 'a', 'name' => 'n'], ['slug' => 'zaak']));
		self::assertSame([], $this->rows);
	}//end testAnAllowedSaveRecordsNothing()
}//end class
