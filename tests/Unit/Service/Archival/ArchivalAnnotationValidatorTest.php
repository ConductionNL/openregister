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

	public function testDeclaredArchivalFactsPass(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => ['default' => 'P30D'],
					'aggregationLevel' => 'Dossier',
					'useRestriction' => ['type' => 'Geen beperking', 'description' => 'Openbaar na toetsing'],
					'temporalCoverage' => [
						'type' => 'Looptijd',
						'startProperty' => 'startdatum',
						'endProperty' => 'einddatum',
					],
				],
			]
		);

		self::assertSame([], $errors);
	}//end testDeclaredArchivalFactsPass()

	public function testUnknownTopLevelKeyIsReportedAsAWarningNotAnError(): void {
		$findings = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => ['default' => 'P30D'],
					'aggregatieniveau' => 'Dossier',
				],
			]
		);

		self::assertSame(['archival-unknown-key'], array_column($findings, 'code'));

		// The finding is still made — a typo is still surfaced — but it is a
		// warning, so the schema is saved rather than refused.
		$split = ArchivalAnnotationValidator::partition(findings: $findings);
		self::assertSame([], $split['errors'], 'An unknown top-level key must not refuse the schema.');
		self::assertCount(1, $split['warnings']);
		self::assertSame(
			ArchivalAnnotationValidator::SEVERITY_WARNING,
			$findings[0]['severity'] ?? null
		);
	}//end testUnknownTopLevelKeyIsReportedAsAWarningNotAnError()

	/**
	 * REGRESSION, 2026-09-12. openregister#3661 made an unknown top-level key
	 * refuse the schema. `ImportHandler` then drops a refused schema and every
	 * object that needed it, so two apps went red on payloads they had carried
	 * for weeks, each surfacing as an HTTP 412 from their own demo-data seeding
	 * rather than as anything naming this annotation:
	 *
	 *   filinq   — 9 of 22 schemas, on `category` / `action` / `responsibleParty`
	 *   pipelinq — the `ticket` supertype, on a `_note`
	 *
	 * Both payloads are reproduced verbatim here. Every app clones
	 * openregister@development at CI run time, so a check that refuses one of
	 * these reaches all 21 apps the minute it merges.
	 *
	 * @return void
	 */
	public function testExtraDescriptiveKeysBesideAValidRetentionBlockDoNotRefuseTheSchema(): void {
		$filinq = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => ['default' => 'P7Y'],
					'category' => 'Archiefwet 1995 selectielijst — cat. 3.2: zakelijke correspondentie',
					'action' => 'destroy',
					'responsibleParty' => 'docudesk-privacy-officer',
				],
			]
		);
		self::assertSame(
			[],
			ArchivalAnnotationValidator::partition(findings: $filinq)['errors'],
			"filinq's correspondence schema must still import."
		);
		self::assertCount(3, ArchivalAnnotationValidator::partition(findings: $filinq)['warnings']);

		$pipelinq = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'_note' => 'unify-ticket-supertype: the retired contactmoment schema carried a VNG/AVG policy.',
					'retention' => ['default' => 'P2Y'],
				],
			]
		);
		self::assertSame(
			[],
			ArchivalAnnotationValidator::partition(findings: $pipelinq)['errors'],
			"pipelinq's ticket supertype must still import."
		);
	}//end testExtraDescriptiveKeysBesideAValidRetentionBlockDoNotRefuseTheSchema()

	/**
	 * The other half of the rule: loosening the UNKNOWN-key check must not
	 * loosen the checks on a fact the schema actually declared. Each of these
	 * validates something present, so each stays fatal.
	 *
	 * @return void
	 */
	public function testDeclaredFactsAreStillRefusedWhenMalformed(): void {
		$cases = [
			'malformed retention' => ['retention' => ['default' => 'seven years']],
			'unknown retention key' => ['retention' => ['default' => 'P30D', 'strategy' => 'destroy']],
			'term off the begrippenlijst' => ['retention' => ['default' => 'P30D'], 'aggregationLevel' => 'Bananen'],
		];

		foreach ($cases as $label => $annotation) {
			$findings = $this->validator->validate(['x-openregister-archival' => $annotation]);
			self::assertNotSame(
				[],
				ArchivalAnnotationValidator::partition(findings: $findings)['errors'],
				sprintf('%s must still refuse the schema.', $label)
			);
		}
	}//end testDeclaredFactsAreStillRefusedWhenMalformed()

	public function testAggregationLevelOutsideTheListIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => ['default' => 'P30D'],
					'aggregationLevel' => 'Map',
				],
			]
		);

		self::assertSame(['archival-aggregation-level-unknown'], array_column($errors, 'code'));
		self::assertStringContainsString('Archief, Serie, Dossier, Archiefstuk', $errors[0]['message']);
	}//end testAggregationLevelOutsideTheListIsRejected()

	public function testUseRestrictionTypeOutsideTheListIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => ['default' => 'P30D'],
					'useRestriction' => ['type' => 'Openbaar'],
				],
			]
		);

		self::assertSame(['archival-use-restriction-type-unknown'], array_column($errors, 'code'));
	}//end testUseRestrictionTypeOutsideTheListIsRejected()

	public function testUseRestrictionUnknownKeyIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => ['default' => 'P30D'],
					'useRestriction' => ['type' => 'Overig', 'reden' => 'typo'],
				],
			]
		);

		self::assertSame(['archival-use-restriction-unknown-key'], array_column($errors, 'code'));
	}//end testUseRestrictionUnknownKeyIsRejected()

	public function testTemporalCoverageWithoutAStartPropertyIsRejected(): void {
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => ['default' => 'P30D'],
					'temporalCoverage' => ['type' => 'Looptijd'],
				],
			]
		);

		self::assertSame(['archival-temporal-coverage-startproperty-missing'], array_column($errors, 'code'));
	}//end testTemporalCoverageWithoutAStartPropertyIsRejected()

	public function testTemporalCoverageWithALiteralDateIsRejected(): void {
		// A date on the schema would be the same wrong answer for every row,
		// so the annotation takes property NAMES and `start` is not a key.
		$errors = $this->validator->validate(
			[
				'x-openregister-archival' => [
					'retention' => ['default' => 'P30D'],
					'temporalCoverage' => ['type' => 'Looptijd', 'start' => '2021-01-01'],
				],
			]
		);

		self::assertSame(
			['archival-temporal-coverage-unknown-key', 'archival-temporal-coverage-startproperty-missing'],
			array_column($errors, 'code')
		);
	}//end testTemporalCoverageWithALiteralDateIsRejected()

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
