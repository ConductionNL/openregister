<?php

/**
 * Unit tests for ArchivalAnnotationValidator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-2-3
 */

declare(strict_types=1);

namespace Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator;
use PHPUnit\Framework\TestCase;

final class ArchivalAnnotationValidatorTest extends TestCase {

	private ArchivalAnnotationValidator $validator;

	protected function setUp(): void {
		$this->validator = new ArchivalAnnotationValidator();
	}//end setUp()

	public function testNoAnnotationIsValid(): void {
		self::assertSame([], $this->validator->validate(['properties' => []]));
	}//end testNoAnnotationIsValid()

	public function testWellFormedAnnotationPasses(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => [
						'default' => 'P30D',
						'rules' => [
							[
								'condition' => 'statusCode < 400',
								'retention' => 'PT1H',
								'reason' => 'successful integrations',
							],
							[
								'condition' => 'statusCode >= 400',
								'retention' => 'P30D',
							],
						],
					],
				],
			]
		);

		self::assertSame([], $errors);
	}//end testWellFormedAnnotationPasses()

	public function testMissingRetentionDefaultIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => ['retention' => []],
			]
		);

		$codes = array_column($errors, 'code');
		self::assertContains('archival-retention-default-missing', $codes);
	}//end testMissingRetentionDefaultIsRejected()

	public function testMalformedRetentionDefaultIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => ['retention' => ['default' => '30 days']],
			]
		);

		$codes = array_column($errors, 'code');
		self::assertContains('archival-retention-default-malformed', $codes);
	}//end testMalformedRetentionDefaultIsRejected()

	public function testRuleConditionNotStringIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => [
						'default' => 'P30D',
						'rules' => [
							['condition' => 42, 'retention' => 'P7D'],
						],
					],
				],
			]
		);

		$codes = array_column($errors, 'code');
		self::assertContains('archival-rule-condition-not-string', $codes);
	}//end testRuleConditionNotStringIsRejected()

	public function testRuleRetentionMalformedIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => [
						'default' => 'P30D',
						'rules' => [
							['condition' => 'statusCode < 400', 'retention' => '1h'],
						],
					],
				],
			]
		);

		$codes = array_column($errors, 'code');
		self::assertContains('archival-rule-retention-malformed', $codes);
	}//end testRuleRetentionMalformedIsRejected()

	public function testUnknownKeyUnderRetentionIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => [
						'default' => 'P30D',
						'strategy' => 'oldest-first',
					],
				],
			]
		);

		$messages = array_column($errors, 'message');
		$blob = implode(' ', $messages);
		self::assertStringContainsString('archival-retention-unknown-key', implode(' ', array_column($errors, 'code')));
		self::assertStringContainsString('strategy', $blob);
	}//end testUnknownKeyUnderRetentionIsRejected()

	public function testRetentionBlockMissingEntirelyIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [],
			]
		);

		$codes = array_column($errors, 'code');
		self::assertContains('archival-retention-missing', $codes);
	}//end testRetentionBlockMissingEntirelyIsRejected()

	public function testAnnotationNotObjectIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => 'P30D',
			]
		);

		$codes = array_column($errors, 'code');
		self::assertContains('archival-not-object', $codes);
	}//end testAnnotationNotObjectIsRejected()

	public function testUnknownKeyUnderRuleIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => [
						'default' => 'P30D',
						'rules' => [
							[
								'condition' => 'statusCode < 400',
								'retention' => 'PT1H',
								'priority' => 'high',
							],
						],
					],
				],
			]
		);

		$codes = array_column($errors, 'code');
		self::assertContains('archival-rule-unknown-key', $codes);
	}//end testUnknownKeyUnderRuleIsRejected()

	public function testIso8601CompoundDurationParses(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => ['retention' => ['default' => 'P1Y6M']],
			]
		);

		self::assertSame([], $errors);
	}//end testIso8601CompoundDurationParses()
}//end class
