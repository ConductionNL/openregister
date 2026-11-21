<?php

/**
 * Unit tests for the Core ports Core reads file storage through.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Kernel\Contracts\Core\FileNodeLookupInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFileRecordsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFolderAccessInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFolderLookupInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\FileFolderServiceInterface;
use OCA\OpenRegister\Service\FileReaderInterface;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\AuditRecording;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\Collaborators\ObjectStorageServices;
use OCA\OpenRegister\Service\Object\DeleteObject;
use OCA\OpenRegister\Service\Object\DeletionAttribution;
use OCA\OpenRegister\Service\Object\DeletionIntegrity;
use OCA\OpenRegister\Service\Object\RemovalOptions;
use OCA\OpenRegister\Service\Object\RenderFileSources;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\Files\Folder;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Each port is a parent of the file storage interface it was taken from, and is bound to the same class.
 */
final class FileStoragePortsTest extends TestCase {

	/**
	 * @return array<string, array{0: class-string, 1: class-string}>
	 */
	public static function portsAndTheirFileInterfaces(): array {
		return [
			'file records'  => [ObjectFileRecordsInterface::class, FileMapperInterface::class],
			'file node'     => [FileNodeLookupInterface::class, FileReaderInterface::class],
			'object folder' => [ObjectFolderLookupInterface::class, FileFolderServiceInterface::class],
			'folder access' => [ObjectFolderAccessInterface::class, FileFolderServiceInterface::class],
		];
	}

	/**
	 * @param class-string $port          The Core port.
	 * @param class-string $fileInterface The file storage interface.
	 *
	 * @return void
	 *
	 * @dataProvider portsAndTheirFileInterfaces
	 */
	public function testEachFileInterfaceExtendsItsCorePort(string $port, string $fileInterface): void {
		$this->assertTrue(is_subclass_of($fileInterface, $port), $fileInterface . ' extends ' . $port);
	}

	public function testFilesBindsTheFileRecordsPortToItsMapper(): void {
		$this->assertSame(FileMapper::class, FilesRegistrar::BINDINGS[ObjectFileRecordsInterface::class]);
		$this->assertSame(FilesRegistrar::BINDINGS[FileMapperInterface::class], FilesRegistrar::BINDINGS[ObjectFileRecordsInterface::class]);
	}

	public function testTheFileNodeAndFolderPortsBindToTheFileService(): void {
		$this->assertSame(FileService::class, ContractBindings::BINDINGS[FileNodeLookupInterface::class]);
		$this->assertSame(FileService::class, ContractBindings::BINDINGS[ObjectFolderLookupInterface::class]);
		$this->assertSame(ContractBindings::BINDINGS[FileReaderInterface::class], ContractBindings::BINDINGS[FileNodeLookupInterface::class]);
		$this->assertSame(ContractBindings::BINDINGS[FileFolderServiceInterface::class], ContractBindings::BINDINGS[ObjectFolderLookupInterface::class]);
	}

	public function testTheFolderAccessPortBindsToTheFileService(): void {
		$this->assertSame(FileService::class, ContractBindings::BINDINGS[ObjectFolderAccessInterface::class]);
		$this->assertSame(ContractBindings::BINDINGS[FileFolderServiceInterface::class], ContractBindings::BINDINGS[ObjectFolderAccessInterface::class]);
	}

	public function testTheObjectStorageBagCarriesTheFolderAccessPortOnly(): void {
		$parameters = [];
		foreach ((new ReflectionMethod(ObjectStorageServices::class, '__construct'))->getParameters() as $parameter) {
			$type = $parameter->getType();
			$parameters[$parameter->getName()] = ($type instanceof ReflectionNamedType) ? $type->getName() : null;
		}

		$this->assertSame(ObjectFolderAccessInterface::class, $parameters['fileService']);
		$this->assertArrayNotHasKey('searchTrailService', $parameters);
	}

	public function testARenderReadsFilesThroughThePorts(): void {
		$records = $this->createMock(ObjectFileRecordsInterface::class);
		$nodes = $this->createMock(FileNodeLookupInterface::class);

		$sources = new RenderFileSources($records, $nodes, $this->createMock(ISystemTagManager::class), $this->createMock(ISystemTagObjectMapper::class));

		$this->assertSame($records, $sources->fileMapper);
		$this->assertSame($nodes, $sources->fileService);
	}

	public function testAPermanentDeleteRemovesTheObjectsFolderThroughThePort(): void {
		$object = new ObjectEntity();
		$object->setUuid('uuid-folder');
		$object->setRegister('2');
		$object->setSchema('5');
		$object->setFolder('42');

		$folder = $this->createMock(Folder::class);
		$folder->expects($this->once())->method('delete');
		$folders = $this->createMock(ObjectFolderLookupInterface::class);
		$folders->expects($this->once())->method('getObjectFolder')->with($this->identicalTo($object))->willReturn($folder);

		$mapper = $this->createMock(MagicMapper::class);
		$mapper->method('deleteObjectEntity')->willReturn($object);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRetentionSettingsOnly')->willReturn(['auditTrailsEnabled' => false]);
		$logger = $this->createMock(LoggerInterface::class);

		$handler = new DeleteObject(
			$mapper,
			$this->createMock(CacheHandler::class),
			new AuditRecording($this->createMock(AuditTrailMapper::class), $logger),
			$settings,
			new DeletionIntegrity($this->createMock(ReferentialIntegrityService::class), $this->createMock(IDBConnection::class)),
			new DeletionAttribution($this->createMock(IUserSession::class), $this->createMock(OrganisationMapper::class), $logger),
			$folders
		);

		$register = new Register();
		$register->setId(2);
		$schema = new Schema();
		$schema->setId(5);
		$this->assertTrue($handler->delete($object, new RemovalOptions(permanent: true, table: new RegisterSchema(register: $register, schema: $schema))));
	}
}
