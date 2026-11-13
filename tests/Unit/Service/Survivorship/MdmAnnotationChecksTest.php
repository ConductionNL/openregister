<?php

/**
 * Unit tests for MdmAnnotationChecks — shape checks of an MDM schema annotation.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survivorship
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Survivorship;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Survivorship\MdmAnnotationChecks;
use PHPUnit\Framework\TestCase;

final class MdmAnnotationChecksTest extends TestCase {

	private MdmAnnotationChecks $checks;

	protected function setUp(): void {
		$this->checks = new MdmAnnotationChecks('survivorship');
	}

	public function testAnAbsentAnnotationHasNoErrorsAndStopsTheCheck(): void {
		$this->assertSame([], $this->checks->shapeErrors(null));
	}

	public function testAnObjectAnnotationContinuesToTheDetailedCheck(): void {
		$this->assertNull($this->checks->shapeErrors(['sourceLink' => []]));
	}

	public function testAScalarAnnotationIsRefusedNamingTheAnnotation(): void {
		$this->assertSame(
			[['code' => 'survivorship.not-object', 'message' => 'x-openregister-survivorship must be an object.']],
			$this->checks->shapeErrors('yes')
		);
	}

	public function testAnAbsentSourceLinkHasNoErrors(): void {
		$this->assertSame([], $this->checks->errors(null));
	}

	public function testAScalarSourceLinkIsRefused(): void {
		$this->assertSame(
			[
				[
					'code' => 'survivorship.source-link-not-object',
					'message' => 'x-openregister-survivorship "sourceLink" must be an object.',
				],
			],
			$this->checks->errors('embedded')
		);
	}

	public function testAnEmbeddedSourceLinkNeedsNothingMore(): void {
		$this->assertSame([], $this->checks->errors([]));
		$this->assertSame([], $this->checks->errors(['mode' => 'embedded']));
	}

	public function testACompleteReverseFkPasses(): void {
		$this->assertSame(
			[],
			$this->checks->errors(['mode' => 'reverseFk', 'sourceSchema' => 'person', 'referenceField' => 'golden'])
		);
	}

	public function testAReverseFkMissingEitherPartIsIncomplete(): void {
		$expected = [
			[
				'code' => 'golden.source-link-reverse-fk-incomplete',
				'message' => 'x-openregister-golden reverseFk "sourceLink" requires "sourceSchema" and "referenceField".',
			],
		];
		$checks   = new MdmAnnotationChecks('golden');

		$this->assertSame($expected, $checks->errors(['mode' => 'reverseFk', 'sourceSchema' => 'person']));
		$this->assertSame($expected, $checks->errors(['mode' => 'reverseFk', 'referenceField' => 'golden']));
		$this->assertSame($expected, $checks->errors(['mode' => 'reverseFk', 'sourceSchema' => '', 'referenceField' => 'g']));
	}
}
