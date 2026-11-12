<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Config;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Config\BundleManageGuard;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class BundleManageGuardTest extends TestCase {
	private RegisterMapper $registers;

	private SchemaMapper $schemas;

	private SchemaManageGate $gate;

	private BundleManageGuard $guard;

	protected function setUp(): void {
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas   = $this->createMock(SchemaMapper::class);
		$this->gate      = $this->createMock(SchemaManageGate::class);
		$this->guard     = new BundleManageGuard($this->registers, $this->schemas, $this->gate);
	}

	public function testAnAdministratorMayOverwriteAnything(): void {
		$this->gate->method('isAdministrator')->willReturn(true);
		$this->registers->expects($this->never())->method('findAllByIdentifier');

		$this->guard->assertAllowed(['components' => ['registers' => ['publicatie' => ['slug' => 'publicatie']]]]);
		$this->addToAssertionCount(1);
	}

	public function testANewRegisterAndSchemaPass(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('findAllByIdentifier')->willReturn([]);
		$this->schemas->method('findAllByIdentifier')->willReturn([]);

		$this->guard->assertAllowed(['components' => ['registers' => ['nieuw' => []], 'schemas' => ['nieuw-schema' => []]]]);
		$this->addToAssertionCount(1);
	}

	public function testAnExistingRegisterTheCallerCannotManageIsRefused(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('findAllByIdentifier')->willReturn([new Register()]);
		$this->gate->method('mayManageRegister')->willReturn(false);

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['components' => ['registers' => ['publicatie' => ['slug' => 'publicatie']]]]);
	}

	public function testAnExistingSchemaTheCallerCannotManageIsRefused(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->schemas->method('findAllByIdentifier')->willReturn([new Schema()]);
		$this->gate->method('mayManage')->willReturn(false);

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['components' => ['schemas' => [['slug' => 'publicatie']]]]);
	}

	public function testAFailedLookupFailsClosed(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('findAllByIdentifier')->willThrowException(new \RuntimeException('db down'));

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['components' => ['registers' => ['publicatie' => []]]]);
	}

	public function testAManagerMayOverwriteTheirRegister(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('findAllByIdentifier')->willReturn([new Register()]);
		$this->gate->method('mayManageRegister')->willReturn(true);

		$this->guard->assertAllowed(['components' => ['registers' => ['publicatie' => []]]]);
		$this->addToAssertionCount(1);
	}

	/**
	 * The importer resolves a schema by uuid, numeric id or slug in any case; the guard must too.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function importerIdentifiers(): array {
		return [
			'uuid'         => ['5f1c9d0e-1111-4222-8333-944455556666'],
			'numeric id'   => ['12'],
			'case variant' => ['Publication'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('importerIdentifiers')]
	public function testAnyIdentifierTheImporterResolvesIsJudged(string $identifier): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->schemas->expects($this->once())->method('findAllByIdentifier')
			->with($identifier)
			->willReturn([new Schema()]);
		$this->gate->method('mayManage')->willReturn(false);

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['components' => ['schemas' => [['slug' => $identifier]]]]);
	}

	public function testSeedObjectsIntoASchemaTheCallerCannotManageAreRefused(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('findAllByIdentifier')->willReturn([new Register()]);
		$this->gate->method('mayManageRegister')->willReturn(true);
		$this->schemas->method('findAllByIdentifier')->with('publication')->willReturn([new Schema()]);
		$this->gate->method('mayManage')->willReturn(false);

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['components' => ['objects' => [['@self' => ['register' => 'woo', 'schema' => 'publication'], 'title' => 'x']]]]);
	}

	public function testTopLevelSeedObjectsIntoARegisterTheCallerCannotManageAreRefused(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('findAllByIdentifier')->willReturn([new Register()]);
		$this->gate->method('mayManageRegister')->willReturn(false);

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['objects' => [['@self' => ['register' => 'woo', 'schema' => 'publication']]]]);
	}

	/**
	 * pp-f9: a non-admin may not seed objects at all, even into the bundle's own new definitions.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function seedBundles(): array {
		$object = [['@self' => ['register' => 'nieuw', 'schema' => 'nieuw-schema']]];
		return [
			'components.objects'             => [['components' => ['objects' => $object]]],
			'top-level objects'              => [['objects' => $object]],
			'x-openregister.seedData.objects' => [['x-openregister' => ['seedData' => ['objects' => ['nieuw-schema' => $object]]]]],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('seedBundles')]
	public function testANonAdminMayNotSeedObjectsInAnyBucket(array $bundle): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('findAllByIdentifier')->willReturn([]);
		$this->schemas->method('findAllByIdentifier')->willReturn([]);

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed($bundle);
	}

	public function testEveryImporterSeedBucketIsListed(): void {
		$importer = (string)file_get_contents(__DIR__ . '/../../../../lib/Service/Configuration/ImportHandler.php');
		foreach (["['components']['objects']", "['objects']", "['x-openregister']['seedData']"] as $read) {
			$this->assertStringContainsString($read, $importer, 'ImportHandler reads ' . $read);
		}

		$this->assertSame(
			[['components', 'objects'], ['objects'], ['x-openregister', 'seedData', 'objects']],
			BundleManageGuard::SEED_OBJECT_PATHS
		);
	}

	public function testAnAdministratorMaySeedObjects(): void {
		$this->gate->method('isAdministrator')->willReturn(true);

		$this->guard->assertAllowed(['objects' => [['@self' => ['schema' => 'x']]]]);
		$this->addToAssertionCount(1);
	}

	/**
	 * pp-f1: the importer lower-cases a register slug; an upper-cased uuid, a numeric id or a case variant is judged.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function registerIdentifiers(): array {
		return [
			'upper-case uuid' => ['5F1C9D0E-1111-4222-8333-944455556666'],
			'numeric id'      => ['3'],
			'case variant'    => ['Publicatie'],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('registerIdentifiers')]
	public function testAnyRegisterIdentifierTheImporterResolvesIsJudged(string $identifier): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->expects($this->once())->method('findAllByIdentifier')
			->with($identifier)
			->willReturn([new Register()]);
		$this->gate->method('mayManageRegister')->willReturn(false);

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['components' => ['registers' => [['slug' => $identifier]]]]);
	}

	/**
	 * pp-f2: x-openregister.seedData objects, keyed by schema slug, are judged too.
	 */
	public function testSeedDataObjectsIntoASchemaTheCallerCannotManageAreRefused(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->schemas->method('findAllByIdentifier')->with('publication')->willReturn([new Schema()]);
		$this->gate->method('mayManage')->willReturn(false);

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['x-openregister' => ['seedData' => ['objects' => ['publication' => [['title' => 'x']]]]]]);
	}
}
