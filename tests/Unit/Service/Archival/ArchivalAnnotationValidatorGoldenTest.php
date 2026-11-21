<?php

declare(strict_types=1);

/**
 * Golden test for ArchivalAnnotationValidator::validate(): pins the exact findings
 * (codes, messages, severities, order) and whether each schema is allowed or
 * refused, captured on the code before validate() and validateRule() were split
 * into named steps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace Unit\Service\Archival;

use OCA\OpenRegister\Service\Archival\ArchivalAnnotationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes of ArchivalAnnotationValidator.
 */
class ArchivalAnnotationValidatorGoldenTest extends TestCase {

	/**
	 * The golden cases: schema, findings, and whether partition() refuses it.
	 *
	 * @return array<string, array{0: array, 1: array, 2: bool}>
	 */
	public static function goldenCases(): array {
		$path   = __DIR__ . '/../../../fixtures/archival-annotation-validator-golden.json';
		$golden = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

		$cases = [];
		foreach ($golden as $name => $case) {
			$cases[$name] = [$case['schema'], $case['findings'], $case['refused']];
		}

		return $cases;
	}//end goldenCases()

	/**
	 * validate() returns exactly the golden findings, and the schema is allowed or refused as before.
	 *
	 * @param array $schema   The schema definition.
	 * @param array $findings The findings captured on the old code.
	 * @param bool  $refused  Whether the old code refused the schema.
	 *
	 * @return void
	 */
	#[DataProvider('goldenCases')]
	public function testFindingsMatchTheGolden(array $schema, array $findings, bool $refused): void {
		$actual = (new ArchivalAnnotationValidator())->validate($schema);

		$this->assertSame($findings, $actual);
		$this->assertSame($refused, ArchivalAnnotationValidator::partition($actual)['errors'] !== []);
	}//end testFindingsMatchTheGolden()

	/**
	 * Mutation check: the golden distinguishes an allowed schema from the same schema with one value broken.
	 *
	 * @return void
	 */
	public function testTheGoldenCatchesAMutatedRuleRetention(): void {
		$cases  = self::goldenCases();
		$schema = $cases['valid annotation is allowed'][0];
		$schema['x-openregister-archival']['retention']['rules'][0]['retention'] = 'P10';

		$actual = (new ArchivalAnnotationValidator())->validate($schema);

		$this->assertNotSame($cases['valid annotation is allowed'][1], $actual);
		$this->assertSame('archival-rule-retention-malformed', $actual[0]['code']);
		$this->assertNotSame([], ArchivalAnnotationValidator::partition($actual)['errors']);
	}//end testTheGoldenCatchesAMutatedRuleRetention()
}//end class
