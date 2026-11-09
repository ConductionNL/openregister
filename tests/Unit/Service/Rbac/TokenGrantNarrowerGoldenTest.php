<?php

/**
 * Golden test for TokenGrantNarrower::narrow() and markerPermits().
 *
 * Pins, on the code before narrow() was split, the exact narrowed block for a
 * matrix of authorization blocks (null, empty, verbs with control keys, a
 * declared marker) and grants (none, expired, never-expiring, out of schema or
 * register scope, a subset of verbs, an ungrantable verb, no verbs), and what
 * markerPermits() answers for each verb on the result — allowed AND refused.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantReach;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\TokenGrantNarrower
 */
final class TokenGrantNarrowerGoldenTest extends TestCase {

	private const X = TokenGrantNarrower::IMPOSSIBLE;

	private const M = TokenGrantNarrower::MARKER;

	/**
	 * The fixed "now" every case is decided at.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('2030-01-01T00:00:00+00:00');
	}//end now()

	/**
	 * A grant valid until a day after now unless told otherwise.
	 *
	 * @param string[]      $verbs     The verbs.
	 * @param array|null    $schemas   Schema scope.
	 * @param array|null    $registers Register scope.
	 * @param string|null   $expires   Expiry, or null for none.
	 *
	 * @return TokenGrant
	 */
	private static function grant(
		array $verbs,
		?array $schemas = null,
		?array $registers = null,
		?string $expires = '2030-01-02T00:00:00+00:00'
	): TokenGrant {
		$expiresAt = null;
		if ($expires !== null) {
			$expiresAt = new DateTimeImmutable($expires);
		}

		return new TokenGrant($verbs, new TokenGrantReach($registers, $schemas), new TokenGrantTerms($expiresAt), 'tok-1');
	}//end grant()

	/**
	 * Every combination's narrowed block and marker answers.
	 *
	 * @return void
	 */
	public function testNarrowGolden(): void {
		$narrower = new TokenGrantNarrower();
		$blocks   = [
			'null' => null,
			'empty' => [],
			'verbs' => ['read' => ['staff'], 'update' => ['staff'], 'delete' => ['admin'], 'roles' => ['viewer' => ['x']], 'public' => true, 7 => ['odd']],
			'declared-marker' => ['read' => ['public'], self::M => ['verbs' => ['read', 'update', 'delete']]],
		];
		$grants = [
			'read-update' => self::grant(['read', 'update']),
			'manage-only' => self::grant(['manage', 'read']),
			'no-verbs' => self::grant([]),
			'expired' => self::grant(['read'], null, null, '2029-12-31T00:00:00+00:00'),
			'expires-now' => self::grant(['read'], null, null, '2030-01-01T00:00:00+00:00'),
			'never-expires' => self::grant(['read'], null, null, null),
			'schema-scoped-in' => self::grant(['read'], ['zaken']),
			'schema-scoped-out' => self::grant(['read'], ['besluiten']),
			'register-scoped-out' => self::grant(['read'], null, ['other']),
			'register-scoped-in' => self::grant(['read', 'delete'], ['zaken'], ['main']),
		];

		$expected = [
			'null|read-update' => [self::M => ['verbs' => ['read', 'update'], 'tokenId' => 'tok-1', 'expired' => false, 'inScope' => true]],
			'null|manage-only' => [self::M => ['verbs' => ['read'], 'tokenId' => 'tok-1', 'expired' => false, 'inScope' => true]],
			'null|no-verbs' => (
				[self::M => ['verbs' => [], 'tokenId' => 'tok-1', 'expired' => false, 'inScope' => true]]
				+ array_fill_keys(array_keys(PermissionCatalogueInterface::CANONICAL), [self::X])
			),
		];

		$actual = [];
		foreach ($blocks as $blockKey => $block) {
			foreach ($grants as $grantKey => $grant) {
				$actual[$blockKey.'|'.$grantKey] = $narrower->narrow($block, $grant, 'zaken', 'main', self::now());
			}
		}

		foreach ($expected as $key => $block) {
			$this->assertSame($block, $actual[$key], $key);
		}

		// Expired / out of scope / no verbs: every verb named is refused and the marker permits nothing.
		foreach (['expired', 'expires-now', 'never-expires', 'schema-scoped-out', 'register-scoped-out', 'no-verbs'] as $grantKey) {
			foreach (array_keys($blocks) as $blockKey) {
				$result = $actual[$blockKey.'|'.$grantKey];
				$this->assertSame([], $result[self::M]['verbs'], $blockKey.'|'.$grantKey);
				foreach (['read', 'create', 'update', 'delete', 'list', 'export', 'manage'] as $verb) {
					$this->assertFalse($narrower->markerPermits($result, $verb), $blockKey.'|'.$grantKey.'|'.$verb);
					$this->assertSame([self::X], $result[$verb], $blockKey.'|'.$grantKey.'|'.$verb);
				}
			}
		}

		$this->assertTrue($actual['verbs|expired'][self::M]['expired']);
		$this->assertTrue($actual['verbs|expires-now'][self::M]['expired']);
		$this->assertTrue($actual['verbs|never-expires'][self::M]['expired']);
		$this->assertFalse($actual['verbs|schema-scoped-out'][self::M]['expired']);
		$this->assertFalse($actual['verbs|schema-scoped-out'][self::M]['inScope']);
		$this->assertFalse($actual['verbs|register-scoped-out'][self::M]['inScope']);
		$this->assertTrue($actual['verbs|expired'][self::M]['inScope']);

		// A block with verbs: unpermitted ones refused, permitted ones kept, control keys kept.
		$this->assertSame(
			[
				'read' => ['staff'],
				'update' => ['staff'],
				'delete' => [self::X],
				'roles' => ['viewer' => ['x']],
				'public' => true,
				7 => [self::X],
				self::M => ['verbs' => ['read', 'update'], 'tokenId' => 'tok-1', 'expired' => false, 'inScope' => true],
			],
			$actual['verbs|read-update']
		);
		$this->assertSame(
			[
				'read' => ['staff'],
				'update' => [self::X],
				'delete' => ['admin'],
				'roles' => ['viewer' => ['x']],
				'public' => true,
				7 => [self::X],
				self::M => ['verbs' => ['read', 'delete'], 'tokenId' => 'tok-1', 'expired' => false, 'inScope' => true],
			],
			$actual['verbs|register-scoped-in']
		);

		// A declared marker is overwritten by the token's own.
		$this->assertSame(
			['read' => ['public'], self::M => ['verbs' => ['read'], 'tokenId' => 'tok-1', 'expired' => false, 'inScope' => true]],
			$actual['declared-marker|schema-scoped-in']
		);
		$this->assertTrue($narrower->markerPermits($actual['declared-marker|schema-scoped-in'], 'read'));
		$this->assertFalse($narrower->markerPermits($actual['declared-marker|schema-scoped-in'], 'update'));
		$this->assertFalse($narrower->markerPermits($actual['verbs|manage-only'], 'manage'));
		$this->assertSame([self::X], $actual['verbs|manage-only']['update']);
		$this->assertSame([self::X], $actual['empty|no-verbs']['read']);
		$this->assertSame(['verbs' => ['read', 'update'], 'tokenId' => 'tok-1', 'expired' => false, 'inScope' => true], $actual['empty|read-update'][self::M]);
		$this->assertCount(1, $actual['empty|read-update']);
	}//end testNarrowGolden()

