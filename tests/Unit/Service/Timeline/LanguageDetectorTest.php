<?php

/**
 * Unit tests for LanguageDetector — the language a message arrived in.
 *
 * The tests that matter here are the ones about DECLINING. A detector that
 * always answers something produces a column that looks filled in and is not,
 * and a handler filtering on "came in in Polish" gets the wrong pile.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Timeline;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Timeline\LanguageDetector;
use PHPUnit\Framework\TestCase;

/**
 * LanguageDetectorTest.
 */
class LanguageDetectorTest extends TestCase {
	/**
	 * Detector under test.
	 *
	 * @var LanguageDetector
	 */
	private LanguageDetector $detector;

	protected function setUp(): void {
		parent::setUp();

		$this->detector = new LanguageDetector();
	}

	public function testDutchIsRecognised(): void {
		$this->assertSame(
			'nl',
			$this->detector->detect('Ik heb de aanvraag van de bewoner ontvangen en wij gaan er naar kijken.')
		);
	}

	public function testEnglishIsRecognised(): void {
		$this->assertSame(
			'en',
			$this->detector->detect('The applicant would like to know whether the papers that they sent have arrived.')
		);
	}

	public function testTheArrivalLanguageIsRecordedForPolish(): void {
		$this->assertSame(
			'pl',
			$this->detector->detect('Dzień dobry, nie jest to dla mnie jasne, proszę o wyjaśnienie jak to działa.')
		);
	}

	public function testAScriptSettlesTheQuestionOnItsOwn(): void {
		$this->assertSame('ar', $this->detector->detect('مرحبا، أود أن أعرف حالة طلبي'));
	}

	public function testAThreeWordMessageCarriesNoEvidence(): void {
		$this->assertNull($this->detector->detect('Bedankt'));
	}

	public function testEmptyAndAbsentTextAnswerNothing(): void {
		$this->assertNull($this->detector->detect(''));
		$this->assertNull($this->detector->detect('   '));
		$this->assertNull($this->detector->detect(null));
	}

	public function testAStringWithNoWordsAtAllAnswersNothing(): void {
		$this->assertNull($this->detector->detect('2026-09-14 12:00 +++'));
	}
}
