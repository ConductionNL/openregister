<?php

/**
 * Characterisation tests for MergeAnnotationValidator: error order across the
 * three checks, a non-object sourceLink, an embedded sourceLink and a
 * reverseFk link missing either half.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Merge
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Merge;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use OCA\OpenRegister\Service\Merge\MergeAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pins MergeAnnotationValidator outcomes before its units are split.
 */
class MergeAnnotationValidatorGoldenTest extends TestCase {

	/**
	 * The error codes for an annotation.
	 *
	 * @param mixed $annotation The x-openregister-merge value.
	 *
	 * @return array<int, string>
	 */
	private function codes(mixed $annotation): array {
		return array_column((new MergeAnnotationValidator())->validate(schema: ['x-openregister-merge' => $annotation]), 'code');
	}//end codes()

	public function testErrorsComeInCheckOrder(): void {
		$this->assertSame(
			['merge.invalid-reversal-window', 'merge.field-not-string', 'merge.field-not-string', 'merge.source-link-not-object'],
			$this->codes(annotation: ['sourceLink' => 'x', 'mergedStatus' => 1, 'entityType' => [], 'reversalWindowDays' => 1.5])
		);
	}//end testErrorsComeInCheckOrder()

	public function testSourceLinkShapes(): void {
		$this->assertSame([], $this->codes(annotation: ['sourceLink' => []]));
		$this->assertSame([], $this->codes(annotation: ['sourceLink' => ['mode' => 'embedded']]));
		$this->assertSame([], $this->codes(annotation: ['sourceLink' => null, 'reversalWindowDays' => 3]));
		$this->assertSame(
			['merge.source-link-reverse-fk-incomplete'],
			$this->codes(annotation: ['sourceLink' => ['mode' => 'reverseFk', 'sourceSchema' => 's']])
		);
		$this->assertSame(['merge.source-link-reverse-fk-incomplete'], $this->codes(annotation: ['sourceLink' => ['mode' => 'reverseFk']]));
		$this->assertSame([], $this->codes(annotation: ['sourceLink' => ['mode' => 'reverseFk', 'sourceSchema' => 's', 'referenceField' => 'f']]));
		$this->assertSame(
			[['code' => 'merge.source-link-not-object', 'message' => 'x-openregister-merge "sourceLink" must be an object.']],
			(new MergeAnnotationValidator())->validate(schema: ['x-openregister-merge' => ['sourceLink' => 5]])
		);
	}//end testSourceLinkShapes()
}//end class
