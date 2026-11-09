<?php

/**
 * ConsentEnvelopeEvaluators hands out the consent envelope evaluator behind its interface.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Consent
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Consent;

use OCA\OpenRegister\Service\Consent\ConsentEvidenceSource;
use OCA\OpenRegister\Service\Consent\ConsentPropertyWrite;
use OCA\OpenRegister\Service\Consent\ConsentEnvelopeEvaluator;
use OCA\OpenRegister\Service\Consent\ConsentEnvelopeEvaluatorInterface;
use OCA\OpenRegister\Service\Consent\ConsentEnvelopeEvaluators;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Consent\ConsentEnvelopeEvaluators
 */
final class ConsentEnvelopeEvaluatorsTest extends TestCase {

	/**
	 * The evaluator handed out is the evaluator class, typed as its interface.
	 *
	 * @return void
	 */
	public function testItHandsOutTheEvaluator(): void {
		$evaluator = ConsentEnvelopeEvaluators::evaluator();
		$this->assertInstanceOf(ConsentEnvelopeEvaluatorInterface::class, $evaluator);
		$this->assertInstanceOf(ConsentEnvelopeEvaluator::class, $evaluator);
	}//end testItHandsOutTheEvaluator()

	/**
	 * It answers a write exactly as a directly built evaluator does.
	 *
	 * @return void
	 */
	public function testItAnswersAsTheEvaluatorDoes(): void {
		$annotation = ['purpose' => 'newsletter'];
		$persisted  = [['granted' => true, 'at' => '2026-01-01T00:00:00+00:00', 'by' => 'alice']];
		$mutated    = [['granted' => false, 'at' => '2026-01-01T00:00:00+00:00', 'by' => 'alice']];
		foreach ([[$persisted, $persisted], [$mutated, $persisted], [[], null]] as [$incoming, $stored]) {
			$expected = (new ConsentEnvelopeEvaluator())->evaluate(new ConsentPropertyWrite('consent', $annotation, $incoming, $stored), new ConsentEvidenceSource('alice', '127.0.0.1', 'ua'));
			$actual   = ConsentEnvelopeEvaluators::evaluator()->evaluate(new ConsentPropertyWrite('consent', $annotation, $incoming, $stored), new ConsentEvidenceSource('alice', '127.0.0.1', 'ua'));
			unset($expected['value'], $actual['value']);
			$this->assertSame($expected, $actual);
		}
	}//end testItAnswersAsTheEvaluatorDoes()

	/**
	 * The consent on-save listener does not name the evaluator class.
	 *
	 * @return void
	 */
	public function testTheListenerDoesNotNameTheEvaluatorClass(): void {
		$source = (string) file_get_contents(__DIR__ . '/../../../../lib/Listener/ConsentEnvelopeOnSaveListener.php');
		$this->assertDoesNotMatchRegularExpression('/\bConsentEnvelopeEvaluator\b(?!Interface|s\b)/', $source);
	}//end testTheListenerDoesNotNameTheEvaluatorClass()
}//end class
