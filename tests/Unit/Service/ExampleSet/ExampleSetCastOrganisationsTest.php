<?php

/**
 * Unit test for ExampleSetCastOrganisations: the organisations a cast declares, read into the plan.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-organisation-may-declare-its-quota-and-its-members
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetCastOrganisations;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastOrganisations
 */
class ExampleSetCastOrganisationsTest extends TestCase {
	/**
	 * A declared organisation is read with its quota and its members' accounts.
	 *
	 * @return void
	 */
	public function testAnOrganisationIsReadWithItsQuotaAndMembers(): void {
		$entities = (new ExampleSetCastOrganisations())->entities(
			declared: [['key' => 'g', 'name' => 'Gemeente', 'slug' => 'gemeente', 'quota' => ['users' => 0, 'objectsSchema' => 'case'], 'members' => ['sanne']]],
			file: 'cast.json',
			people: [['key' => 'sanne', 'uid' => 'sanne.devries'], ['key' => 'nouid']]
		);
		$this->assertSame(
			[
				[
					'key'          => 'g',
					'name'         => 'Gemeente',
					'slug'         => 'gemeente',
					'description'  => '',
					'type'         => '',
					'contactEmail' => '',
					'quota'        => ['users' => 0, 'objectsSchema' => 'case'],
					'members'      => ['sanne.devries'],
				],
			],
			$entities
		);
	}//end testAnOrganisationIsReadWithItsQuotaAndMembers()

	/**
	 * Each refusal names the organisation and what is wrong.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refused(): array {
		$ok = ['key' => 'g', 'name' => 'G', 'slug' => 's'];
		$quota = 'cast.json: OpenRegister organisation "s": quota "%s" must be storage, requests, bandwidth, users or objects with a whole number, or objectsSchema with a schema slug.';
		return [
			'no slug'         => [['key' => 'g', 'name' => 'G'], 'cast.json: OpenRegister organisation 0 needs a key, a name and a slug.'],
			'negative'        => [$ok + ['quota' => ['storage' => -1]], sprintf($quota, 'storage')],
			'text count'      => [$ok + ['quota' => ['users' => '3']], sprintf($quota, 'users')],
			'unknown'         => [$ok + ['quota' => ['seats' => 3]], sprintf($quota, 'seats')],
			'empty schema'    => [$ok + ['quota' => ['objectsSchema' => '']], sprintf($quota, 'objectsSchema')],
			'number schema'   => [$ok + ['quota' => ['objectsSchema' => 3]], sprintf($quota, 'objectsSchema')],
			'stranger member' => [$ok + ['members' => ['nouid']], 'cast.json: OpenRegister organisation "s": member "nouid" is not a cast person with an account.'],
		];
	}//end refused()

	/**
	 * A faulty organisation refuses the set with its exact message.
	 *
	 * @param array<string, mixed> $organisation The declared organisation.
	 * @param string               $message      The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refused
	 */
	public function testAFaultyOrganisationIsRefused(array $organisation, string $message): void {
		$this->expectException(ExampleSetException::class);
		$this->expectExceptionMessage($message);
		(new ExampleSetCastOrganisations())->entities(declared: [$organisation], file: 'cast.json', people: [['key' => 'nouid']]);
	}//end testAFaultyOrganisationIsRefused()
}//end class
