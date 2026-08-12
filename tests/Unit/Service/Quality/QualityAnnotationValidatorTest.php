<?php

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Service\Quality\QualityAnnotationValidator;
use PHPUnit\Framework\TestCase;

class QualityAnnotationValidatorTest extends TestCase {
	private QualityAnnotationValidator $validator;

	protected function setUp(): void {
		$this->validator = new QualityAnnotationValidator();
	}

	public function testAbsentAnnotationIsValid(): void {
		$this->assertSame([], $this->validator->validate(['properties' => []]));
	}

	public function testValidAnnotation(): void {
		$shape = [
			'x-openregister-quality' => [
				'field' => 'qualityScore',
				'rules' => [
					['type' => 'required', 'field' => 'name'],
					['type' => 'format', 'field' => 'email', 'format' => 'email'],
					['type' => 'freshness', 'field' => 'updatedAt', 'halfLifeDays' => 180],
				],
				'thresholds' => ['good' => 0.8, 'fair' => 0.5],
			],
		];
		$this->assertSame([], $this->validator->validate($shape));
	}

	public function testNonObjectAnnotation(): void {
		$errors = $this->validator->validate(['x-openregister-quality' => 'nope']);
		$this->assertCount(1, $errors);
		$this->assertSame('quality.not-object', $errors[0]['code']);
	}

	public function testEmptyRules(): void {
		$errors = $this->validator->validate(['x-openregister-quality' => ['rules' => []]]);
		$this->assertSame('quality.no-rules', $errors[0]['code']);
	}

	public function testUnknownRuleType(): void {
		$shape = ['x-openregister-quality' => ['rules' => [['type' => 'bogus', 'field' => 'x']]]];
		$errors = $this->validator->validate($shape);
		$this->assertSame('quality.unknown-type', $errors[0]['code']);
	}

	public function testMissingField(): void {
		$shape = ['x-openregister-quality' => ['rules' => [['type' => 'required']]]];
		$errors = $this->validator->validate($shape);
		$this->assertSame('quality.missing-field', $errors[0]['code']);
	}

	public function testFormatMissingSpec(): void {
		$shape = ['x-openregister-quality' => ['rules' => [['type' => 'format', 'field' => 'x']]]];
		$errors = $this->validator->validate($shape);
		$this->assertSame('quality.format-missing-spec', $errors[0]['code']);
	}

	public function testBadThreshold(): void {
		$shape = [
			'x-openregister-quality' => [
				'rules' => [['type' => 'required', 'field' => 'x']],
				'thresholds' => ['good' => 'high'],
			],
		];
		$errors = $this->validator->validate($shape);
		$this->assertSame('quality.bad-threshold', $errors[0]['code']);
	}
}
