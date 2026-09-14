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
	private CalculationTrialService $trials;

	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->expects($this->never())->method($this->anything());

		$this->trials = new CalculationTrialService(
			new CalculationAnnotationValidator(),
			new CalculationEvaluator(new PlaceholderResolver($userSession)),
			$this->createMock(CalculationPayloadBuilder::class),
			$this->createMock(RegisterMapper::class),
			$schemaMapper,
			$this->createMock(MagicMapper::class),
		);
	}

	public function testASampleTrialReturnsTheValueAndWritesNothing(): void {
		$result = $this->trials->trySample(
			[
				'type' => 'date',
				'expression' => ['dateAdd' => ['date' => ['prop' => 'ontvangstdatum'], 'amount' => 6, 'unit' => 'weeks']],
			],
			['ontvangstdatum' => '2026-01-01']
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('2026-02-12', $result['value']);
		$this->assertSame(['ontvangstdatum'], $result['dependencies']);
	}

	public function testAnUnknownOperatorComesBackAsAnErrorNotAnException(): void {
		$result = $this->trials->trySample(
			['type' => 'integer', 'expression' => ['frobnicate' => [['prop' => 'aantal']]]],
			['aantal' => 3]
		);

		$this->assertFalse($result['ok']);
		$this->assertSame('calculation-unknown-op', $result['error']['code']);
		$this->assertStringContainsString('frobnicate', $result['error']['message']);
	}

	public function testAnExpressionThatFailsAtRuntimeReportsTheEvaluatorMessage(): void {
		$result = $this->trials->trySample(
			['type' => 'number', 'expression' => ['/' => [['prop' => 'a'], ['prop' => 'b']]]],
			['a' => 10, 'b' => 0]
		);

		$this->assertFalse($result['ok']);
		$this->assertSame('calculation-trial-failed', $result['error']['code']);
		$this->assertSame(['a', 'b'], $result['dependencies']);
	}

	public function testATrialNeverBurnsASequenceNumber(): void {
		$result = $this->trials->trySample(
			['type' => 'string', 'expression' => ['sequence' => ['scope' => 'yearly']]],
			[]
		);

		$this->assertTrue($result['ok']);
		$this->assertNull($result['value'], 'a trial must not reserve a running number');
	}
}
