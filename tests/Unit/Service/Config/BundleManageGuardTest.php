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
		$this->schemas->method('findBySlug')->willReturn([]);

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
		$this->schemas->method('findBySlug')->willReturn([new Schema()]);
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
}
