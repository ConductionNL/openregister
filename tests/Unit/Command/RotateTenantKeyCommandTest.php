<?php

/**
 * occ openregister:tenant-key:rotate prints rotation metadata and never key material.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/saas-multi-tenant/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\RotateTenantKeyCommand;
use OCA\OpenRegister\Service\TenantKeyService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\OpenRegister\Command\RotateTenantKeyCommand
 */
class RotateTenantKeyCommandTest extends TestCase {

	/**
	 * The rotation runs for the named tenant and prints only its metadata.
	 *
	 * @return void
	 */
	public function testRotatesAndPrintsOnlyMetadata(): void {
		$keys = $this->createMock(TenantKeyService::class);
		$keys->method('hasActiveKey')->willReturn(true);
		$keys->expects($this->once())->method('rotateTenantKey')->with('org-E')
			->willReturn(['tenant_id' => 'org-E', 'rotated_at' => '2026-11-05T10:00:00+00:00', 'retired_key_id' => 41]);
		$keys->expects($this->never())->method('getCurrentTenantKey');

		$tester = new CommandTester(new RotateTenantKeyCommand(tenantKeys: $keys));
		$status = $tester->execute(['tenant' => ' org-E ', '--accept-unverifiable-signatures' => true]);

		$this->assertSame(0, $status);
		$this->assertSame("Tenant org-E: new key active since 2026-11-05T10:00:00+00:00, key 41 retired.\n", $tester->getDisplay());
	}//end testRotatesAndPrintsOnlyMetadata()

	/**
	 * A tenant without an earlier key says so.
	 *
	 * @return void
	 */
	public function testAFirstKeySaysNoEarlierKeyExisted(): void {
		$keys = $this->createMock(TenantKeyService::class);
		$keys->method('hasActiveKey')->willReturn(false);
		$keys->method('rotateTenantKey')->willReturn(['tenant_id' => 'org-F', 'rotated_at' => 'now', 'retired_key_id' => null]);

		$tester = new CommandTester(new RotateTenantKeyCommand(tenantKeys: $keys));
		$tester->execute(['tenant' => 'org-F', '--create' => true]);

		$this->assertStringContainsString('no earlier key existed', $tester->getDisplay());
	}//end testAFirstKeySaysNoEarlierKeyExisted()

	/**
	 * A blank tenant is refused before anything is written.
	 *
	 * @return void
	 */
	public function testABlankTenantIsRefused(): void {
		$keys = $this->createMock(TenantKeyService::class);
		$keys->expects($this->never())->method('rotateTenantKey');

		$tester = new CommandTester(new RotateTenantKeyCommand(tenantKeys: $keys));

		$this->assertSame(1, $tester->execute(['tenant' => '  ']));
	}//end testABlankTenantIsRefused()

	/**
	 * An encryption failure is reported and exits non-zero.
	 *
	 * @return void
	 */
	public function testAFailedRotationExitsNonZero(): void {
		$keys = $this->createMock(TenantKeyService::class);
		$keys->method('hasActiveKey')->willReturn(true);
		$keys->method('rotateTenantKey')->willThrowException(new RuntimeException('Failed to encrypt tenant key'));

		$tester = new CommandTester(new RotateTenantKeyCommand(tenantKeys: $keys));

		$this->assertSame(1, $tester->execute(['tenant' => 'org-G', '--accept-unverifiable-signatures' => true]));
		$this->assertStringContainsString('not rotated', $tester->getDisplay());
	}//end testAFailedRotationExitsNonZero()

	/**
	 * A tenant with no active key is refused without --create, so a mistyped id writes nothing.
	 *
	 * @return void
	 */
	public function testATenantWithoutAKeyIsRefusedWithoutCreate(): void {
		$keys = $this->createMock(TenantKeyService::class);
		$keys->method('hasActiveKey')->with('org-typo')->willReturn(false);
		$keys->expects($this->never())->method('rotateTenantKey');

		$tester = new CommandTester(new RotateTenantKeyCommand(tenantKeys: $keys));

		$this->assertSame(1, $tester->execute(['tenant' => 'org-typo']));
		$this->assertStringContainsString('--create', $tester->getDisplay());
	}//end testATenantWithoutAKeyIsRefusedWithoutCreate()

	/**
	 * --create still rotates a tenant that already has a key.
	 *
	 * @return void
	 */
	public function testCreateOnATenantWithAKeyStillRotates(): void {
		$keys = $this->createMock(TenantKeyService::class);
		$keys->method('hasActiveKey')->willReturn(true);
		$keys->expects($this->once())->method('rotateTenantKey')
			->willReturn(['tenant_id' => 'org-H', 'rotated_at' => 'now', 'retired_key_id' => 7]);

		$tester = new CommandTester(new RotateTenantKeyCommand(tenantKeys: $keys));

		$this->assertSame(0, $tester->execute(['tenant' => 'org-H', '--create' => true, '--accept-unverifiable-signatures' => true]));
	}//end testCreateOnATenantWithAKeyStillRotates()

	/**
	 * A live key is not rotated without the explicit flag, and the warning says why.
	 *
	 * @return void
	 */
	public function testALiveKeyIsRefusedWithoutTheFlag(): void {
		$keys = $this->createMock(TenantKeyService::class);
		$keys->method('hasActiveKey')->willReturn(true);
		$keys->expects($this->never())->method('rotateTenantKey');

		$tester = new CommandTester(new RotateTenantKeyCommand(tenantKeys: $keys));

		$this->assertSame(1, $tester->execute(['tenant' => 'org-J']));
		$this->assertStringContainsString('--accept-unverifiable-signatures', $tester->getDisplay());
	}//end testALiveKeyIsRefusedWithoutTheFlag()
}//end class
