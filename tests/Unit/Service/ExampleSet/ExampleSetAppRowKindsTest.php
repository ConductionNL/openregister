<?php

/**
 * Unit test for ExampleSetAppRowKinds: the writers it bundles, by fragment part.
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

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppRowKinds;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppRowKinds
 */
class ExampleSetAppRowKindsTest extends TestCase {
	/**
	 * Without writers every part is there, without a writer.
	 *
	 * @return void
	 */
	public function testEmptyBundleHasEveryPartWithoutWriter(): void {
		$this->assertSame(
			[
				'deckCards' => null,
				'appointments' => null,
				'collectivesPages' => null,
				'talkConversations' => null,
			],
			(new ExampleSetAppRowKinds())->writers()
		);
	}//end testEmptyBundleHasEveryPartWithoutWriter()

	/**
	 * Each writer is given under its own part.
	 *
	 * @return void
	 */
	public function testEachWriterUnderItsPart(): void {
		$deckCards = $this->createMock(ExampleSetDeckCards::class);
		$appointments = $this->createMock(ExampleSetAppointments::class);
		$collectivesPages = $this->createMock(ExampleSetCollectivesPages::class);
		$talkConversations = $this->createMock(ExampleSetTalkConversations::class);
		$bundle = new ExampleSetAppRowKinds(
			deckCards: $deckCards,
			appointments: $appointments,
			collectivesPages: $collectivesPages,
			talkConversations: $talkConversations
		);
		$this->assertSame(
			[
				'deckCards' => $deckCards,
				'appointments' => $appointments,
				'collectivesPages' => $collectivesPages,
				'talkConversations' => $talkConversations,
			],
			$bundle->writers()
		);
	}//end testEachWriterUnderItsPart()
}//end class
