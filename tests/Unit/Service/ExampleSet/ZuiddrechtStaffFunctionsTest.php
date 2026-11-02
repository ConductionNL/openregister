<?php
/**
 * The Zuiddrecht cast gives every member of team Vergunningen a function, the
 * line DqAfdelingen draws under each name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use PHPUnit\Framework\TestCase;

/**
 * The functions of the Vergunningen staff.
 */
class ZuiddrechtStaffFunctionsTest extends TestCase {

	/**
	 * Every cast member in group vergunningen carries a function; Esra Demir is a Vergunningverlener.
	 *
	 * @return void
	 */
	public function testEveryVergunningenMemberHasAFunction(): void {
		$set    = json_decode((string) file_get_contents(__DIR__.'/../../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$people = array_column($set['cast']['people'], null, 'key');

		$this->assertSame('Vergunningverlener', $people['esra']['function'] ?? null);
		foreach ($people as $key => $person) {
			if (in_array('vergunningen', (array) ($person['groups'] ?? []), true) === true) {
				$this->assertNotSame('', (string) ($person['function'] ?? ''), $key);
			}
		}
	}//end testEveryVergunningenMemberHasAFunction()
}//end class
