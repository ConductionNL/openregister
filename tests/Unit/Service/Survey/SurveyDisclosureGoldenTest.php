<?php

/**
 * Characterisation test pinning SurveyRules::disclosure() answers word for word.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace Unit\Service\Survey;

use OCA\OpenRegister\Service\Survey\SurveyRules;
use PHPUnit\Framework\TestCase;

/**
 * Attributed surveys and anonymous ones at their minimum disclose; below it the answers are withheld with a reason.
 */
class SurveyDisclosureGoldenTest extends TestCase {

	/**
	 * Every disclosure answer, key for key.
	 *
	 * @return void
	 */
	public function testTheDisclosureAnswersAreExact(): void {
		$rules = new SurveyRules();
		$anonymous = ['anonymity' => SurveyRules::ANONYMOUS, 'minimumResponses' => 3];

		$this->assertSame(['withheld' => false, 'count' => 1, 'reason' => ''], $rules->disclosure(survey: [], responseCount: 1));
		$this->assertSame(['withheld' => false, 'count' => 3, 'reason' => ''], $rules->disclosure(survey: $anonymous, responseCount: 3));
		$this->assertSame(
			[
				'withheld' => true,
				'count' => 2,
				'reason' => 'This survey is anonymous and has 2 of the 3 answers it needs before any of them are '
					.'shown. Fewer than that, and the answers point back at the people who gave them.',
			],
			$rules->disclosure(survey: $anonymous, responseCount: 2)
		);
		$this->assertTrue($rules->disclosure(survey: ['anonymity' => SurveyRules::ANONYMOUS], responseCount: SurveyRules::DEFAULT_MINIMUM_RESPONSES - 1)['withheld']);
	}//end testTheDisclosureAnswersAreExact()
}//end class
