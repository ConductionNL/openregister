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
		$this->registers->expects($this->never())->method('find');

		$this->guard->assertAllowed(['components' => ['registers' => ['publicatie' => ['slug' => 'publicatie']]]]);
		$this->addToAssertionCount(1);
	}

	public function testANewRegisterAndSchemaPass(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->schemas->method('findAllByIdentifier')->willReturn([]);

		$this->guard->assertAllowed(['components' => ['registers' => ['nieuw' => []], 'schemas' => ['nieuw-schema' => []]]]);
		$this->addToAssertionCount(1);
	}

	public function testAnExistingRegisterTheCallerCannotManageIsRefused(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('find')->willReturn(new Register());
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
		$this->registers->method('find')->willThrowException(new \RuntimeException('db down'));

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['components' => ['registers' => ['publicatie' => []]]]);
	}

	public function testAManagerMayOverwriteTheirRegister(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('find')->willReturn(new Register());
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
		$this->registers->method('find')->willReturn(new Register());
		$this->gate->method('mayManageRegister')->willReturn(true);
		$this->schemas->method('findAllByIdentifier')->with('publication')->willReturn([new Schema()]);
		$this->gate->method('mayManage')->willReturn(false);

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['components' => ['objects' => [['@self' => ['register' => 'woo', 'schema' => 'publication'], 'title' => 'x']]]]);
	}

	public function testTopLevelSeedObjectsIntoARegisterTheCallerCannotManageAreRefused(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('find')->willReturn(new Register());
		$this->gate->method('mayManageRegister')->willReturn(false);

		$this->expectException(NotAuthorizedException::class);
		$this->guard->assertAllowed(['objects' => [['@self' => ['register' => 'woo', 'schema' => 'publication']]]]);
	}

	public function testSeedObjectsIntoTheBundlesOwnNewDefinitionsPass(): void {
		$this->gate->method('isAdministrator')->willReturn(false);
		$this->registers->method('find')->willThrowException(new DoesNotExistException('none'));
		$this->schemas->expects($this->once())->method('findAllByIdentifier')->with('nieuw-schema')->willReturn([]);

		$this->guard->assertAllowed([
			'components' => [
				'registers' => ['nieuw' => []],
				'schemas'   => ['nieuw-schema' => []],
				'objects'   => [['@self' => ['register' => 'nieuw', 'schema' => 'nieuw-schema']]],
			],
		]);
		$this->addToAssertionCount(1);
	}
}