	/**
	 * Without a grant the block passes through, minus any declared marker.
	 *
	 * @return void
	 */
	public function testNoGrantStripsADeclaredMarkerOnly(): void {
		$narrower = new TokenGrantNarrower();
		$this->assertNull($narrower->narrow(null, null, 'zaken', 'main', self::now()));
		$this->assertSame([], $narrower->narrow([], null, null, null));
		$this->assertSame(['read' => ['x']], $narrower->narrow(['read' => ['x']], null, null, null));
		$this->assertSame(['read' => ['x']], $narrower->narrow(['read' => ['x'], self::M => ['verbs' => ['delete']]], null, null, null));
		$this->assertSame([], $narrower->narrow([self::M => null], null, null, null));
	}//end testNoGrantStripsADeclaredMarkerOnly()

	/**
	 * A grant decided at the real clock, far from its expiry either way.
	 *
	 * @return void
	 */
	public function testTheDefaultClockIsUsedWhenNoneIsGiven(): void {
		$narrower = new TokenGrantNarrower();
		$this->assertSame(['read'], $narrower->narrow([], self::grant(['read'], null, null, '2999-01-01T00:00:00+00:00'), null, null)[self::M]['verbs']);
		$this->assertSame([], $narrower->narrow([], self::grant(['read'], null, null, '2000-01-01T00:00:00+00:00'), null, null)[self::M]['verbs']);
	}//end testTheDefaultClockIsUsedWhenNoneIsGiven()

	/**
	 * What markerPermits answers for unmarked, malformed and marked blocks.
	 *
	 * @return void
	 */
	public function testMarkerPermitsGolden(): void {
		$narrower = new TokenGrantNarrower();
		// Allowed: nothing to narrow.
		$this->assertTrue($narrower->markerPermits(null, 'read'));
		$this->assertTrue($narrower->markerPermits([], 'read'));
		$this->assertTrue($narrower->markerPermits(['read' => []], 'delete'));
		$this->assertTrue($narrower->markerPermits([self::M => 'x'], 'delete'));
		$this->assertTrue($narrower->markerPermits([self::M => ['verbs' => ['read']]], 'read'));
		// Refused: a marker that cannot be read, or that does not carry the verb.
		$this->assertFalse($narrower->markerPermits([self::M => []], 'read'));
		$this->assertFalse($narrower->markerPermits([self::M => ['verbs' => 'read']], 'read'));
		$this->assertFalse($narrower->markerPermits([self::M => ['verbs' => ['read']]], 'update'));
		$this->assertFalse($narrower->markerPermits([self::M => ['verbs' => []]], 'read'));
	}//end testMarkerPermitsGolden()
}//end class
