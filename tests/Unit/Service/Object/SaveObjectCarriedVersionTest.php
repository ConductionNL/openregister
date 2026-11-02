<?php

/**
 * A version a save carries in `@self.version` counts only for a trusted caller.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\ObjectVersionHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The seed channel (a stored seed follows a higher shipped version) must not be steerable from the object API.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject
 * @covers \OCA\OpenRegister\Service\Object\ObjectVersionHandler
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 */
class SaveObjectCarriedVersionTest extends TestCase {

	/**
	 * The `@self` an update works from, for a caller judged by RBAC or not.
	 *
	 * @param array<string, mixed> $selfData The `@self` the caller sent.
	 * @param bool                 $rbac     Whether RBAC applies to the save.
	 *
	 * @return array<string, mixed> The `@self` the update uses.
	 */
	private function selfDataForUpdate(array $selfData, bool $rbac): array {
		$save = (new ReflectionClass(SaveObject::class))->newInstanceWithoutConstructor();

		return (new ReflectionMethod(SaveObject::class, 'selfDataForUpdate'))->invoke($save, $selfData, $rbac);
	}//end selfDataForUpdate()

	/**
	 * The version an update ends on, given the `@self` it works from.
	 *
	 * @param array<string, mixed> $selfData The `@self` the update works from.
	 *
	 * @return string|null The stored version afterwards.
	 */
	private function versionAfter(array $selfData): ?string {
		$entity = new ObjectEntity();
		$entity->setVersion('1.0.3');
		$carried = null;
		if (is_scalar($selfData['version'] ?? null) === true) {
			$carried = (string)$selfData['version'];
		}

		(new ObjectVersionHandler())->advanceVersion(entity: $entity, carried: $carried);

		return $entity->getVersion();
	}//end versionAfter()

	/**
	 * A caller judged by RBAC cannot set a higher version: the update bumps the patch like any other.
	 *
	 * Goes red without the filter: the object would end on 9.9.9 and the
	 * next shipped version of the seed would never be taken.
	 *
	 * @return void
	 */
	public function testAUserCannotSetAHigherVersion(): void {
		$selfData = $this->selfDataForUpdate(selfData: ['version' => '9.9.9', 'slug' => 'woo'], rbac: true);

		$this->assertArrayNotHasKey('version', $selfData);
		$this->assertSame('woo', $selfData['slug'], 'Nothing else is touched.');
		$this->assertSame('1.0.4', $this->versionAfter(selfData: $selfData));
	}//end testAUserCannotSetAHigherVersion()

	/**
	 * The importer, an administrator and a system caller (RBAC off) keep the version they carry.
	 *
	 * @return void
	 */
	public function testATrustedCallerKeepsTheCarriedVersion(): void {
		$selfData = $this->selfDataForUpdate(selfData: ['version' => '9.9.9'], rbac: false);

		$this->assertSame(['version' => '9.9.9'], $selfData);
		$this->assertSame('9.9.9', $this->versionAfter(selfData: $selfData));
	}//end testATrustedCallerKeepsTheCarriedVersion()
}//end class
