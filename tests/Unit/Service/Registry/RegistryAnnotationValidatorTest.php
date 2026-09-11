<?php

declare(strict_types=1);

namespace Unit\Service\Registry;

use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidator;
use PHPUnit\Framework\TestCase;

class RegistryAnnotationValidatorTest extends TestCase {
	private RegistryAnnotationValidator $v;

	protected function setUp(): void {
		$this->v = new RegistryAnnotationValidator();
	}

	public function testNoAnnotationIsValid(): void {
		$this->assertSame([], $this->v->validate(['properties' => []]));
	}

	public function testValidAnnotation(): void {
		$errors = $this->v->validate([
			'properties' => [
				'bsn' => ['type' => 'string'],
				'givenNames' => ['type' => 'string'],
				'birthDate' => ['type' => 'string', 'format' => 'date'],
			],
			'x-openregister-registry' => [
				'registry' => 'brp',
				'identity' => 'bsn',
				'owned' => ['givenNames', 'birthDate'],
			],
		]);
		$this->assertSame([], $errors);
	}

	public function testNonArrayAnnotationIsRejected(): void {
		$errors = $this->v->validate(['x-openregister-registry' => 'brp']);
		$this->assertSame('registry-malformed', $errors[0]['code']);
	}

	public function testMissingRegistryIdIsRejected(): void {
		$errors = $this->v->validate([
			'properties' => ['bsn' => ['type' => 'string']],
			'x-openregister-registry' => ['identity' => 'bsn', 'owned' => ['bsn']],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('registry-id-missing', $codes);
	}

	public function testMissingIdentityIsRejected(): void {
		$errors = $this->v->validate([
			'properties' => ['bsn' => ['type' => 'string']],
			'x-openregister-registry' => ['registry' => 'brp', 'owned' => ['bsn']],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('registry-identity-missing', $codes);
	}

	public function testIdentityNotDeclaredInSchemaIsRejected(): void {
		$errors = $this->v->validate([
			'properties' => ['givenNames' => ['type' => 'string']],
			'x-openregister-registry' => [
				'registry' => 'brp',
				'identity' => 'bsn',
				'owned' => ['givenNames'],
			],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('registry-identity-undeclared', $codes);
	}

	public function testOwnedPropertyNotDeclaredInSchemaIsRejected(): void {
		$errors = $this->v->validate([
			'properties' => ['bsn' => ['type' => 'string']],
			'x-openregister-registry' => [
				'registry' => 'brp',
				'identity' => 'bsn',
				'owned' => ['birthDate'],
			],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('registry-owned-undeclared', $codes);
	}

	public function testEmptyOwnedIsRejected(): void {
		$errors = $this->v->validate([
			'properties' => ['bsn' => ['type' => 'string']],
			'x-openregister-registry' => [
				'registry' => 'brp',
				'identity' => 'bsn',
				'owned' => [],
			],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('registry-owned-missing', $codes);
	}

	public function testNonStringOwnedEntryIsRejected(): void {
		$errors = $this->v->validate([
			'properties' => ['bsn' => ['type' => 'string']],
			'x-openregister-registry' => [
				'registry' => 'brp',
				'identity' => 'bsn',
				'owned' => [123],
			],
		]);
		$codes = array_column($errors, 'code');
		$this->assertContains('registry-owned-malformed', $codes);
	}
}
