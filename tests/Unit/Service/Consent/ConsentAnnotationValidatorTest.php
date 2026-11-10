<?php

declare(strict_types=1);

namespace Unit\Service\Consent;

use OCA\OpenRegister\Service\Consent\ConsentAnnotationValidator;
use PHPUnit\Framework\TestCase;

class ConsentAnnotationValidatorTest extends TestCase {
	private ConsentAnnotationValidator $validator;

	protected function setUp(): void {
		$this->validator = new ConsentAnnotationValidator();
	}

	public function testNoAnnotationIsValid(): void {
		$this->assertSame([], $this->validator->validate(['properties' => []]));
	}

	public function testValidDeclarationOnArrayPropertyPasses(): void {
		$errors = $this->validator->validate([
			'properties' => [
				'beeldmateriaalConsent' => [
					'type' => 'array',
					'x-openregister-consent' => ['purpose' => 'beeldmateriaal-gebruik'],
				],
			],
		]);
		$this->assertSame([], $errors);
	}

	public function testValidDeclarationWithSubjectPropertyPasses(): void {
		$errors = $this->validator->validate([
			'properties' => [
				'beeldmateriaalConsent' => [
					'type' => 'array',
					'x-openregister-consent' => [
						'purpose' => 'beeldmateriaal-gebruik',
						'subjectProperty' => 'learnerRef',
					],
				],
			],
		]);
		$this->assertSame([], $errors);
	}

	public function testNonArrayPropertyIsRejected(): void {
		$errors = $this->validator->validate([
			'properties' => [
				'consentGiven' => [
					'type' => 'boolean',
					'x-openregister-consent' => ['purpose' => 'beeldmateriaal-gebruik'],
				],
			],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('consent-not-array', $codes);
	}

	public function testMissingPurposeIsRejected(): void {
		$errors = $this->validator->validate([
			'properties' => [
				'consentLog' => [
					'type' => 'array',
					'x-openregister-consent' => [],
				],
			],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('consent-missing-purpose', $codes);
	}

	public function testEmptyPurposeIsRejected(): void {
		$errors = $this->validator->validate([
			'properties' => [
				'consentLog' => [
					'type' => 'array',
					'x-openregister-consent' => ['purpose' => ''],
				],
			],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('consent-missing-purpose', $codes);
	}

	public function testNonStringSubjectPropertyIsRejected(): void {
		$errors = $this->validator->validate([
			'properties' => [
				'consentLog' => [
					'type' => 'array',
					'x-openregister-consent' => ['purpose' => 'x', 'subjectProperty' => 42],
				],
			],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('consent-bad-subject-property', $codes);
	}

	public function testMalformedAnnotationIsRejected(): void {
		$errors = $this->validator->validate([
			'properties' => [
				'consentLog' => [
					'type' => 'array',
					'x-openregister-consent' => 'not-an-object',
				],
			],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('consent-malformed', $codes);
	}

	public function testUnannotatedPropertiesAreIgnored(): void {
		$errors = $this->validator->validate([
			'properties' => [
				'title' => ['type' => 'string'],
				'tags' => ['type' => 'array'],
			],
		]);
		$this->assertSame([], $errors);
	}
}
