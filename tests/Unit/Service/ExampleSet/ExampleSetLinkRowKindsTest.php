<?php

/**
 * Unit test for ExampleSetLinkRowKinds: the writers it bundles, by fragment part.
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

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetLinkRowKinds;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetParties;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetLinkRowKinds
 */
class ExampleSetLinkRowKindsTest extends TestCase {
	/**
	 * Without writers every part is there, without a writer.
	 *
	 * @return void
	 */
	public function testEmptyBundleHasEveryPartWithoutWriter(): void {
		$this->assertSame(
			[
				'parties' => null,
				'emailLinks' => null,
				'accessLinks' => null,
				'grants' => null,
			],
			(new ExampleSetLinkRowKinds())->writers()
		);
	}//end testEmptyBundleHasEveryPartWithoutWriter()

	/**
	 * Each writer is given under its own part.
	 *
	 * @return void
	 */
	public function testEachWriterUnderItsPart(): void {
		$parties = $this->createMock(ExampleSetParties::class);
		$emailLinks = $this->createMock(ExampleSetEmailLinks::class);
		$accessLinks = $this->createMock(ExampleSetAccessLinks::class);
		$grants = $this->createMock(ExampleSetGrants::class);
		$bundle = new ExampleSetLinkRowKinds(
			parties: $parties,
			emailLinks: $emailLinks,
			accessLinks: $accessLinks,
			grants: $grants
		);
		$this->assertSame(
			[
				'parties' => $parties,
				'emailLinks' => $emailLinks,
				'accessLinks' => $accessLinks,
				'grants' => $grants,
			],
			$bundle->writers()
		);
	}//end testEachWriterUnderItsPart()
}//end class
