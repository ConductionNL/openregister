<?php

/**
 * MaskFormTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Exception\UnknownMaskFormException;
use OCA\OpenRegister\Files\Service\File\MaskForm;
use PHPUnit\Framework\TestCase;

/**
 * The mask forms of REQ-PIS-010: what each keeps, and when the placeholder wins.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\MaskForm
 */
class MaskFormTest extends TestCase {

	public function testLastFourOfAnIban(): void {
		$this->assertSame('**************4300', MaskForm::apply(value: 'NL91ABNA0417164300', form: ['form' => 'last', 'n' => 4]));
	}//end testLastFourOfAnIban()

	public function testFirstKeepsTheStart(): void {
		$this->assertSame('NL91**************', MaskForm::apply(value: 'NL91ABNA0417164300', form: ['form' => 'first', 'n' => 4]));
	}//end testFirstKeepsTheStart()

	public function testEmailLocalPartIsMasked(): void {
		$this->assertSame('********@gemeente.nl', MaskForm::apply(value: 'j.jansen@gemeente.nl', form: ['form' => 'email-local']));
		$this->assertNull(MaskForm::apply(value: 'geen adres', form: ['form' => 'email-local']), 'no @: the placeholder');
		$this->assertNull(MaskForm::apply(value: '@gemeente.nl', form: ['form' => 'email-local']), 'no local part');
		$this->assertNull(MaskForm::apply(value: 'jan@', form: ['form' => 'email-local']), 'no domain');
	}//end testEmailLocalPartIsMasked()

	public function testGeneraliseADateToItsYear(): void {
		$this->assertSame('2026', MaskForm::apply(value: '12-03-2026', form: ['form' => 'generalise']));
		$this->assertSame('1987', MaskForm::apply(value: '4 juli 1987', form: ['form' => 'generalise']));
		$this->assertSame('1234', MaskForm::apply(value: '1234 AB', form: ['form' => 'generalise']), 'a postcode keeps its digits');
		$this->assertSame('1234', MaskForm::apply(value: '1234AB', form: ['form' => 'generalise']));
		$this->assertNull(MaskForm::apply(value: 'Jan Jansen', form: ['form' => 'generalise']), 'no date or postcode');
		$this->assertNull(MaskForm::apply(value: '2026', form: ['form' => 'generalise']), 'a bare year shows the whole value');
		$this->assertNull(MaskForm::apply(value: '2025 tot 2026', form: ['form' => 'generalise']), 'two years: not one date');
	}//end testGeneraliseADateToItsYear()

	public function testAMaskThatWouldRevealTooMuchFallsBack(): void {
		$this->assertNull(MaskForm::apply(value: '061234', form: ['form' => 'last', 'n' => 4]), 'four of six is more than half');
		$this->assertSame('***234', MaskForm::apply(value: '061234', form: ['form' => 'last', 'n' => 3]), 'exactly half is allowed');
		$this->assertNull(MaskForm::apply(value: 'abc', form: ['form' => 'first', 'n' => 3]), 'the whole value');
	}//end testAMaskThatWouldRevealTooMuchFallsBack()

	public function testThePlaceholderFormAndNoFormMaskNothing(): void {
		$this->assertNull(MaskForm::apply(value: 'NL91ABNA0417164300', form: null));
		$this->assertNull(MaskForm::apply(value: 'NL91ABNA0417164300', form: ['form' => 'placeholder']));
		$this->assertNull(MaskForm::apply(value: '', form: ['form' => 'last', 'n' => 4]));
	}//end testThePlaceholderFormAndNoFormMaskNothing()

	public function testNormaliseKeepsFormAndCount(): void {
		$this->assertSame(
			['IBAN' => ['form' => 'last', 'n' => 4], 'EMAIL' => ['form' => 'email-local'], 'DATE' => ['form' => 'generalise']],
			MaskForm::normalise(maskForms: ['IBAN' => ['form' => 'last', 'n' => '4'], 'EMAIL' => ['form' => 'email-local', 'n' => 9], 'DATE' => ['form' => 'generalise']])
		);
		$this->assertSame([], MaskForm::normalise(maskForms: []));
	}//end testNormaliseKeepsFormAndCount()

	public function testNormaliseRefusesWhatCannotBeApplied(): void {
		$refused = [
			'unknown form' => ['IBAN' => ['form' => 'middle', 'n' => 4]],
			'n of zero' => ['IBAN' => ['form' => 'last', 'n' => 0]],
			'no n' => ['IBAN' => ['form' => 'first']],
			'fractional n' => ['IBAN' => ['form' => 'last', 'n' => 2.5]],
			'entry not a map' => ['IBAN' => 'last'],
			'list, not a map' => [['form' => 'last', 'n' => 4]],
		];
		foreach ($refused as $case => $maskForms) {
			try {
				MaskForm::normalise(maskForms: $maskForms);
				$this->fail($case.' was accepted');
			} catch (UnknownMaskFormException $e) {
				$this->assertStringContainsString('anonymisation.maskForms', $e->getMessage(), $case);
			}
		}

		$this->expectException(UnknownMaskFormException::class);
		MaskForm::normalise(maskForms: 'last');
	}//end testNormaliseRefusesWhatCannotBeApplied()
}//end class
