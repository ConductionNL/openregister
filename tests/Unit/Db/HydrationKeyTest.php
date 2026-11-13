<?php

/**
 * OpenRegister HydrationKeyTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\HydrationKey;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A capitalised key does not reach the field its lower-case spelling names.
 *
 * @covers \OCA\OpenRegister\Db\HydrationKey
 */
final class HydrationKeyTest extends TestCase {
	public function testOnlyLowerCaseFirstKeysAreFieldNames(): void {
		$this->assertTrue(HydrationKey::isFieldName(key: 'organisation'));
		$this->assertTrue(HydrationKey::isFieldName(key: 'quota'));
		$this->assertFalse(HydrationKey::isFieldName(key: 'Organisation'));
		$this->assertFalse(HydrationKey::isFieldName(key: ''));
		$this->assertFalse(HydrationKey::isFieldName(key: 0));
	}//end testOnlyLowerCaseFirstKeysAreFieldNames()

	/**
	 * Entities with an owner and an organisation, one per hydrate path that carries them.
	 *
	 * @return array<string, array{0: object}>
	 */
	public static function entities(): array {
		return [
			'empty-json trait (application)' => [new Application()],
			'empty-json hydration (object)'  => [new ObjectEntity()],
		];
	}//end entities()

	#[DataProvider('entities')]
	public function testACapitalisedOwnerOrOrganisationIsNotHydrated(object $entity): void {
		$entity->hydrate(['Owner' => 'mallory', 'Organisation' => 'other-org']);

		$this->assertNull($entity->getOwner());
		$this->assertNull($entity->getOrganisation());
	}//end testACapitalisedOwnerOrOrganisationIsNotHydrated()

	public function testTheLowerCaseSpellingStillHydrates(): void {
		$application = new Application();
		$application->hydrate(['organisation' => 'own-org']);

		$this->assertSame('own-org', $application->getOrganisation());
	}//end testTheLowerCaseSpellingStillHydrates()
}//end class
