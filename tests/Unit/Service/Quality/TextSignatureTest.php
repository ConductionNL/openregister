<?php

/**
 * Unit tests for the MinHash text signature.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-every-extracted-file-carries-a-text-signature-req-ndt-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Quality;

use OCA\OpenRegister\Service\Quality\TextSignature;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Quality\TextSignature
 */
final class TextSignatureTest extends TestCase {

	/**
	 * The same text, in any case, spacing or punctuation, gives the same signature, hash and count.
	 *
	 * @return void
	 */
	public function testTheSameTextGivesTheSameSignature(): void {
		$text  = NearDuplicateTexts::letter(seed: 1);
		$first = (new TextSignature())->sign($text);
		$again = (new TextSignature())->sign(strtoupper(str_replace(' ', "  \t", $text)) . '!!');

		$this->assertCount(TextSignature::HASHES, $first['signature']);
		$this->assertSame($first, $again);
		$this->assertGreaterThan(250, $first['shingleCount']);
		$this->assertLessThanOrEqual(296, $first['shingleCount']);
		$this->assertSame(hash('sha256', (new TextSignature())->normalise($text)), $first['textSha256']);
		foreach ($first['signature'] as $value) {
			$this->assertGreaterThanOrEqual(0, $value);
			$this->assertLessThan(2147483647, $value);
		}
	}//end testTheSameTextGivesTheSameSignature()

	/**
	 * The signature of a fixed text is pinned, so a change to the seeds or the hash, which would make
	 * stored signatures incomparable with new ones, fails here.
	 *
	 * @return void
	 */
	public function testTheSignatureIsStableAcrossInstances(): void {
		$text      = implode(' ', array_map(static fn (int $i): string => 'woord' . $i, range(1, 60)));
		$signature = (new TextSignature())->sign($text)['signature'];

		$this->assertSame([100991887, 36841226, 5890932, 83351633], array_slice($signature, 0, 4));
		$this->assertSame([101420220, 62789074], array_slice($signature, -2));
		$this->assertSame(56, (new TextSignature())->sign($text)['shingleCount']);
	}//end testTheSignatureIsStableAcrossInstances()

	/**
	 * A 20-word cover note is not signed but keeps its count and hash.
	 *
	 * @return void
	 */
	public function testAShortTextIsNotSigned(): void {
		$record = (new TextSignature())->sign(NearDuplicateTexts::letter(seed: 3, words: 20));

		$this->assertNull($record['signature']);
		$this->assertSame(16, $record['shingleCount']);
		$this->assertSame(64, strlen($record['textSha256']));

		$this->assertNull((new TextSignature())->sign(NearDuplicateTexts::letter(seed: 3, words: 53))['signature']);
		$this->assertNotNull((new TextSignature())->sign(NearDuplicateTexts::letter(seed: 3, words: 54))['signature']);
	}//end testAShortTextIsNotSigned()

	/**
	 * A text without words has no shingles.
	 *
	 * @return void
	 */
	public function testAnEmptyTextHasNoShingles(): void {
		$record = (new TextSignature())->sign(" \n ... ");

		$this->assertSame(['signature' => null, 'shingleCount' => 0, 'textSha256' => hash('sha256', '')], $record);
	}//end testAnEmptyTextHasNoShingles()

	/**
	 * A forwarded copy with a header and two changed words estimates above 0.9; an unrelated letter far below.
	 *
	 * @return void
	 */
	public function testNearIdenticalTextsEstimateAboveNinety(): void {
		$signer    = new TextSignature();
		$original  = NearDuplicateTexts::letter(seed: 4);
		$forwarded = "Doorgestuurd bericht van de afdeling:\n" . NearDuplicateTexts::edited(text: $original, positions: [100, 220]);

		$a = $signer->sign($original)['signature'];
		$b = $signer->sign($forwarded)['signature'];
		$c = $signer->sign(NearDuplicateTexts::letter(seed: 5))['signature'];

		$this->assertGreaterThanOrEqual(0.9, $signer->estimate($a, $b));
		$this->assertLessThan(0.2, $signer->estimate($a, $c));
		$this->assertSame(1.0, $signer->estimate($a, $a));
	}//end testNearIdenticalTextsEstimateAboveNinety()

	/**
	 * Signatures of different lengths, or empty ones, estimate 0.
	 *
	 * @return void
	 */
	public function testMismatchedSignaturesEstimateZero(): void {
		$signer = new TextSignature();

		$this->assertSame(0.0, $signer->estimate([], []));
		$this->assertSame(0.0, $signer->estimate([1, 2], [1]));
		$this->assertSame(0.5, $signer->estimate([1, 2], [1, 3]));
	}//end testMismatchedSignaturesEstimateZero()

	/**
	 * Normalisation lower-cases, turns punctuation into spaces and folds whitespace; shingles are distinct.
	 *
	 * @return void
	 */
	public function testNormaliseAndShingles(): void {
		$signer = new TextSignature();

		$this->assertSame('één twee drie', $signer->normalise("  ÉÉN,\ttwee.\n\nDrie! "));
		$this->assertSame('a b', $signer->normalise('a.b'));
		$this->assertSame(['a b c d e', 'b c d e f'], $signer->shingles('a b c d e f'));
		$this->assertSame(['a b a b a', 'b a b a b'], $signer->shingles('a b a b a b a'));
		$this->assertSame([], $signer->shingles('a b c d'));
		$this->assertSame([], $signer->shingles(''));
	}//end testNormaliseAndShingles()
}//end class
