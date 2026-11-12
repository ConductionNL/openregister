<?php

/**
 * Golden characterisation test for ReversibilityAnnotationValidator::validate().
 *
 * Pins the full refusal list — codes, messages and their order — for a corpus
 * of declarations that walks every branch: shape refusals alone and combined,
 * the window checks, the irreversible-kind and undeclared-kind refusals, and
 * the declarations that are accepted. The golden was recorded on the code
 * before its validators were split into named steps.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\BulkJob
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\BulkJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\BulkJob\ReversibilityAnnotationValidator;
use PHPUnit\Framework\TestCase;

final class ReversibilityAnnotationValidatorGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/reversibility-annotation-validator-golden.json';

	/**
	 * The corpus: case name => schema configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function corpus(): array {
		$a = ReversibilityAnnotationValidator::ANNOTATION;

		return [
			'no annotation' => [],
			'annotation not an array' => [$a => 'yes'],
			'skipped entries' => [$a => [0 => ['reversible' => 'x'], '' => ['reversible' => 'x'], 'notAnArray' => 'x']],
			'plain write reversible' => [$a => ['setStatus' => ['kind' => 'write', 'reversible' => true, 'reversalWindow' => 3600]]],
			'not reversible' => [$a => ['deleteAll' => ['reversible' => false]]],
			'reversible absent' => [$a => ['deleteAll' => []]],
			'irreversible kinds' => [$a => [
				'a' => ['kind' => 'destroy', 'reversible' => true],
				'b' => ['kind' => 'dispatch', 'reversible' => true],
				'c' => ['kind' => 'transfer', 'reversible' => true, 'reversalWindow' => 60],
			]],
			'undeclared kind reads irreversible' => [$a => [
				'purgeOld' => ['reversible' => true],
				'SendMail' => ['reversible' => true],
				'archiveToEdepot' => ['reversible' => true],
			]],
			'undeclared kind reads harmless' => [$a => ['setFlag' => ['reversible' => true]]],
			'explicit write on destructive key' => [$a => ['deleteFlag' => ['kind' => 'write', 'reversible' => true]]],
			'unknown kind' => [$a => ['x' => ['kind' => 'explode'], 'y' => ['kind' => 7], 'z' => ['kind' => null, 'reversible' => true]]],
			'not a boolean' => [$a => ['x' => ['reversible' => 'true'], 'y' => ['reversible' => 1]]],
			'window out of range' => [$a => [
				'w0' => ['reversible' => true, 'reversalWindow' => 0],
				'w1' => ['reversible' => true, 'reversalWindow' => 7776001],
				'w2' => ['reversible' => true, 'reversalWindow' => '60'],
				'w3' => ['reversible' => true, 'reversalWindow' => 1.5],
				'w4' => ['reversible' => true, 'reversalWindow' => 7776000],
				'w5' => ['reversible' => true, 'reversalWindow' => 1],
			]],
			'window without reversible' => [$a => ['w' => ['reversalWindow' => 60], 'v' => ['reversible' => false, 'reversalWindow' => 60]]],
			'every shape refusal at once' => [$a => ['deleteAll' => ['kind' => 'nuke', 'reversible' => 'yes', 'reversalWindow' => -1]]],
			'shape refusals stop the reversibility checks' => [$a => ['purge' => ['kind' => 'destroy', 'reversible' => true, 'reversalWindow' => 'soon']]],
			'unknown kind and window without reversible' => [$a => ['x' => ['kind' => 'nuke', 'reversalWindow' => 60]]],
		];
	}//end corpus()

	public function testRefusalsMatchTheGolden(): void {
		$validator = new ReversibilityAnnotationValidator();
		$actual    = [];
		foreach (self::corpus() as $name => $configuration) {
			$actual[$name] = $validator->validate($configuration);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame($golden, $actual);
	}//end testRefusalsMatchTheGolden()
}//end class
