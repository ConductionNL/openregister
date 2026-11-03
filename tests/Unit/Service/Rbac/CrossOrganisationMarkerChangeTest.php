<?php

/**
 * A new authorization block widens across organisations exactly when it
 * holds a marked rule the old block did not hold in that form.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\CrossOrganisationMarkerChange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\CrossOrganisationMarkerChange
 */
class CrossOrganisationMarkerChangeTest extends TestCase {

	private const MARKED = ['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation'], 'crossOrganisation' => true];

	/**
	 * @return array<string, array{0: mixed, 1: mixed, 2: bool}>
	 */
	public static function changes(): array {
		$marked = self::MARKED;
		$plain = ['group' => 'aanbod-beheerder', 'match' => ['provider' => '$organisation']];

		return [
			'a marker is added to a read rule'            => [['read' => [$plain]], ['read' => [$marked]], true],
			'false becomes true'                          => [['read' => [['crossOrganisation' => false] + $plain]], ['read' => [$marked]], true],
			'a new marked rule is added'                  => [['read' => ['hr']], ['read' => ['hr', $marked]], true],
			'a marked rule is added on another action'    => [['read' => [$marked]], ['read' => [$marked], 'update' => [$marked]], true],
			'a second copy of a marked rule'              => [['read' => [$marked]], ['read' => [$marked, $marked]], true],
			'a marked rule gets another group'            => [['read' => [$marked]], ['read' => [['group' => 'iedereen'] + $marked]], true],
			'a marked rule loses its match'               => [['read' => [$marked]], ['read' => [['group' => 'aanbod-beheerder', 'crossOrganisation' => true]]], true],
			'a matrix row is marked'                      => [['matrix' => [['group' => 'team', 'action' => 'read']]], ['matrix' => [['group' => 'team', 'action' => 'read', 'crossOrganisation' => true]]], true],
			'from no authorization to a marked rule'      => [null, ['read' => [$marked]], true],
			'the marked rule stays, other rules change'   => [['read' => [$marked], 'update' => ['hr']], ['read' => ['team', $marked], 'update' => []], false],
			'the same rule with its keys in another order' => [['read' => [$marked]], ['read' => [['crossOrganisation' => true, 'match' => ['provider' => '$organisation'], 'group' => 'aanbod-beheerder']]], false],
			'a marker is removed'                         => [['read' => [$marked]], ['read' => [$plain]], false],
			'a marker is set to false'                    => [['read' => [$plain]], ['read' => [['crossOrganisation' => false] + $plain]], false],
			'a truthy value that is not true'             => [['read' => [$plain]], ['read' => [['crossOrganisation' => 'yes'] + $plain]], false],
			'no authorization at all'                     => [null, null, false],
		];
	}//end changes()

	#[DataProvider('changes')]
	public function testWidensOnlyWhenACrossingIsAdded(mixed $old, mixed $new, bool $widens): void {
		self::assertSame($widens, (new CrossOrganisationMarkerChange())->widens(old: $old, new: $new));
	}//end testWidensOnlyWhenACrossingIsAdded()
}//end class
