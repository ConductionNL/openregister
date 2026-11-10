<?php

/**
 * Golden tests for the ZGW autorisatie mapping and the permission matrix.
 *
 * Pins, on the code as it stood before those methods were split, the exact
 * authorization block mapZgwAutorisatie() builds for a mixed scope list
 * (repeated verbs, scopes without a dot, empty, non-string and unknown
 * scopes) with and without a clearance, and the exact matrix
 * extractPermissionMatrix() derives from a block holding every entry shape
 * it accepts and every one it drops.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\CaseTypeAuthorizationService;
use PHPUnit\Framework\TestCase;

/**
 * Golden tests for the mapping and matrix of CaseTypeAuthorizationService.
 */
class CaseTypeAuthorizationServiceGoldenTest extends TestCase {

	/**
	 * Scopes covering every branch of the mapping.
	 *
	 * @var array<int, mixed>
	 */
	private const SCOPES = [
		'zaken.lezen',
		'',
		42,
		'zaken.onbekend',
		'aanmaken',
		'documenten.lezen',
		'a.b.verwijderen',
		null,
		'zaken.bijwerken.',
	];

	/**
	 * Without a clearance every recognised scope grants the bare group.
	 *
	 * @return void
	 */
	public function testMappingWithoutClearance(): void {
		$service = new CaseTypeAuthorizationService();

		$this->assertSame(
			['read' => ['g', 'g'], 'create' => ['g'], 'delete' => ['g']],
			$service->mapZgwAutorisatie(self::SCOPES, 'g')
		);
	}//end testMappingWithoutClearance()

	/**
	 * With a clearance every grant carries the same confidentiality match.
	 *
	 * @return void
	 */
	public function testMappingWithClearance(): void {
		$service = new CaseTypeAuthorizationService();
		$rule = ['group' => 'g', 'match' => ['vt' => ['$in' => ['openbaar', 'beperkt_openbaar', 'intern']]]];

		$this->assertSame(
			['read' => [$rule, $rule], 'create' => [$rule], 'delete' => [$rule]],
			$service->mapZgwAutorisatie(self::SCOPES, 'g', 'intern', 'vt')
		);
	}//end testMappingWithClearance()

	/**
	 * An unknown clearance still grants, under a match nothing satisfies.
	 *
	 * @return void
	 */
	public function testMappingWithUnknownClearanceFailsClosed(): void {
		$service = new CaseTypeAuthorizationService();

		$this->assertSame(
			['read' => [['group' => 'g', 'match' => ['vertrouwelijkheidaanduiding' => ['$in' => []]]]]],
			$service->mapZgwAutorisatie(['zaken.lezen'], 'g', 'bogus')
		);
		$this->assertSame([], $service->mapZgwAutorisatie([], 'g', 'intern'));
		$this->assertSame([], $service->mapZgwAutorisatie(['', 'x.y'], 'g'));
	}//end testMappingWithUnknownClearanceFailsClosed()

	/**
	 * The matrix keeps every accepted shape, in order, and drops the rest.
	 *
	 * @return void
	 */
	public function testMatrixOverEveryEntryShape(): void {
		$service = new CaseTypeAuthorizationService();
		$authorization = [
			'inheritFromPublic' => true,
			'roles' => ['x'],
			0 => ['g0'],
			'read' => [
				'public',
				'user:bob',
				['group' => 'staff', 'match' => ['a' => 1]],
				['group' => 'staff2', 'match' => []],
				['user' => 'carol', 'group' => 'ignored'],
				['user' => 5, 'group' => 'fallback', 'match' => ['b' => 2]],
				['user' => 5],
				['match' => ['c' => 3]],
				7,
				null,
				'',
			],
			'list' => ['everyone'],
			'update' => 'not-a-list',
			'delete' => [],
			'create' => [['group' => ['nested']], 'user:'],
		];

		$this->assertSame(
			[
				['action' => 'read', 'principal' => 'public', 'kind' => 'group', 'conditional' => false],
				['action' => 'read', 'principal' => 'user:bob', 'kind' => 'user', 'conditional' => false],
				['action' => 'read', 'principal' => 'staff', 'kind' => 'group', 'conditional' => true],
				['action' => 'read', 'principal' => 'staff2', 'kind' => 'group', 'conditional' => false],
				['action' => 'read', 'principal' => 'user:carol', 'kind' => 'user', 'conditional' => false],
				['action' => 'read', 'principal' => 'fallback', 'kind' => 'group', 'conditional' => true],
				['action' => 'read', 'principal' => '', 'kind' => 'group', 'conditional' => false],
				['action' => 'list', 'principal' => 'everyone', 'kind' => 'group', 'conditional' => false],
				['action' => 'create', 'principal' => 'user:', 'kind' => 'user', 'conditional' => false],
			],
			$service->extractPermissionMatrix($authorization)
		);
	}//end testMatrixOverEveryEntryShape()

	/**
	 * A missing or empty block has no matrix.
	 *
	 * @return void
	 */
	public function testMatrixOfNothing(): void {
		$service = new CaseTypeAuthorizationService();

		$this->assertSame([], $service->extractPermissionMatrix(null));
		$this->assertSame([], $service->extractPermissionMatrix([]));
		$this->assertSame([], $service->extractPermissionMatrix(['roles' => ['read' => ['g']]]));
	}//end testMatrixOfNothing()
}//end class
