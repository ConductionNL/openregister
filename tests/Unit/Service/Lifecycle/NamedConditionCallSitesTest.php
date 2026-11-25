<?php

/**
 * The lifecycle, state and field-rule evaluation points resolve `$condition`
 * against the schema's own library, and refuse when they cannot (task 1.2b).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\LifecycleConditionReporting;
use OCA\OpenRegister\Service\Lifecycle\LifecycleRuleCaller;
use OCA\OpenRegister\Service\Lifecycle\LifecycleRuleCheck;
use OCA\OpenRegister\Service\Lifecycle\LifecycleWrite;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluator;
use OCA\OpenRegister\Service\Lifecycle\StateCrossing;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Lifecycle\StateSave;
use OCA\OpenRegister\Service\Lifecycle\TransitionAttempt;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\NamedConditionScope;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Named conditions at the three evaluation points that used to call the dialect directly.
 */
class NamedConditionCallSitesTest extends TestCase {

	/**
	 * The schema's library: one condition, reading the amount.
	 *
	 * @var array<string, mixed>
	 */
	private const LIBRARY = [
		'groot' => ['description' => 'Large amount', 'expression' => ['>' => [['var' => 'object.bedrag'], 500]]],
	];

	private ConditionDialect $dialect;
	private IUserSession $session;
	private IGroupManager $groups;

	protected function setUp(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->session->method('getUser')->willReturn(null);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('getUserGroupIds')->willReturn([]);
		$this->dialect = new ConditionDialect(new CalculationEvaluator(new PlaceholderResolver($this->session)));
	}

	private function lifecycle(): LifecycleConditionEvaluator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('en');
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf($text, $p));

		return new LifecycleConditionEvaluator(
			new LifecycleConditionReporting($l10n, $this->createMock(LoggerInterface::class)),
			new LifecycleRuleCheck($this->dialect, new LifecycleRuleCaller($this->session, $this->groups))
		);
	}

	/**
	 * @param array<string, mixed> $object
	 * @param array<string, mixed> $library
	 */
	private function attempt(array $object, array $library): TransitionAttempt {
		return new TransitionAttempt(
			write: new LifecycleWrite(newData: $object, oldData: [], schemaSlug: 'case', field: 'status'),
			move: new TransitionMove(action: 'approve', from: 'open', to: 'approved'),
			conditions: $library
		);
	}

	public function testATransitionConditionNamingAConditionIsDecidedByIt(): void {
		$spec = ['condition' => ['$condition' => 'groot']];

		$this->assertNull($this->lifecycle()->refusalOf($spec, $this->attempt(['bedrag' => 900], self::LIBRARY)));
		$refusal = $this->lifecycle()->refusalOf($spec, $this->attempt(['bedrag' => 100], self::LIBRARY));
		$this->assertSame(LifecycleConditionEvaluator::CODE_UNMET, $refusal['code']);
		$this->assertArrayNotHasKey('unresolved', $refusal);
	}

	public function testAnUndeclaredNameRefusesTheTransitionAndSaysWhich(): void {
		$refusal = $this->lifecycle()->refusalOf(['condition' => ['$condition' => 'groot']], $this->attempt(['bedrag' => 900], []));

		$this->assertSame(LifecycleConditionEvaluator::CODE_UNMET, $refusal['code']);
		$this->assertSame('groot', $refusal['unresolved']['condition']);
		$this->assertStringContainsString('"groot"', $refusal['message']);
	}

	public function testANegatedUndeclaredNameStillRefusesRatherThanPassing(): void {
		// With a false for the unresolvable reference, `not` would make this TRUE and let every move through.
		$refusal = $this->lifecycle()->refusalOf(['condition' => ['not' => ['$condition' => 'missing']]], $this->attempt([], self::LIBRARY));

		$this->assertSame('missing', $refusal['unresolved']['condition']);
	}

	public function testAnAutoWhenNamingAnUndeclaredConditionMakesNoMove(): void {
		$this->assertFalse($this->lifecycle()->holds(['not' => ['$condition' => 'missing']], $this->attempt([], self::LIBRARY)));
		$this->assertTrue($this->lifecycle()->holds(['$condition' => 'groot'], $this->attempt(['bedrag' => 900], self::LIBRARY)));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function stateAnnotation(mixed $entry): array {
		$annotation = ['field' => 'status', 'states' => ['besloten' => ['entry' => $entry]]];

		return NamedConditionScope::withLibrary($annotation, [NamedConditionScope::KEY => self::LIBRARY]);
	}

	private function states(): StateConditionEvaluator {
		return new StateConditionEvaluator(new StateFieldRuleResolver($this->session, $this->groups, $this->dialect), $this->dialect);
	}

	public function testAStateEntryConditionResolvesTheSchemasLibrary(): void {
		$crossing = new StateCrossing(from: 'open', to: 'besloten');
		$annotation = $this->stateAnnotation(['$condition' => 'groot']);

		$this->assertNull($this->states()->refusal(new StateSave($annotation, ['bedrag' => 900]), $crossing));
		$refusal = $this->states()->refusal(new StateSave($annotation, ['bedrag' => 100]), $crossing);
		$this->assertSame(StateConditionEvaluator::CODE_ENTRY, $refusal['code']);
		$this->assertArrayNotHasKey('unresolved', $refusal);
	}

	public function testAStateEntryConditionNamingAnUndeclaredConditionRefusesAndNamesIt(): void {
		$annotation = $this->stateAnnotation(['not' => ['$condition' => 'missing']]);

		$refusal = $this->states()->refusal(new StateSave($annotation, []), new StateCrossing(from: 'open', to: 'besloten'));

		$this->assertSame(StateConditionEvaluator::CODE_ENTRY, $refusal['code']);
		$this->assertSame('$condition:missing', $refusal['clause']);
		$this->assertSame('missing', $refusal['unresolved']['condition']);
		$this->assertStringContainsString('"missing"', $refusal['message']);
	}

	private function schema(mixed $when): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setConfiguration([
			'x-openregister-lifecycle' => [
				'field' => 'status',
				'states' => ['open' => ['fields' => ['required' => [['fields' => ['motivering'], 'when' => $when]]]]],
			],
			NamedConditionScope::KEY => self::LIBRARY,
		]);

		return $schema;
	}

	public function testAFieldRuleWhenResolvesTheSchemasLibrary(): void {
		$resolver = new StateFieldRuleResolver($this->session, $this->groups, $this->dialect);
		$schema = $this->schema(['$condition' => 'groot']);

		$this->assertSame(['motivering'], $resolver->resolve($schema, ['status' => 'open', 'bedrag' => 900])->getRequired());
		$this->assertSame([], $resolver->resolve($schema, ['status' => 'open', 'bedrag' => 100])->getRequired());
	}

	public function testAFieldRuleNamingAnUndeclaredConditionKeepsTheRestrictionOn(): void {
		$resolver = new StateFieldRuleResolver($this->session, $this->groups, $this->dialect);

		// A missing name answering false would lift the requirement; refused, it keeps it.
		$this->assertSame(['motivering'], $resolver->resolve($this->schema(['$condition' => 'missing']), ['status' => 'open'])->getRequired());
	}
}
