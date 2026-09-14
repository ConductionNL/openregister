<?php

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\CalculationTrialService;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Try it before you save it.
 *
 * Covers the spec scenario "an administrator tries an expression before
 * committing it": the value comes back, and no schema is written. The mappers
 * are mocked with no expectations, so any call into the schema or object store
 * on the sample path would be a failure, not a silent pass.
 */
class CalculationTrialServiceTest extends TestCase {
	/**
	 * @var CalculationTrialService The dry-run service under test.
	 */
	private CalculationTrialService $trials;

	/**
	 * Wire the collaborators this suite needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->expects($this->never())->method($this->anything());

		$this->trials = new CalculationTrialService(
			new CalculationAnnotationValidator(),
			new CalculationEvaluator(new PlaceholderResolver($userSession)),
			$this->createMock(originalClassName: CalculationPayloadBuilder::class),
			$this->createMock(originalClassName: RegisterMapper::class),
			$schemaMapper,
			$this->createMock(originalClassName: MagicMapper::class),
		);
	}

	/**
	 * A sample trial returns the value and writes nothing.
	 *
	 * @return void
	 */
	public function testASampleTrialReturnsTheValueAndWritesNothing(): void {
		$result = $this->trials->trySample(
			[
				'type' => 'date',
				'expression' => ['dateAdd' => ['date' => ['prop' => 'ontvangstdatum'], 'amount' => 6, 'unit' => 'weeks']],
			],
			['ontvangstdatum' => '2026-01-01']
		);

		$this->assertTrue(condition: $result['ok']);
		$this->assertSame(expected: '2026-02-12', actual: $result['value']);
		$this->assertSame(expected: ['ontvangstdatum'], actual: $result['dependencies']);
	}

	/**
	 * An unknown operator comes back as an error not an exception.
	 *
	 * @return void
	 */
	public function testAnUnknownOperatorComesBackAsAnErrorNotAnException(): void {
		$result = $this->trials->trySample(
			['type' => 'integer', 'expression' => ['frobnicate' => [['prop' => 'aantal']]]],
			['aantal' => 3]
		);

		$this->assertFalse(condition: $result['ok']);
		$this->assertSame(expected: 'calculation-unknown-op', actual: $result['error']['code']);
		$this->assertStringContainsString(needle: 'frobnicate', haystack: $result['error']['message']);
	}

	/**
	 * An expression that fails at runtime reports the evaluator message.
	 *
	 * @return void
	 */
	public function testAnExpressionThatFailsAtRuntimeReportsTheEvaluatorMessage(): void {
		$result = $this->trials->trySample(
			['type' => 'number', 'expression' => ['/' => [['prop' => 'a'], ['prop' => 'b']]]],
			['a' => 10, 'b' => 0]
		);

		$this->assertFalse(condition: $result['ok']);
		$this->assertSame(expected: 'calculation-trial-failed', actual: $result['error']['code']);
		$this->assertSame(expected: ['a', 'b'], actual: $result['dependencies']);
	}

	/**
	 * A trial never burns A sequence number.
	 *
	 * @return void
	 */
	public function testATrialNeverBurnsASequenceNumber(): void {
		$result = $this->trials->trySample(
			['type' => 'string', 'expression' => ['sequence' => ['scope' => 'yearly']]],
			[]
		);

		$this->assertTrue(condition: $result['ok']);
		$this->assertNull(actual: $result['value'], message: 'a trial must not reserve a running number');
	}
}
