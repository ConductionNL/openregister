<?php

/**
 * Unit tests for the store install target: the manifest block, the id check,
 * the installer stamping each object and the stamp on a bundle.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Store
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/store-install-target/specs/apphost-store-plane/spec.md#requirement-an-install-can-go-into-a-target-the-app-declares
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Store;

use OCA\OpenRegister\AppHost\Store\GenericStoreInstaller;
use OCA\OpenRegister\AppHost\Store\StoreInstallTarget;
use OCA\OpenRegister\AppHost\Store\StoreManifest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\BundleObjectStamp;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An install can go into a target the app declares.
 */
class StoreInstallTargetTest extends TestCase {

	/**
	 * A store whose installs can go into a world.
	 *
	 * @return StoreManifest
	 */
	private function worldStore(): StoreManifest {
		return StoreManifest::fromManifest(
			'larpinq',
			[
				'store' => [
					'schema' => 'bundle',
					'installable' => ['larping_skill', 'condition'],
					'installTarget' => ['property' => 'setting', 'schemas' => ['larping_skill', 'condition', '', 7]],
				],
			]
		);
	}//end worldStore()

	/**
	 * The manifest block is read; non-string schema entries are dropped.
	 *
	 * @return void
	 */
	public function testTheManifestDeclaresThePropertyAndSchemas(): void {
		$target = $this->worldStore()->installTarget;

		$this->assertTrue($target->isDeclared());
		$this->assertSame('setting', $target->property);
		$this->assertSame(['larping_skill', 'condition'], $target->schemas);
	}//end testTheManifestDeclaresThePropertyAndSchemas()

	/**
	 * No block, or a malformed one, declares no target.
	 *
	 * @return void
	 */
	public function testAnAbsentOrMalformedBlockDeclaresNoTarget(): void {
		$this->assertFalse(StoreManifest::fromManifest('larpinq', ['store' => ['schema' => 'bundle']])->installTarget->isDeclared());
		$this->assertFalse(StoreInstallTarget::fromBlock(block: 'setting')->isDeclared());
		$this->assertFalse(StoreInstallTarget::fromBlock(block: ['property' => ['x'], 'schemas' => ['a']])->isDeclared());
		$this->assertFalse(StoreInstallTarget::fromBlock(block: ['property' => 'setting', 'schemas' => []])->isDeclared());
		$this->assertFalse(StoreInstallTarget::fromBlock(block: ['property' => 'setting', 'schemas' => 'a'])->isDeclared());
	}//end testAnAbsentOrMalformedBlockDeclaresNoTarget()

	/**
	 * A UUID, a number or a slug is a target; a path, a query or an empty string is not.
	 *
	 * @return void
	 */
	public function testOnlyIdsAreWellFormedTargets(): void {
		$this->assertTrue(StoreInstallTarget::isWellFormed(target: '0b6f1c3e-2c3a-4f7e-9d1a-5c2e8a1b4d6f'));
		$this->assertTrue(StoreInstallTarget::isWellFormed(target: '42'));
		$this->assertTrue(StoreInstallTarget::isWellFormed(target: 'aldmoor_valley'));
		$this->assertFalse(StoreInstallTarget::isWellFormed(target: ''));
		$this->assertFalse(StoreInstallTarget::isWellFormed(target: '../other'));
		$this->assertFalse(StoreInstallTarget::isWellFormed(target: 'a?b=c'));
		$this->assertFalse(StoreInstallTarget::isWellFormed(target: str_repeat('a', 65)));
	}//end testOnlyIdsAreWellFormedTargets()

	/**
	 * Without a declaration the stamp writes nothing.
	 *
	 * @return void
	 */
	public function testAnUndeclaredTargetStampsNothing(): void {
		$stamp = (new StoreInstallTarget())->stampFor(target: 'world-1');

		$this->assertTrue($stamp->isEmpty());
		$this->assertSame(['name' => 'x'], $stamp->applyToObject(schema: 'larping_skill', object: ['name' => 'x']));
	}//end testAnUndeclaredTargetStampsNothing()

	/**
	 * An objects install with a target writes it into each object of a declared schema, and no other.
	 *
	 * @return void
	 */
	public function testTheInstallerStampsEachObjectOfADeclaredSchema(): void {
		$saved   = [];
		$entity  = $this->createMock(ObjectEntity::class);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved, $entity) {
				$saved[] = $object;
				return $entity;
			}
		);

		$installer = new GenericStoreInstaller(
			objectService: $objects,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$report = $installer->install(
			store: $this->worldStore(),
			item: [
				'components' => [
					['schema' => 'larping_skill', 'object' => ['name' => 'Herbalism']],
					['schema' => 'condition', 'object' => ['name' => 'Poisoned', 'setting' => 'another-world']],
				],
			],
			target: 'world-1'
		);

		$this->assertTrue($report['success']);
		$this->assertCount(2, $saved);
		$this->assertSame('world-1', $saved[0]['setting']);
		$this->assertSame('world-1', $saved[1]['setting'], 'the chosen target wins over what the item carried');
	}//end testTheInstallerStampsEachObjectOfADeclaredSchema()

	/**
	 * An install without a target is the install it always was.
	 *
	 * @return void
	 */
	public function testAnInstallWithoutATargetIsUnchanged(): void {
		$saved   = [];
		$entity  = $this->createMock(ObjectEntity::class);
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved, $entity) {
				$saved[] = $object;
				return $entity;
			}
		);

		$installer = new GenericStoreInstaller(
			objectService: $objects,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$installer->install(store: $this->worldStore(), item: ['components' => [['schema' => 'larping_skill', 'object' => ['name' => 'Herbalism']]]]);

		$this->assertArrayNotHasKey('setting', $saved[0]);
	}//end testAnInstallWithoutATargetIsUnchanged()

	/**
	 * A bundle's objects are stamped in both places an import reads them, by `@self.schema`.
	 *
	 * @return void
	 */
	public function testTheStampReachesBothObjectListsOfABundle(): void {
		$stamp  = new BundleObjectStamp(property: 'setting', schemas: ['larping_skill'], value: 'world-1');
		$bundle = $stamp->applyTo(
			bundle: [
				'info' => ['version' => '1.0.0'],
				'components' => [
					'objects' => [
						['@self' => ['schema' => 'larping_skill'], 'name' => 'Herbalism'],
						['@self' => ['schema' => 'larping_item'], 'name' => 'Sword'],
						'not an object',
					],
				],
				'objects' => [['@self' => ['schema' => 'larping_skill'], 'name' => 'Tracking']],
			]
		);

		$this->assertSame('world-1', $bundle['components']['objects'][0]['setting']);
		$this->assertArrayNotHasKey('setting', $bundle['components']['objects'][1]);
		$this->assertSame('not an object', $bundle['components']['objects'][2]);
		$this->assertSame('world-1', $bundle['objects'][0]['setting']);
		$this->assertSame(['version' => '1.0.0'], $bundle['info']);
	}//end testTheStampReachesBothObjectListsOfABundle()

	/**
	 * An empty stamp leaves the bundle as it is.
	 *
	 * @return void
	 */
	public function testAnEmptyStampLeavesTheBundleAlone(): void {
		$bundle = ['components' => ['objects' => [['@self' => ['schema' => 'larping_skill']]]]];

		$this->assertSame($bundle, (new BundleObjectStamp(property: 'setting', schemas: ['larping_skill'], value: ''))->applyTo(bundle: $bundle));
	}//end testAnEmptyStampLeavesTheBundleAlone()
}//end class
