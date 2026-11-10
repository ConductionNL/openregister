<?php

/**
 * Golden test pinning the evidence ConsentEnvelopeEvaluator stamps on appended entries.
 *
 * Written against the evaluator before the identity, address and user agent
 * its fill helpers pass along were grouped. It pins every evidence field on
 * each appended entry, that a persisted entry is passed through untouched,
 * that a non-object entry is skipped, and that a mutation of a persisted
 * entry is refused with nothing filled. No assertion depends on the clock
 * beyond the timestamp's format and its equality with `withdrawnAt`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Consent
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/consent-evidence-envelope/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Consent;

use OCA\OpenRegister\Service\Consent\ConsentEvidenceSource;
use OCA\OpenRegister\Service\Consent\ConsentPropertyWrite;
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Service\Consent\ConsentEnvelopeEvaluator;
use PHPUnit\Framework\TestCase;

final class ConsentEnvelopeEvaluatorEvidenceGoldenTest extends TestCase {

	public function testEveryAppendedEntryCarriesTheCallersEvidenceAndOnlyThose(): void {
		$kept = ['decision' => 'granted', 'by' => 'old', 'timestamp' => 't0', 'ip' => '10.0.0.9', 'userAgent' => 'ua0', 'contentHash' => 'h0', 'withdrawnAt' => null];

		$result = (new ConsentEnvelopeEvaluator())->evaluate(
			write: new ConsentPropertyWrite(
				name: 'consent',
				annotation: ['purpose' => 'beeldmateriaal-gebruik'],
				incoming: [
					$kept,
					['decision' => 'granted', 'evidenceOf' => 'v3', 'by' => 'forged', 'ip' => '1.1.1.1', 'note' => 'kept'],
					'not-an-entry',
					['decision' => 'withdrawn', 'evidenceOf' => 'v4'],
				],
				persisted: [$kept]
			),
			source: new ConsentEvidenceSource(actingIdentity: 'guardian-42', ipAddress: '203.0.113.5', userAgent: 'Mozilla/5.0 (test)')
		);

		$this->assertFalse($result['refused']);
		$this->assertNull($result['message']);
		$this->assertSame($kept, $result['value'][0]);
		$this->assertSame('not-an-entry', $result['value'][2]);

		$granted = $result['value'][1];
		$this->assertValidTimestamp($granted['timestamp']);
		unset($granted['timestamp']);
		$this->assertSame(
			[
				'decision' => 'granted',
				'evidenceOf' => 'v3',
				'note' => 'kept',
				'by' => 'guardian-42',
				'ip' => '203.0.113.5',
				'userAgent' => 'Mozilla/5.0 (test)',
				'contentHash' => hash('sha256', 'beeldmateriaal-gebruik' . 'granted' . 'v3'),
				'withdrawnAt' => null,
			],
			$granted
		);

		$withdrawn = $result['value'][3];
		$this->assertValidTimestamp($withdrawn['timestamp']);
		$this->assertSame($withdrawn['timestamp'], $withdrawn['withdrawnAt']);
		unset($withdrawn['timestamp'], $withdrawn['withdrawnAt']);
		$this->assertSame(
			[
				'decision' => 'withdrawn',
				'evidenceOf' => 'v4',
				'by' => 'guardian-42',
				'ip' => '203.0.113.5',
				'userAgent' => 'Mozilla/5.0 (test)',
				'contentHash' => hash('sha256', 'beeldmateriaal-gebruik' . 'withdrawn' . 'v4'),
			],
			$withdrawn
		);
	}

	public function testAnAnonymousWriteStampsNullEvidence(): void {
		$result = (new ConsentEnvelopeEvaluator())->evaluate(
			write: new ConsentPropertyWrite(name: 'consent', annotation: [], incoming: [['decision' => 'granted']], persisted: []),
			source: new ConsentEvidenceSource(actingIdentity: null, ipAddress: null, userAgent: null)
		);

		$entry = $result['value'][0];
		$this->assertNull($entry['by']);
		$this->assertNull($entry['ip']);
		$this->assertNull($entry['userAgent']);
		$this->assertSame(hash('sha256', 'granted'), $entry['contentHash']);
	}

	public function testAMutatedPersistedEntryIsRefusedAndNothingIsFilled(): void {
		$result = (new ConsentEnvelopeEvaluator())->evaluate(
			write: new ConsentPropertyWrite(name: 'consent', annotation: ['purpose' => 'x'], incoming: [['decision' => 'refused'], ['decision' => 'granted']], persisted: [['decision' => 'granted']]),
			source: new ConsentEvidenceSource(actingIdentity: 'guardian-42', ipAddress: '203.0.113.5', userAgent: 'ua')
		);

		$this->assertTrue($result['refused']);
		$this->assertSame([['decision' => 'refused'], ['decision' => 'granted']], $result['value']);
	}

	private function assertValidTimestamp(mixed $value): void {
		$this->assertIsString($value);
		$this->assertInstanceOf(DateTimeImmutable::class, DateTimeImmutable::createFromFormat(DATE_ATOM, $value));
	}
}
