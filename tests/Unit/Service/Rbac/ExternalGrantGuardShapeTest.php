<?php

/**
 * Block-shape tests for ExternalGrantGuard.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\ExternalGrantGuard;
use OCA\OpenRegister\Service\Rbac\LapseWindow;
use PHPUnit\Framework\TestCase;

/**
 * Which parts of a block the guard reads, and how it names the principal and holders.
 *
 * The clock is fixed and every end date carries an explicit offset.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ExternalGrantGuard
 */
class ExternalGrantGuardShapeTest extends TestCase {

	/**
	 * A missing block, pseudo-principal keys, numeric keys and non-list values are not read.
	 *
	 * @return void
	 */
	public function testOnlyActionListsAreRead(): void {
		$guard   = new ExternalGrantGuard();
		$endless = ['user' => 'x', 'external' => true];

		$this->assertSame([], $guard->endlessExternalGrants(authorization: null));
		$this->assertNull($guard->refusalFor(authorization: null));
		$this->assertSame(
			[],
			$guard->endlessExternalGrants(
				authorization: ['public' => [$endless], 'inheritFromPublic' => [$endless], 0 => [$endless], 'read' => 'staff']
			)
		);
	}//end testOnlyActionListsAreRead()

	/**
	 * Only a literal `true` marks a grant external; a blank end is no end; the principal
	 * is named by the first non-blank of name, principal, id, user, group.
	 *
	 * @return void
	 */
	public function testExternalMarkingEndsAndPrincipalNaming(): void {
		$offending = (new ExternalGrantGuard())->endlessExternalGrants(
			authorization: [
				'read'   => [
					['user' => 'a', 'external' => 'true'],
					['name' => ' ', 'principal' => 'adviser', 'user' => 'u', 'external' => true, 'until' => '  '],
					['group' => 'partners', 'external' => true],
					['external' => true],
					'staff',
				],
				'update' => [['id' => 'ext-7', 'external' => true, 'until' => 5]],
			]
		);

		$this->assertSame(
			[['read', 'adviser'], ['read', 'partners'], ['read', ''], ['update', 'ext-7']],
			array_map(static fn (array $o): array => [$o['action'], $o['principal']], $offending)
		);
		$this->assertSame('external-grant-needs-an-end', $offending[0]['rule']);
	}//end testExternalMarkingEndsAndPrincipalNaming()

	/**
	 * A lapse warning lists the holders by kind and skips grants ending beyond the horizon.
	 *
	 * @return void
	 */
	public function testLapseWarningListsHoldersWithinTheHorizonOnly(): void {
		$now     = new DateTimeImmutable('2026-05-01T00:00:00+00:00');
		$lapsing = (new ExternalGrantGuard())->lapsingWithin(
			authorization: [
				'read' => [
					['user' => 'jan', 'group' => 'partners', 'external' => true, 'until' => '2026-05-03T00:00:00+00:00'],
					['user' => '', 'group' => 'auditors', 'external' => true, 'until' => '2026-07-01T00:00:00+00:00'],
					['user' => 'kim', 'until' => '2026-05-02T00:00:00+00:00'],
				],
			],
			window: new LapseWindow(now: $now, days: 7)
		);

		$this->assertCount(1, $lapsing);
		$this->assertSame([['kind' => 'user', 'id' => 'jan'], ['kind' => 'group', 'id' => 'partners']], $lapsing[0]['holders']);
		$this->assertSame(2, $lapsing[0]['daysLeft']);
		$this->assertSame('jan', $lapsing[0]['principal']);
	}//end testLapseWarningListsHoldersWithinTheHorizonOnly()
}//end class
