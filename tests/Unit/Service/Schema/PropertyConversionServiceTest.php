<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Schema\PropertyConversionService}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\PropertyConversionService;
use PHPUnit\Framework\TestCase;

class PropertyConversionServiceTest extends TestCase {

	private PropertyConversionService $conversions;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->conversions = new PropertyConversionService();
	}//end setUp()

	/**
	 * An administrator sees what a conversion would cost, exactly: the spec's
	 * own figures, 4,000 stored values of which 12 are not numeric.
	 *
	 * @return void
	 */
	public function testAnAdministratorSeesWhatAConversionWouldCost(): void {
		$values = array_fill(0, 3988, '42');
		for ($i = 0; $i < 12; $i++) {
			$values[] = 'onbekend-' . $i;
		}

		$preview = $this->conversions->preview(values: $values, from: 'string', to: 'number');

		$this->assertTrue($preview['supported']);
		$this->assertSame(4000, $preview['total']);
		$this->assertSame(3988, $preview['convertible']);
		$this->assertSame(12, $preview['rejected']);
		$this->assertCount(PropertyConversionService::SAMPLE_SIZE, $preview['samples']);
		$this->assertSame('onbekend-0', $preview['samples'][0]['value']);
	}//end testAnAdministratorSeesWhatAConversionWouldCost()

	/**
	 * An unsupported conversion is refused with a reason, and nothing is
	 * attempted: the preview reports zero convertible rather than a
	 * best-effort count.
	 *
	 * @return void
	 */
	public function testAnUnsupportedConversionIsRefusedWithAReason(): void {
		$this->assertFalse($this->conversions->isSupported(from: 'file', to: 'number'));

		$preview = $this->conversions->preview(values: ['a', 'b'], from: 'file', to: 'number');

		$this->assertFalse($preview['supported']);
		$this->assertSame(0, $preview['convertible']);
		$this->assertSame(2, $preview['rejected']);
		$this->assertNotNull($preview['reason']);
		$this->assertStringContainsString('file', (string)$preview['reason']);
	}//end testAnUnsupportedConversionIsRefusedWithAReason()

	/**
	 * The refusal names the targets that ARE supported when there are any,
	 * because "no" without "but you could" is half an answer.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheSupportedTargetsWhenThereAreAny(): void {
		$reason = $this->conversions->refusalReason(from: 'boolean', to: 'number');

		$this->assertStringContainsString('string', $reason);
	}//end testTheRefusalNamesTheSupportedTargetsWhenThereAreAny()

	/**
	 * An absent value is not a conversion failure: the property stays empty
	 * whatever its type. Counting it as a rejection would make every optional
	 * property look unconvertible.
	 *
	 * @return void
	 */
	public function testAnAbsentValueIsNotAConversionFailure(): void {
		$preview = $this->conversions->preview(
			values: [null, '', '7'],
			from: 'string',
			to: 'integer'
		);

		$this->assertSame(3, $preview['convertible']);
		$this->assertSame(0, $preview['rejected']);
	}//end testAnAbsentValueIsNotAConversionFailure()

	/**
	 * A boolean conversion is strict about what it accepts. Treating any
	 * non-empty string as true is how a column of free text silently becomes
	 * a column of true.
	 *
	 * @return void
	 */
	public function testABooleanConversionIsStrictAboutWhatItAccepts(): void {
		$preview = $this->conversions->preview(
			values: ['true', 'ja', '0', 'misschien', 'wellicht'],
			from: 'string',
			to: 'boolean'
		);

		$this->assertSame(3, $preview['convertible']);
		$this->assertSame(2, $preview['rejected']);
	}//end testABooleanConversionIsStrictAboutWhatItAccepts()

	/**
	 * A number that is not whole does not survive a conversion to integer.
	 *
	 * @return void
	 */
	public function testANonWholeNumberDoesNotSurviveAConversionToInteger(): void {
		$preview = $this->conversions->preview(
			values: ['10', '10.5'],
			from: 'string',
			to: 'integer'
		);

		$this->assertSame(1, $preview['convertible']);
		$this->assertSame(1, $preview['rejected']);
	}//end testANonWholeNumberDoesNotSurviveAConversionToInteger()

	/**
	 * The matrix is published, so an administrator can plan around it rather
	 * than discover it by trial.
	 *
	 * @return void
	 */
	public function testTheMatrixIsPublished(): void {
		$published = $this->conversions->supported();

		$this->assertNotEmpty($published);
		$froms = array_column($published, 'from');
		$this->assertContains('string', $froms);
		$this->assertNotContains('file', $froms);
	}//end testTheMatrixIsPublished()

	/**
	 * A no-op conversion is trivially supported and converts every value.
	 *
	 * @return void
	 */
	public function testANoOpConversionIsSupported(): void {
		$this->assertTrue($this->conversions->isSupported(from: 'file', to: 'file'));
	}//end testANoOpConversionIsSupported()
}//end class
