<?php

/**
 * A download audit row names the file it was for.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-download-audit-row-names-the-file
 */

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\FilesController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileAuditHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The audit row a download writes carries the file's name beside its id.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-download-audit-row-names-the-file
 */
class FilesDownloadAuditNamesTheFileTest extends TestCase {
	/**
	 * A history can say which document was looked at: the row's data holds
	 * the file name ("Medisch verslag.pdf") as well as the id.
	 *
	 * @return void
	 */
	public function testTheDownloadRowNamesTheFile(): void {
		$written = null;
		$audit = $this->createMock(FileAuditHandler::class);
		$audit->expects($this->once())->method('logFileAction')->willReturnCallback(
			static function (ObjectEntity $object, int $fileId, string $action, array $data = []) use (&$written) {
				$written = ['action' => $action, 'fileId' => $fileId, 'data' => $data];
				return null;
			}
		);

		$controller = new FilesController(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			fileService: $this->createMock(FileService::class),
			objectService: $this->createMock(ObjectService::class),
			rootFolder: $this->createMock(IRootFolder::class),
			userManager: $this->createMock(IUserManager::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			fileAuditHandler: $audit
		);

		$record = new ReflectionMethod(objectOrMethod: $controller, method: 'recordDownloadEvent');
		$record->invoke($controller, 42, new ObjectEntity(), 'Medisch verslag.pdf');

		$this->assertSame(
			expected: ['action' => 'file.downloaded', 'fileId' => 42, 'data' => ['fileId' => 42, 'fileName' => 'Medisch verslag.pdf']],
			actual: $written
		);
	}//end testTheDownloadRowNamesTheFile()
}//end class
