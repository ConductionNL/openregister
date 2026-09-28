<?php

/**
 * Tests for RegisterFolderProvisioner.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/register-folder-at-import/specs/file-actions/spec.md#requirement-an-app-imported-register-has-its-files-folder-when-the-import-returns-req-rfai-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\File\RegisterFolderProvisioner;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Folder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\File\RegisterFolderProvisioner
 */
class RegisterFolderProvisionerTest extends TestCase {

	/**
	 * File service double.
	 *
	 * @var FileService&MockObject
	 */
	private FileService&MockObject $fileService;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Wire the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->fileService = $this->createMock(FileService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	/**
	 * A persisted register with the given folder value.
	 *
	 * @param int $id The register id.
	 * @param string|null $folder The stored folder value.
	 *
	 * @return Register
	 */
	private function register(int $id, ?string $folder): Register {
		$register = new Register();
		$register->setId($id);
		$register->setFolder($folder);
		return $register;
	}

	/**
	 * A folder node with the given id.
	 *
	 * @param int $id The node id.
	 *
	 * @return Folder
	 */
	private function folder(int $id): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		return $folder;
	}

	/**
	 * A register without a folder is provisioned; one whose folder resolves is present.
	 *
	 * @return void
	 */
	public function testNewFolderIsProvisionedAndResolvingFolderIsPresent(): void {
		$empty = $this->register(1, null);
		$held = $this->register(2, '42');
		$this->fileService->expects($this->exactly(2))
			->method('createEntityFolder')
			->willReturnCallback(fn (Register $r) => ($r === $empty ? $this->folder(77) : $this->folder(42)));
		$this->logger->expects($this->once())->method('info');

		$tally = (new RegisterFolderProvisioner($this->fileService, $this->logger))->ensureFolders([$empty, $held]);

		$this->assertSame(['provisioned' => 1, 'present' => 1, 'failed' => 0], $tally);
	}

	/**
	 * A stale folder id that the handler replaced counts as provisioned.
	 *
	 * @return void
	 */
	public function testStaleFolderIdReplacedCountsAsProvisioned(): void {
		$this->fileService->method('createEntityFolder')->willReturn($this->folder(90));

		$tally = (new RegisterFolderProvisioner($this->fileService, $this->logger))
			->ensureFolders([$this->register(3, '12')]);

		$this->assertSame(1, $tally['provisioned']);
	}

	/**
	 * A null folder and a throw both count as failed, and neither stops the rest.
	 *
	 * @return void
	 */
	public function testFailuresAreCountedAndDoNotStopTheRest(): void {
		$returnsNull = $this->register(1, null);
		$throws = $this->register(2, null);
		$works = $this->register(3, null);
		$this->fileService->expects($this->exactly(3))
			->method('createEntityFolder')
			->willReturnCallback(
				function (Register $r) use ($returnsNull, $throws) {
					if ($r === $returnsNull) {
						return null;
					}

					if ($r === $throws) {
						throw new RuntimeException('storage gone');
					}

					return $this->folder(5);
				}
			);
		$this->logger->expects($this->exactly(2))->method('warning');

		$tally = (new RegisterFolderProvisioner($this->fileService, $this->logger))
			->ensureFolders([$returnsNull, $throws, $works]);

		$this->assertSame(['provisioned' => 1, 'present' => 0, 'failed' => 2], $tally);
	}

	/**
	 * Non-registers and unsaved registers are skipped without a call.
	 *
	 * @return void
	 */
	public function testNonRegistersAndUnsavedRegistersAreSkipped(): void {
		$this->fileService->expects($this->never())->method('createEntityFolder');
		$this->logger->expects($this->never())->method('info');

		$tally = (new RegisterFolderProvisioner($this->fileService, $this->logger))
			->ensureFolders([new stdClass(), 'register', new Register()]);

		$this->assertSame(['provisioned' => 0, 'present' => 0, 'failed' => 0], $tally);
	}
}
