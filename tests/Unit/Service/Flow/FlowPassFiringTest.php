<?php

/**
 * FlowPassFiring: a firing cannot be committed without the pass token that owns its claims.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowFiring;
use OCA\OpenRegister\Service\Flow\FlowPassFiring;
use OCA\OpenRegister\Service\Flow\FlowStreamRelease;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlowPassFiringTest extends TestCase {

	/**
	 * A claimed firing with an enabled transition afterwards.
	 *
	 * @return FlowFiring
	 */
	private function firing(): FlowFiring {
		return (new FlowFiring(streamId: 'a', transition: 'T', froms: ['p'], taken: ['q']))
			->withClaimedPlaces(claimedPlaces: ['p', 'q'])
			->withLogEntry(logEntry: ['status' => 'completed'], enabledAfter: true);
	}//end firing()

	/**
	 * Owner tokens that name no pass.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function emptyOwners(): array {
		return [
			'empty'      => [''],
			'whitespace' => ['  '],
		];
	}//end emptyOwners()

	/**
	 * A release with an empty owner cannot be built, so a commit can never release under no pass.
	 *
	 * @param string $owner An owner token that names no pass.
	 *
	 * @return void
	 */
	#[DataProvider('emptyOwners')]
	public function testAFiringWithoutAnOwnerIsRefused(string $owner): void {
		$this->expectException(InvalidArgumentException::class);
		new FlowPassFiring(firing: $this->firing(), owner: $owner);
	}//end testAFiringWithoutAnOwnerIsRefused()

	/**
	 * The release carries the firing's claims, the pass token and whether a transition stays enabled.
	 *
	 * @return void
	 */
	public function testTheReleaseCarriesTheOwnerAndTheFiringsClaims(): void {
		$this->assertEquals(
			new FlowStreamRelease(claimedPlaces: ['p', 'q'], owner: 'pass-1', enabled: true),
			(new FlowPassFiring(firing: $this->firing(), owner: 'pass-1'))->release()
		);
	}//end testTheReleaseCarriesTheOwnerAndTheFiringsClaims()
}//end class
