<?php

declare(strict_types=1);

namespace Unit\Service\Quality;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use PHPUnit\Framework\TestCase;

class QualityScorerTest extends TestCase {
	private QualityScorer $scorer;

	private DateTimeImmutable $now;

	protected function setUp(): void {
		$this->scorer = new QualityScorer();
		$this->now = new DateTimeImmutable('2026-06-23T00:00:00+00:00');
	}

	public function testNoRulesIsTriviallyCompliant(): void {
		$this->assertSame(1.0, $this->scorer->score(['a' => 1], [], $this->now));
	}

	public function testRequiredAllPresent(): void {
		$rules = [
			['type' => 'required', 'field' => 'name'],
			['type' => 'required', 'field' => 'email'],
		];
		$object = ['name' => 'Anna', 'email' => 'anna@example.com'];
		$this->assertSame(1.0, $this->scorer->score($object, $rules, $this->now));
	}

	public function testRequiredHalfPresent(): void {
		$rules = [
			['type' => 'required', 'field' => 'name'],
			['type' => 'required', 'field' => 'email'],
		];
		// Only name present, empty string + missing count as absent.
		$object = ['name' => 'Anna', 'email' => ''];
		$this->assertSame(0.5, $this->scorer->score($object, $rules, $this->now));
	}

	public function testRequiredNonePresent(): void {
		$rules = [['type' => 'required', 'field' => 'name']];
		$object = ['other' => 'x'];
		$this->assertSame(0.0, $this->scorer->score($object, $rules, $this->now));
	}

	public function testWeightingFavoursHeavyRule(): void {
		$rules = [
			['type' => 'required', 'field' => 'name', 'weight' => 3],
			['type' => 'required', 'field' => 'email', 'weight' => 1],
		];
		// name present (3), email absent (0) => 3/4 = 0.75.
		$object = ['name' => 'Anna'];
		$this->assertSame(0.75, $this->scorer->score($object, $rules, $this->now));
	}

	public function testFormatEmailValidAndInvalid(): void {
		$rules = [['type' => 'format', 'field' => 'email', 'format' => 'email']];
		$this->assertSame(1.0, $this->scorer->score(['email' => 'a@b.co'], $rules, $this->now));
		$this->assertSame(0.0, $this->scorer->score(['email' => 'not-an-email'], $rules, $this->now));
	}

	public function testFormatCustomPattern(): void {
		$rules = [['type' => 'format', 'field' => 'kvk', 'pattern' => '^[0-9]{8}$']];
		$this->assertSame(1.0, $this->scorer->score(['kvk' => '12345678'], $rules, $this->now));
		$this->assertSame(0.0, $this->scorer->score(['kvk' => '1234'], $rules, $this->now));
	}

	public function testFormatAbsentFieldScoresZero(): void {
		$rules = [['type' => 'format', 'field' => 'email', 'format' => 'email']];
		$this->assertSame(0.0, $this->scorer->score([], $rules, $this->now));
	}

	public function testFreshnessFreshIsHigh(): void {
		$rules = [['type' => 'freshness', 'field' => 'updatedAt', 'halfLifeDays' => 180]];
		$object = ['updatedAt' => '2026-06-23T00:00:00+00:00'];
		// Same instant => decay 1.0.
		$this->assertSame(1.0, $this->scorer->score($object, $rules, $this->now));
	}

	public function testFreshnessHalfLifeDecaysToHalf(): void {
		$rules = [['type' => 'freshness', 'field' => 'updatedAt', 'halfLifeDays' => 180]];
		// Exactly 180 days old => ~0.5.
		$object = ['updatedAt' => '2025-12-25T00:00:00+00:00'];
		$score = $this->scorer->score($object, $rules, $this->now);
		$this->assertGreaterThan(0.45, $score);
		$this->assertLessThan(0.55, $score);
	}

	public function testFreshnessUnparseableDateScoresZero(): void {
		$rules = [['type' => 'freshness', 'field' => 'updatedAt', 'halfLifeDays' => 180]];
		$object = ['updatedAt' => 'not-a-date'];
		$this->assertSame(0.0, $this->scorer->score($object, $rules, $this->now));
	}

	public function testNullSafeAndNonFatalOnUnknownType(): void {
		$rules = [
			['type' => 'required', 'field' => 'name'],
			['type' => 'bogus', 'field' => 'whatever'],
			['not-an-array'],
			'garbage',
		];
		$object = ['name' => 'Anna'];
		// required scores 1.0, bogus scores 0.0 => 0.5; non-array entries skipped.
		$this->assertSame(0.5, $this->scorer->score($object, $rules, $this->now));
	}

	public function testDottedFieldPath(): void {
		$rules = [['type' => 'required', 'field' => 'profile.email']];
		$object = ['profile' => ['email' => 'a@b.co']];
		$this->assertSame(1.0, $this->scorer->score($object, $rules, $this->now));
	}

	public function testStatusThresholds(): void {
		$thresholds = ['good' => 0.8, 'fair' => 0.5];
		$this->assertSame('good', $this->scorer->status(0.9, $thresholds));
		$this->assertSame('fair', $this->scorer->status(0.6, $thresholds));
		$this->assertSame('poor', $this->scorer->status(0.2, $thresholds));
	}

	public function testStatusDefaultThresholds(): void {
		$this->assertSame('good', $this->scorer->status(0.85, []));
		$this->assertSame('poor', $this->scorer->status(0.1, []));
	}
}
