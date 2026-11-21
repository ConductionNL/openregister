<?php

/**
 * Characterisation tests for ExampleSetProfiles::fromPerson: the defaults of
 * every part a cast person may leave out.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-person-may-carry-a-function-a-role-an-absence-and-a-status
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetProfiles;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ExampleSetProfilesFromPersonTest extends TestCase {

	private function profile(array $person): array {
		$profiles = (new ReflectionClass(ExampleSetProfiles::class))->newInstanceWithoutConstructor();

		return $profiles->fromPerson(person: $person, today: new DateTimeImmutable('2026-03-01'));
	}

	public function testAnEmptyPersonHasAnEmptyProfile(): void {
		$this->assertSame(
			['headline' => '', 'role' => '', 'organisation' => '', 'absence' => null, 'status' => null],
			$this->profile([])
		);
	}

	public function testEmptyAbsenceAndStatusTakeTheirDefaults(): void {
		$profile = $this->profile(['absence' => [], 'status' => [], 'roles' => 'Intaker', 'function' => 7]);

		$this->assertSame(
			[
				'headline' => '7',
				'role' => 'Intaker',
				'organisation' => '',
				'absence' => ['firstDay' => '2026-03-01', 'lastDay' => '2026-03-01', 'status' => '', 'message' => ''],
				'status' => ['status' => 'online', 'message' => '', 'icon' => ''],
			],
			$profile
		);
	}

	public function testAbsenceDaysCountFromTheInstallDayAndPartsThatAreNoListAreIgnored(): void {
		$profile = $this->profile(
			[
				'absence' => ['fromDay' => '3', 'untilDay' => -1, 'status' => 'Ziek'],
				'status' => 'busy',
				'roles' => [],
				'department' => 'KCC',
			]
		);

		$this->assertSame(['firstDay' => '2026-03-04', 'lastDay' => '2026-02-28', 'status' => 'Ziek', 'message' => ''], $profile['absence']);
		$this->assertNull($profile['status']);
		$this->assertSame('', $profile['role']);
		$this->assertSame('KCC', $profile['organisation']);
	}
}//end class
