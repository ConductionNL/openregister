<?php

/**
 * Unit test for DelegationResolver's scope match and its quietest refusal: a
 * live grant that lacks a scope key, or holds a different scalar value, is out
 * of scope; a grant in a status nobody ranks gives no reason at all.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Delegation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Delegation;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapperInterface;
use OCA\OpenRegister\Service\Delegation\DelegationRequest;
use OCA\OpenRegister\Service\Delegation\DelegationResolver;
use OCA\OpenRegister\Service\Delegation\DelegationVerdict;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Delegation\DelegationResolver
 * @uses   \OCA\OpenRegister\Service\Delegation\DelegationVerdict
 * @uses   \OCA\OpenRegister\Db\DelegationGrant
 */
class DelegationResolverScopeTest extends TestCase {

	/**
	 * A fixed moment.
	 *
	 * @return DateTime
	 */
	private static function now(): DateTime {
		return new DateTime('2026-11-09 12:00:00', new DateTimeZone('UTC'));
	}//end now()

	/**
	 * A grant with the given status and scope.
	 *
	 * @param string                    $status The status.
	 * @param array<string, mixed>|null $scope  The scope.
	 *
	 * @return DelegationGrant
	 */
	private static function grant(string $status, ?array $scope): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setStatus($status);
		$grant->setScope($scope);
		return $grant;
	}//end grant()

	/**
	 * The verdict over the given candidates for the given scope.
	 *
	 * @param array<int, DelegationGrant> $candidates The grants the store holds.
	 * @param array<string, mixed>        $scope      The scope asked for.
	 *
	 * @return DelegationVerdict
	 */
	private function verdict(array $candidates, array $scope): DelegationVerdict {
		$grants = $this->createMock(DelegationGrantMapperInterface::class);
		$grants->method('findFor')->with('anna', 'acme')->willReturn($candidates);
		return (new DelegationResolver(grants: $grants, logger: $this->createMock(LoggerInterface::class)))
			->resolve(new DelegationRequest(principal: 'anna', actingAs: 'acme', scope: $scope, now: self::now()));
	}//end verdict()

	/**
	 * A live grant that matches every key, list or scalar, is granted.
	 *
	 * @return void
	 */
	public function testAMatchingScopeIsGranted(): void {
		$grant   = self::grant(status: DelegationGrant::STATUS_GRANTED, scope: ['schema' => 'case', 'actions' => ['read', 'update']]);
		$verdict = $this->verdict(candidates: [$grant], scope: ['schema' => 'case', 'actions' => ['read']]);

		$this->assertTrue($verdict->permitted);
		$this->assertSame($grant, $verdict->grant);
	}//end testAMatchingScopeIsGranted()

	/**
	 * A live grant missing a scope key, or holding another scalar value, is out of scope.
	 *
	 * @return void
	 */
	public function testAMissingKeyOrAnotherValueIsOutOfScope(): void {
		$grant = self::grant(status: DelegationGrant::STATUS_GRANTED, scope: ['schema' => 'case']);

		$missing = $this->verdict(candidates: [$grant], scope: ['register' => 'zaken']);
		$this->assertFalse($missing->permitted);
		$this->assertSame(DelegationVerdict::REASON_OUT_OF_SCOPE, $missing->reason);

		$other = $this->verdict(candidates: [$grant], scope: ['schema' => 'permit']);
		$this->assertFalse($other->permitted);
		$this->assertSame(DelegationVerdict::REASON_OUT_OF_SCOPE, $other->reason);
		$this->assertSame('"anna" holds no live grant to act as "acme".', $other->detail);
	}//end testAMissingKeyOrAnotherValueIsOutOfScope()

	/**
	 * A grant in a status nobody ranks gives no reason, and an out-of-scope one next to it wins.
	 *
	 * @return void
	 */
	public function testAnUnrankedStatusGivesNoReason(): void {
		$odd = self::grant(status: DelegationGrant::STATUS_EXPIRED, scope: null);

		$this->assertSame(DelegationVerdict::REASON_NONE, $this->verdict(candidates: [$odd], scope: [])->reason);
		$this->assertSame(
			DelegationVerdict::REASON_OUT_OF_SCOPE,
			$this->verdict(candidates: [$odd, self::grant(status: DelegationGrant::STATUS_GRANTED, scope: [])], scope: ['schema' => 'case'])->reason
		);
	}//end testAnUnrankedStatusGivesNoReason()
}//end class
