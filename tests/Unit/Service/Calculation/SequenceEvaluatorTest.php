<?php

/**
 * OpenRegister SequenceEvaluatorTest
 *
 * Unit tests for the `sequence` calculation primitive and its consume-once
 * contract (reserves only when a SequenceContext is supplied on create).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
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

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCA\OpenRegister\Service\Calculation\SequenceContext;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\SequenceService;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the sequence calculation primitive.
 *
 * Covers: no consume without a context (read path), consume + zero-pad with a
 * context (create path), increment across two creates, scope-key derivation
 * (yearly/monthly/global), custom pad, and invalid-scope error.
 */
class SequenceEvaluatorTest extends TestCase {

	private CalculationEvaluator $eval;

	/** @var SequenceService&MockObject */
	private $sequences;

	/**
	 * Set up the evaluator and a mocked sequence service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$this->eval = new CalculationEvaluator(new PlaceholderResolver($userSession));
		$this->sequences = $this->createMock(SequenceService::class);

	}//end setUp()

	/**
	 * Build a SequenceContext bound to register 1 / schema 2.
	 *
	 * @return SequenceContext
	 */
	private function context(): SequenceContext {
		return new SequenceContext(service: $this->sequences, registerId: 1, schemaId: 2);
	}//end context()

	/**
	 * Without a context (read path) the sequence node returns null and never
	 * consumes a number.
	 *
	 * @return void
	 */
	public function testNoConsumeWithoutContext(): void {
		$this->sequences->expects($this->never())->method('reserveNext');

		$result = $this->eval->evaluate([], ['sequence' => ['scope' => 'yearly', 'pad' => 4]]);
		$this->assertNull($result);

	}//end testNoConsumeWithoutContext()

	/**
	 * With a context the sequence node reserves and zero-pads the value.
	 *
	 * @return void
	 */
	public function testConsumesAndPads(): void {
		$this->sequences->expects($this->once())
			->method('reserveNext')
			->with($this->equalTo(1), $this->equalTo(2), $this->equalTo(date('Y')))
			->willReturn(1);

		$result = $this->eval->evaluate([], ['sequence' => ['scope' => 'yearly', 'pad' => 4]], $this->context());
		$this->assertSame('0001', $result);

	}//end testConsumesAndPads()

	/**
	 * A second create increments to 0002.
	 *
	 * @return void
	 */
	public function testIncrementsAcrossCreates(): void {
		$this->sequences->method('reserveNext')->willReturn(2);

		$result = $this->eval->evaluate([], ['sequence' => ['scope' => 'yearly', 'pad' => 4]], $this->context());
		$this->assertSame('0002', $result);

	}//end testIncrementsAcrossCreates()

	/**
	 * The yearly scope key is the current four-digit year.
	 *
	 * @return void
	 */
	public function testYearlyScopeKey(): void {
		$this->sequences->expects($this->once())
			->method('reserveNext')
			->with(1, 2, date('Y'))
			->willReturn(5);

		$this->eval->evaluate([], ['sequence' => ['scope' => 'yearly']], $this->context());

	}//end testYearlyScopeKey()

	/**
	 * The monthly scope key is YYYY-MM.
	 *
	 * @return void
	 */
	public function testMonthlyScopeKey(): void {
		$this->sequences->expects($this->once())
			->method('reserveNext')
			->with(1, 2, date('Y-m'))
			->willReturn(5);

		$this->eval->evaluate([], ['sequence' => ['scope' => 'monthly']], $this->context());

	}//end testMonthlyScopeKey()

	/**
	 * The global scope key is the empty string.
	 *
	 * @return void
	 */
	public function testGlobalScopeKey(): void {
		$this->sequences->expects($this->once())
			->method('reserveNext')
			->with(1, 2, '')
			->willReturn(42);

		$result = $this->eval->evaluate([], ['sequence' => ['scope' => 'global', 'pad' => 2]], $this->context());
		$this->assertSame('42', $result);

	}//end testGlobalScopeKey()

	/**
	 * The default pad is 4 when omitted.
	 *
	 * @return void
	 */
	public function testDefaultPadIsFour(): void {
		$this->sequences->method('reserveNext')->willReturn(7);

		$result = $this->eval->evaluate([], ['sequence' => ['scope' => 'global']], $this->context());
		$this->assertSame('0007', $result);

	}//end testDefaultPadIsFour()

	/**
	 * A larger reserved value is not truncated by a smaller pad.
	 *
	 * @return void
	 */
	public function testPadDoesNotTruncate(): void {
		$this->sequences->method('reserveNext')->willReturn(12345);

		$result = $this->eval->evaluate([], ['sequence' => ['scope' => 'global', 'pad' => 4]], $this->context());
		$this->assertSame('12345', $result);

	}//end testPadDoesNotTruncate()

	/**
	 * An invalid scope throws even on the read path (annotation error).
	 *
	 * @return void
	 */
	public function testInvalidScopeThrows(): void {
		$this->expectException(EvaluationException::class);
		$this->eval->evaluate([], ['sequence' => ['scope' => 'hourly']]);

	}//end testInvalidScopeThrows()

	/**
	 * Missing scope key throws.
	 *
	 * @return void
	 */
	public function testMissingScopeThrows(): void {
		$this->expectException(EvaluationException::class);
		$this->eval->evaluate([], ['sequence' => ['pad' => 4]]);

	}//end testMissingScopeThrows()

}//end class
