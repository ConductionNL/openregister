<?php

/**
 * Unit test for ExampleSetOwnRowKinds: the writers it bundles, by fragment part.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-saved-views-for-the-sets-own-accounts
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetOwnRowKinds;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetOwnRowKinds
 */
class ExampleSetOwnRowKindsTest extends TestCase {
	/**
	 * Without writers every part is there, without a writer.
	 *
	 * @return void
	 */
	public function testEmptyBundleHasEveryPartWithoutWriter(): void {
		$this->assertSame(
			[
				'views' => null,
				'flows' => null,
				'casePlans' => null,
			],
			(new ExampleSetOwnRowKinds())->writers()
		);
	}//end testEmptyBundleHasEveryPartWithoutWriter()

	/**
	 * Each writer is given under its own part.
	 *
	 * @return void
	 */
	public function testEachWriterUnderItsPart(): void {
		$views = $this->createMock(ExampleSetViews::class);
		$flows = $this->createMock(ExampleSetFlows::class);
		$casePlans = $this->createMock(ExampleSetCasePlans::class);
		$bundle = new ExampleSetOwnRowKinds(
			views: $views,
			flows: $flows,
			casePlans: $casePlans
		);
		$this->assertSame(
			[
				'views' => $views,
				'flows' => $flows,
				'casePlans' => $casePlans,
			],
			$bundle->writers()
		);
	}//end testEachWriterUnderItsPart()
}//end class
