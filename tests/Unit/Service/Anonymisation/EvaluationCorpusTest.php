<?php

/**
 * Unit tests for the shipped evaluation corpus and its reader (anonymisation-discloses-itself, REQ-ADI-002).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Anonymisation\EvaluationCorpus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The corpus is what its README says, and a malformed one is refused.
 */
#[CoversClass(EvaluationCorpus::class)]
final class EvaluationCorpusTest extends TestCase {

	private const DIRECTORY = __DIR__ . '/../../../Fixtures/anonymisation-eval/nl-woo-synthetic';

	/**
	 * The shipped corpus.
	 *
	 * @return EvaluationCorpus
	 */
	private static function corpus(): EvaluationCorpus {
		return EvaluationCorpus::fromDirectory(directory: self::DIRECTORY);
	}//end corpus()

	public function testTheCorpusIsNamedAndVersioned(): void {
		$corpus = self::corpus();

		$this->assertSame('nl-woo-synthetic', $corpus->name);
		$this->assertSame('1', $corpus->version);
		$this->assertSame(['PERSON', 'EMAIL', 'PHONE', 'IBAN', 'BSN', 'ADDRESS', 'ORGANIZATION'], $corpus->types);
		$this->assertGreaterThanOrEqual(10, count($corpus->documents));
	}

	public function testEveryGoldSpanLiesInsideItsTextAndCutsOutItsValue(): void {
		$spans = 0;
		foreach (self::corpus()->documents as $document) {
			foreach ($document['gold'] as $span) {
				$this->assertGreaterThanOrEqual(0, $span['start'], $document['id']);
				$this->assertLessThanOrEqual(strlen($document['text']), $span['end'], $document['id']);
				$this->assertLessThan($span['end'], $span['start'], $document['id']);
				$this->assertSame($span['value'], substr($document['text'], $span['start'], ($span['end'] - $span['start'])), $document['id']);
				$spans++;
			}
		}

		$this->assertGreaterThanOrEqual(35, $spans);
	}

	public function testEveryTypeIsAnnotated(): void {
		$seen = [];
		foreach (self::corpus()->documents as $document) {
			foreach ($document['gold'] as $span) {
				$seen[$span['type']] = true;
			}
		}

		$this->assertSame(self::corpus()->types, array_values(array_intersect(self::corpus()->types, array_keys($seen))));
	}

	public function testEveryBsnPassesTheElfproefInTheReservedTestRange(): void {
		foreach (self::corpus()->documents as $document) {
			foreach ($document['gold'] as $span) {
				if ($span['type'] !== 'BSN') {
					continue;
				}

				$bsn = $span['value'];
				$this->assertMatchesRegularExpression('/^\d{9}$/', $bsn);
				$this->assertGreaterThanOrEqual(999990000, (int) $bsn, 'Not in the reserved test range: ' . $bsn);
				$sum = 0;
				for ($i = 0; $i < 8; $i++) {
					$sum += ((int) $bsn[$i] * (9 - $i));
				}

				$this->assertSame(0, (($sum - (int) $bsn[8]) % 11), 'Fails the elfproef: ' . $bsn);
			}
		}
	}

	public function testEveryEmailUsesAReservedDomain(): void {
		foreach (self::corpus()->documents as $document) {
			foreach ($document['gold'] as $span) {
				if ($span['type'] === 'EMAIL') {
					$this->assertMatchesRegularExpression('/@example\.(org|com|net)$/', $span['value']);
				}
			}
		}
	}

	public function testAMissingCorpusIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('No corpus at');

		EvaluationCorpus::fromDirectory(directory: '/nonexistent-corpus');
	}

	public function testAnAnnotationOfAnUndeclaredTypeIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		EvaluationCorpus::fromArray(
			data: [
				'name' => 'x',
				'version' => '1',
				'types' => ['EMAIL'],
				'documents' => [['id' => 'd', 'text' => 'Jan', 'gold' => [['type' => 'PERSON', 'value' => 'Jan', 'start' => 0, 'end' => 3]]]],
			]
		);
	}

	public function testACorpusWithoutAVersionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		EvaluationCorpus::fromArray(data: ['name' => 'x', 'types' => ['EMAIL'], 'documents' => []]);
	}
}
