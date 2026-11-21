<?php

declare(strict_types=1);

namespace Unit\Service\Consent;

use OCA\OpenRegister\Service\Consent\ConsentEvidenceSource;
use OCA\OpenRegister\Service\Consent\ConsentPropertyWrite;
use OCA\OpenRegister\Service\Consent\ConsentEnvelopeEvaluator;
use PHPUnit\Framework\TestCase;

class ConsentEnvelopeEvaluatorTest extends TestCase {
	private ConsentEnvelopeEvaluator $evaluator;

	protected function setUp(): void {
		$this->evaluator = new ConsentEnvelopeEvaluator();
	}

	public function testGrantingConsentFillsEvidenceFields(): void {
		$result = $this->evaluator->evaluate(
			write: new ConsentPropertyWrite(name: 'beeldmateriaalConsent', annotation: ['purpose' => 'beeldmateriaal-gebruik'], incoming: [['decision' => 'granted', 'evidenceOf' => 'v3']], persisted: []),
			source: new ConsentEvidenceSource(actingIdentity: 'guardian-42', ipAddress: '203.0.113.5', userAgent: 'Mozilla/5.0 (test)')
		);

		$this->assertFalse($result['refused']);
		$this->assertNull($result['message']);
		$entry = $result['value'][0];
		$this->assertSame('guardian-42', $entry['by']);
		$this->assertSame('203.0.113.5', $entry['ip']);
		$this->assertSame('Mozilla/5.0 (test)', $entry['userAgent']);
		$this->assertNotEmpty($entry['timestamp']);
		$this->assertSame(hash('sha256', 'beeldmateriaal-gebruik' . 'granted' . 'v3'), $entry['contentHash']);
		$this->assertNull($entry['withdrawnAt']);
	}

	public function testCallerSuppliedEvidenceFieldsAreOverwritten(): void {
		$result = $this->evaluator->evaluate(
			write: new ConsentPropertyWrite(name: 'consent', annotation: ['purpose' => 'x'], incoming: [['decision' => 'granted', 'evidenceOf' => 'v3', 'timestamp' => '2000-01-01T00:00:00+00:00', 'ip' => '10.0.0.1']], persisted: []),
			source: new ConsentEvidenceSource(actingIdentity: null, ipAddress: '203.0.113.5', userAgent: null)
		);

		$entry = $result['value'][0];
		$this->assertNotSame('2000-01-01T00:00:00+00:00', $entry['timestamp']);
		$this->assertSame('203.0.113.5', $entry['ip']);
	}

	public function testWithdrawalSetsWithdrawnAt(): void {
		$result = $this->evaluator->evaluate(
			write: new ConsentPropertyWrite(name: 'consent', annotation: ['purpose' => 'x'], incoming: [['decision' => 'withdrawn', 'evidenceOf' => 'v3']], persisted: []),
			source: new ConsentEvidenceSource(actingIdentity: null, ipAddress: null, userAgent: null)
		);

		$entry = $result['value'][0];
		$this->assertNotNull($entry['withdrawnAt']);
		$this->assertSame($entry['timestamp'], $entry['withdrawnAt']);
	}

	public function testEditingAnExistingEntryIsRefused(): void {
		$result = $this->evaluator->evaluate(
			write: new ConsentPropertyWrite(name: 'consent', annotation: ['purpose' => 'x'], incoming: [['decision' => 'refused', 'by' => 'a', 'timestamp' => 't1']], persisted: [['decision' => 'granted', 'by' => 'a', 'timestamp' => 't1']]),
			source: new ConsentEvidenceSource(actingIdentity: null, ipAddress: null, userAgent: null)
		);

		$this->assertTrue($result['refused']);
		$this->assertStringContainsString('entry 0 cannot be changed', (string)$result['message']);
		// Unfilled, normalised-but-untouched incoming array is returned on refusal.
		$this->assertSame('refused', $result['value'][0]['decision']);
		$this->assertArrayNotHasKey('contentHash', $result['value'][0]);
	}

	public function testShorteningTheArrayIsRefused(): void {
		$grant = ['decision' => 'granted', 'by' => 'a', 'timestamp' => 't1'];
		$result = $this->evaluator->evaluate(
			write: new ConsentPropertyWrite(name: 'consent', annotation: ['purpose' => 'x'], incoming: [$grant], persisted: [$grant, ['decision' => 'withdrawn', 'by' => 'a', 'timestamp' => 't2']]),
			source: new ConsentEvidenceSource(actingIdentity: null, ipAddress: null, userAgent: null)
		);

		$this->assertTrue($result['refused']);
		$this->assertStringContainsString('fewer entries', (string)$result['message']);
	}

	public function testAppendingBeyondPersistedLengthIsAllowed(): void {
		$grant = ['decision' => 'granted', 'by' => 'a', 'timestamp' => 't1', 'ip' => null, 'userAgent' => null, 'contentHash' => 'h1', 'withdrawnAt' => null];

		$result = $this->evaluator->evaluate(
			write: new ConsentPropertyWrite(name: 'consent', annotation: ['purpose' => 'x'], incoming: [$grant, ['decision' => 'withdrawn', 'evidenceOf' => 'v3']], persisted: [$grant]),
			source: new ConsentEvidenceSource(actingIdentity: null, ipAddress: null, userAgent: null)
		);

		$this->assertFalse($result['refused']);
		$this->assertCount(2, $result['value']);
		$this->assertSame('granted', $result['value'][0]['decision']);
		$this->assertSame('withdrawn', $result['value'][1]['decision']);
		$this->assertNotNull($result['value'][1]['withdrawnAt']);
	}

	public function testNonArrayIncomingAndPersistedNormaliseToEmpty(): void {
		$result = $this->evaluator->evaluate(
			write: new ConsentPropertyWrite(name: 'consent', annotation: ['purpose' => 'x'], incoming: 'not-an-array', persisted: null),
			source: new ConsentEvidenceSource(actingIdentity: null, ipAddress: null, userAgent: null)
		);

		$this->assertFalse($result['refused']);
		$this->assertSame([], $result['value']);
	}

	public function testNonArrayEntryInIncomingIsSkippedByFill(): void {
		$result = $this->evaluator->evaluate(
			write: new ConsentPropertyWrite(name: 'consent', annotation: ['purpose' => 'x'], incoming: ['not-an-array-entry'], persisted: []),
			source: new ConsentEvidenceSource(actingIdentity: null, ipAddress: null, userAgent: null)
		);

		$this->assertFalse($result['refused']);
		$this->assertSame(['not-an-array-entry'], $result['value']);
	}
}
