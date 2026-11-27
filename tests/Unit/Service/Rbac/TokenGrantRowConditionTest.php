<?php

/**
 * The grant's row condition travels in the marker, its subject resolved.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/tasks.md#1-1d
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use OCA\OpenRegister\Service\Rbac\TokenGrantReach;
use OCA\OpenRegister\Service\Rbac\TokenGrantTarget;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use OCA\OpenRegister\Service\Rbac\TokenGrantUse;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\TokenGrantNarrower
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrant
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantReach
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantTarget
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantTerms
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenGrantUse
 * @uses   \OCA\OpenRegister\Service\Rbac\TokenNarrowing
 */
final class TokenGrantRowConditionTest extends TestCase {

	/**
	 * A block narrowed by a read grant on `zaak` carrying the given condition.
	 *
	 * @param array|null $match   The grant's row condition.
	 * @param string     $tokenId The grant's id.
	 *
	 * @return array<string, mixed> The narrowed block.
	 */
	private function narrowed(?array $match, string $tokenId = 'consumer-7'): array {
		return (new TokenGrantNarrower())->narrow(
			authorization: ['read' => ['leveranciers']],
			use: new TokenGrantUse(
				grant: new TokenGrant(
					verbs: ['read'],
					reach: new TokenGrantReach(schemas: ['zaak'], match: $match),
					terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('+1 year')),
					tokenId: $tokenId
				),
				target: new TokenGrantTarget(schemaSlug: 'zaak', registerSlug: 'zaken')
			)
		);
	}//end narrowed()

	public function testTheConditionIsCarriedWithTheTokenSubjectResolved(): void {
		$block = $this->narrowed(match: ['leverancier' => TokenGrantNarrower::TOKEN_SUBJECT, 'status' => 'open']);

		$this->assertSame(
			['leverancier' => 'consumer-7', 'status' => 'open'],
			$block[TokenGrantNarrower::MARKER]['match']
		);
		$this->assertSame(
			['leverancier' => 'consumer-7', 'status' => 'open'],
			(new TokenGrantNarrower())->markerMatch(authorization: $block)
		);
	}//end testTheConditionIsCarriedWithTheTokenSubjectResolved()

	public function testTheSubjectIsResolvedInsideAnOperator(): void {
		$block = $this->narrowed(match: ['leverancier' => ['$in' => [TokenGrantNarrower::TOKEN_SUBJECT, 'gedeeld']]]);

		$this->assertSame(
			['leverancier' => ['$in' => ['consumer-7', 'gedeeld']]],
			(new TokenGrantNarrower())->markerMatch(authorization: $block)
		);
	}//end testTheSubjectIsResolvedInsideAnOperator()

	public function testAGrantWithoutAnIdResolvesTheSubjectToNoRow(): void {
		$block = $this->narrowed(match: ['leverancier' => TokenGrantNarrower::TOKEN_SUBJECT], tokenId: '');

		$this->assertSame(
			['leverancier' => TokenGrantNarrower::IMPOSSIBLE],
			(new TokenGrantNarrower())->markerMatch(authorization: $block)
		);
	}//end testAGrantWithoutAnIdResolvesTheSubjectToNoRow()

	public function testAGrantWithoutAConditionCarriesNone(): void {
		$block = $this->narrowed(match: null);

		$this->assertArrayNotHasKey('match', $block[TokenGrantNarrower::MARKER]);
		$this->assertNull((new TokenGrantNarrower())->markerMatch(authorization: $block));
		$this->assertNull((new TokenGrantNarrower())->markerMatch(authorization: $this->narrowed(match: [])));
	}//end testAGrantWithoutAConditionCarriesNone()

	public function testABlockWithoutAMarkerHasNoCondition(): void {
		$narrower = new TokenGrantNarrower();

		$this->assertNull($narrower->markerMatch(authorization: null));
		$this->assertNull($narrower->markerMatch(authorization: ['read' => ['public']]));
	}//end testABlockWithoutAMarkerHasNoCondition()

	public function testAnUnreadableConditionMeetsNoRowInsteadOfEveryRow(): void {
		$narrower = new TokenGrantNarrower();
		$impossible = ['_owner' => TokenGrantNarrower::IMPOSSIBLE];

		foreach (['not an object', [], ['a', 'list']] as $match) {
			$block = ['read' => ['x'], TokenGrantNarrower::MARKER => ['verbs' => ['read'], 'match' => $match]];
			$this->assertSame($impossible, $narrower->markerMatch(authorization: $block), var_export($match, true));
		}
	}//end testAnUnreadableConditionMeetsNoRowInsteadOfEveryRow()

	public function testAConditionInTheSchemaBlockCannotStandInForTheGrants(): void {
		// The block declares a marker of its own with a condition that every
		// row meets; the grant has none, so the narrowed marker must have none.
		$block = (new TokenGrantNarrower())->narrow(
			authorization: ['read' => ['x'], TokenGrantNarrower::MARKER => ['verbs' => ['read'], 'match' => ['a' => 'b']]],
			use: new TokenGrantUse(
				grant: new TokenGrant(verbs: ['read'], terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('+1 year')), tokenId: 't'),
				target: new TokenGrantTarget(schemaSlug: 'zaak', registerSlug: null)
			)
		);

		$this->assertNull((new TokenGrantNarrower())->markerMatch(authorization: $block));
	}//end testAConditionInTheSchemaBlockCannotStandInForTheGrants()
}//end class
