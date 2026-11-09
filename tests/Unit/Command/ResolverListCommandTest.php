<?php

/**
 * ResolverListCommandTest.
 *
 * Pins what the resolver:list diagnostic prints for an app with and without
 * resolver-shaped config keys.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/register-resolver-service/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ResolverListCommand;
use OCA\OpenRegister\Service\RegisterResolverServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Covers the empty inventory, the listed inventory and the required argument.
 */
class ResolverListCommandTest extends TestCase {
	private RegisterResolverServiceInterface&MockObject $resolver;

	private CommandTester $tester;

	protected function setUp(): void {
		$this->resolver = $this->createMock(RegisterResolverServiceInterface::class);
		$this->tester   = new CommandTester(new ResolverListCommand($this->resolver));
	}

	public function testEmptyInventoryPrintsNoKeysMessage(): void {
		$this->resolver->expects($this->once())->method('enumerateAppConfigs')->with('pipelinq')->willReturn([]);

		$exit    = $this->tester->execute(['app-id' => 'pipelinq']);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('No resolver-shaped config keys set for app "pipelinq".', $display);
		$this->assertStringNotContainsString('Resolver keys for app', $display);
	}

	public function testInventoryListsEveryKeyWithItsValue(): void {
		$this->resolver->expects($this->once())->method('enumerateAppConfigs')->with('opencatalogi')->willReturn(
			['catalog_register' => '3', 'catalog_schema' => 'publication']
		);

		$exit    = $this->tester->execute(['app-id' => 'opencatalogi']);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('Resolver keys for app "opencatalogi":', $display);
		$this->assertStringContainsString('  catalog_register = 3', $display);
		$this->assertStringContainsString('  catalog_schema = publication', $display);
		$this->assertStringNotContainsString('No resolver-shaped', $display);
	}

	public function testMissingAppIdIsRefusedBeforeEnumerating(): void {
		$this->resolver->expects($this->never())->method('enumerateAppConfigs');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('app-id');

		$this->tester->execute([]);
	}
}
