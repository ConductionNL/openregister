<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Tests\Unit\Architecture\ControllerSources;

/**
 * The system verbs carry no authorization, so no HTTP surface may reach
 * them: no controller references them.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-in-process-callers-act-as-a-named-app
 */
class CaseSystemVerbsUnroutedTest extends TestCase {

	/**
	 * Scan every controller for the system verbs.
	 *
	 * @return void
	 */
	public function testNoControllerReachesASystemVerb(): void {
		$scanned = 0;
		$offenders = [];
		foreach (ControllerSources::files() as $file) {
			$scanned++;
			$source = (string)file_get_contents($file);
			if (preg_match('/\b(createPlanAsSystem|getPlanAsSystem|ensureItems)\b/', $source) === 1) {
				$offenders[] = $file;
			}
		}

		$this->assertGreaterThan(10, $scanned, 'The scan must actually see the controllers.');
		$this->assertSame([], $offenders, 'A system verb has no authorization; route the user verb instead.');
	}//end testNoControllerReachesASystemVerb()
}//end class
