<?php

/**
 * Shape tests for GrantConstraints.
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
use OCA\OpenRegister\Service\Rbac\ConstraintPoint;
use OCA\OpenRegister\Service\Rbac\GrantConstraints;
use PHPUnit\Framework\TestCase;

/**
 * Malformed constraints fail closed, and keys that are not rule lists pass through.
 *
 * The clock is fixed and every date carries an explicit offset.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\GrantConstraints
 */
class GrantConstraintsShapeTest extends TestCase {

	/**
	 * The fixed moment.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-05-01T00:00:00+00:00');
	}//end now()

	/**
	 * Empty and missing blocks declare nothing and come back as given.
	 *
	 * @return void
	 */
	public function testEmptyBlocksDeclareNothing(): void {
		$constraints = new GrantConstraints();

		$this->assertFalse($constraints->declaresAnyConstraint(authorization: null));
		$this->assertFalse($constraints->declaresAnyConstraint(authorization: []));
		$this->assertNull($constraints->apply(authorization: null));
		$this->assertSame([], $constraints->apply(authorization: []));
	}//end testEmptyBlocksDeclareNothing()

	/**
	 * A malformed or empty `scopedTo`, one naming no list, and an area value that cannot
	 * be named all fail closed; a blank `until` is an end already passed.
	 *
	 * @return void
	 */
	public function testMalformedConstraintsFailClosed(): void {
		$constraints = new GrantConstraints();
		$area        = ['register' => 'zaken', 'schema' => 'zaak'];

		$this->assertFalse($constraints->reaches(entry: ['group' => 'g', 'scopedTo' => 'zaken'], point: new ConstraintPoint(area: $area, now: $this->now())));
		$this->assertFalse($constraints->reaches(entry: ['group' => 'g', 'scopedTo' => []], point: new ConstraintPoint(area: $area, now: $this->now())));
		$this->assertFalse($constraints->reaches(entry: ['group' => 'g', 'scopedTo' => ['registers' => [], 'other' => ['x']]], point: new ConstraintPoint(area: $area, now: $this->now())));
		$this->assertFalse($constraints->reaches(entry: ['group' => 'g', 'scopedTo' => ['registers' => ['zaken']]], point: new ConstraintPoint(area: ['register' => ['', null]], now: $this->now())));
		$this->assertFalse($constraints->reaches(entry: ['group' => 'g', 'until' => ' '], point: new ConstraintPoint(area: $area, now: $this->now())));
		$this->assertFalse($constraints->reaches(entry: ['group' => 'g', 'until' => 20260601], point: new ConstraintPoint(area: $area, now: $this->now())));
		$this->assertTrue($constraints->reaches(entry: 'staff', point: new ConstraintPoint(area: $area, now: $this->now())));
		$this->assertTrue($constraints->reaches(entry: ['group' => 'g', 'scopedTo' => ['registers' => ['zaken', 7]]], point: new ConstraintPoint(area: $area, now: $this->now())));
	}//end testMalformedConstraintsFailClosed()

	/**
	 * Pseudo keys and scalar values pass through; roles whose holders all lapsed are
	 * dropped, non-list holders kept as written.
	 *
	 * @return void
	 */
	public function testPassThroughAndRoleHolders(): void {
		$applied = (new GrantConstraints())->applyAt(
			authorization: [
				'public'  => [['group' => 'g', 'until' => '2020-01-01T00:00:00+00:00']],
				'scope'   => 'private',
				'mode'    => 'strict',
				7         => ['x'],
				'roles'   => [
					'viewer' => [['group' => 'g', 'until' => '2020-01-01T00:00:00+00:00']],
					'editor' => [['group' => 'e', 'until' => '2030-01-01T00:00:00+00:00'], ['group' => 'f', 'until' => '2020-01-01T00:00:00+00:00']],
					'legacy' => 'staff',
				],
			],
			point: new ConstraintPoint(area: [], now: $this->now())
		);

		$this->assertSame([['group' => 'g', 'until' => '2020-01-01T00:00:00+00:00']], $applied['public']);
		$this->assertSame('private', $applied['scope']);
		$this->assertSame('strict', $applied['mode']);
		$this->assertSame(['x'], $applied[7]);
		$this->assertSame(
			['editor' => [['group' => 'e', 'until' => '2030-01-01T00:00:00+00:00']], 'legacy' => 'staff'],
			$applied['roles']
		);
	}//end testPassThroughAndRoleHolders()
}//end class
