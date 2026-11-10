<?php

/**
 * Unit tests for when the state field rules are memoised and when they are resolved afresh.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A state whose rules depend on nothing but the state is resolved once per request;
 * a state with a conditional rule is resolved every time, because its answer depends on the data.
 *
 * @covers \OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolver
 */
class StateFieldRuleResolverMemoTest extends TestCase {

	/**
	 * A resolver for an anonymous caller.
	 *
	 * @return StateFieldRuleResolver
	 */
	private function resolver(): StateFieldRuleResolver {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		return new StateFieldRuleResolver(
			$session,
			$this->createMock(IGroupManager::class),
			new ConditionDialect($this->createMock(CalculationEvaluatorInterface::class))
		);

	}//end resolver()

	/**
	 * A schema with a lifecycle annotation on `status`.
	 *
	 * @param array<string, mixed> $states The states block.
	 *
	 * @return Schema
	 */
	private function schema(array $states): Schema {
		$schema = new Schema();
		$schema->setId(3);
		$schema->setConfiguration(['x-openregister-lifecycle' => ['field' => 'status', 'states' => $states]]);

		return $schema;

	}//end schema()

	/**
	 * A state without a block, and a block whose `fields` is no list, are resolved once and reused.
	 *
	 * @return void
	 */
	public function testUnconditionalStatesAreMemoised(): void {
		$resolver = $this->resolver();
		$schema   = $this->schema(['open' => ['fields' => 'not-a-list']]);

		$this->assertSame($resolver->resolve($schema, ['status' => 'open']), $resolver->resolve($schema, ['status' => 'open']));
		$this->assertSame($resolver->resolve($schema, [], 'unknown'), $resolver->resolve($schema, [], 'unknown'));
		$this->assertNotSame($resolver->resolve($schema, [], 'open'), $resolver->resolve($schema, [], 'unknown'));

	}//end testUnconditionalStatesAreMemoised()

	/**
	 * A block carrying a condition is resolved afresh on every call.
	 *
	 * @return void
	 */
	public function testAConditionalStateIsNotMemoised(): void {
		$resolver = $this->resolver();
		$schema   = $this->schema(['open' => ['condition' => ['==' => [['var' => 'urgent'], true]], 'fields' => []]]);

		$this->assertNotSame($resolver->resolve($schema, ['status' => 'open']), $resolver->resolve($schema, ['status' => 'open']));

	}//end testAConditionalStateIsNotMemoised()

	/**
	 * An annotation that names no field has no state to read.
	 *
	 * @return void
	 */
	public function testAnAnnotationWithoutAFieldHasNoState(): void {
		$this->assertNull($this->resolver()->stateOf(['states' => []], ['status' => 'open']));
		$this->assertSame('open', $this->resolver()->stateOf(['property' => 'status'], ['status' => 'open']));

	}//end testAnAnnotationWithoutAFieldHasNoState()
}//end class
