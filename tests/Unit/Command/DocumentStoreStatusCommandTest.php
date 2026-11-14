<?php

/**
 * DocumentStoreStatusCommandTest.
 *
 * Pins what `occ openregister:document-store:status` prints for a store on the
 * primary storage, on a separate External storage mount, and when the root
 * cannot be read.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DocumentStoreStatusCommand;
use OCA\OpenRegister\Service\File\DocumentStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\OpenRegister\Command\DocumentStoreStatusCommand
 */
class DocumentStoreStatusCommandTest extends TestCase {
	/**
	 * The described store.
	 *
	 * @var DocumentStore&MockObject
	 */
	private DocumentStore&MockObject $store;

	/**
	 * Runs the command.
	 *
	 * @var CommandTester
	 */
	private CommandTester $tester;

	protected function setUp(): void {
		$this->store  = $this->createMock(DocumentStore::class);
		$this->tester = new CommandTester(new DocumentStoreStatusCommand($this->store));
	}

	public function testStatusPrintsTheStore(): void {
		$this->store->expects($this->once())->method('describe')->willReturn([
			'root' => 'Open Registers',
			'storageId' => 'home::openregister',
			'backend' => 'local',
			'backendName' => 'local',
			'mountPoint' => '/openregister/',
			'separate' => false,
			'freeBytes' => 1048576,
		]);

		$exit    = $this->tester->execute([]);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('Root:        Open Registers', $display);
		$this->assertStringContainsString('Backend:     local (local)', $display);
		$this->assertStringContainsString('Storage:     home::openregister', $display);
		$this->assertStringContainsString('Mount point: /openregister/', $display);
		$this->assertStringContainsString('Separate:    no, it is on the instance\'s primary storage', $display);
		$this->assertStringContainsString('Free space:  1048576 bytes', $display);
	}

	public function testASeparateExternalStoreIsNamedAsSeparate(): void {
		$this->store->method('describe')->willReturn([
			'root' => 'Documents',
			'storageId' => 'local::/mnt/documents/',
			'backend' => 'external',
			'backendName' => 'local',
			'mountPoint' => '/openregister/files/Documents/',
			'separate' => true,
			'freeBytes' => null,
		]);

		$exit    = $this->tester->execute([]);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::SUCCESS, $exit);
		$this->assertStringContainsString('Root:        Documents', $display);
		$this->assertStringContainsString('Backend:     external (local)', $display);
		$this->assertStringContainsString('Separate:    yes, it is separate from the instance\'s primary storage', $display);
		$this->assertStringContainsString('Free space:  not reported by the storage', $display);
	}

	public function testAnUnreadableRootFailsAndSaysWhy(): void {
		$this->store->method('describe')->willThrowException(new RuntimeException('The document store root "Open Registers" is not a folder.'));

		$exit    = $this->tester->execute([]);
		$display = $this->tester->getDisplay();

		$this->assertSame(Command::FAILURE, $exit);
		$this->assertStringContainsString('The document store cannot be read: The document store root "Open Registers" is not a folder.', $display);
		$this->assertStringNotContainsString('Root:', $display);
	}
}
