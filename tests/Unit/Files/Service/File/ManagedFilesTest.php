<?php

/**
 * The managed-files port answers what the document store and the owner lookup answer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\ManagedFiles;
use OCA\OpenRegister\Kernel\Contracts\Files\ManagedFilesInterface;
use OCA\OpenRegister\Service\File\DocumentStore;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Files\Service\File\ManagedFiles
 * @uses   \OCA\OpenRegister\Service\File\DocumentStore
 * @uses   \OCA\OpenRegister\Service\File\ManagedFileOwnerLookup
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ManagedFilesTest extends TestCase
{

    /**
     * The port over a store whose root setting is $stored and an object finder.
     *
     * @param string                     $stored  The stored `document_store_root` value.
     * @param MagicObjectFinderInterface $objects The object finder.
     *
     * @return array{0: ManagedFiles, 1: DocumentStore, 2: ManagedFileOwnerLookup}
     */
    private function build(string $stored, MagicObjectFinderInterface $objects): array
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn($stored);
        $store  = new DocumentStore(appConfig: $appConfig, rootFolder: $this->createMock(IRootFolder::class));
        $owners = new ManagedFileOwnerLookup(objects: $objects);

        return [new ManagedFiles(store: $store, owners: $owners), $store, $owners];
    }//end build()

    /**
     * A file in a folder called $name with id $id.
     *
     * @param string $name The folder's name.
     * @param int    $id   The folder's id.
     *
     * @return File
     */
    private function fileIn(string $name, int $id): File
    {
        $parent = $this->createMock(Folder::class);
        $parent->method('getName')->willReturn($name);
        $parent->method('getId')->willReturn($id);
        $file = $this->createMock(File::class);
        $file->method('getParent')->willReturn($parent);

        return $file;
    }//end fileIn()

    public function testItImplementsThePort(): void
    {
        $this->assertTrue(is_subclass_of(ManagedFiles::class, ManagedFilesInterface::class));
    }//end testItImplementsThePort()

    /**
     * @return array<string, array{0: string}>
     */
    public static function storedRoots(): array
    {
        return [
            'set'       => ['/Archief/Zaken/'],
            'empty'     => [''],
            'escapes'   => ['../elsewhere'],
            'default'   => [DocumentStore::DEFAULT_ROOT],
        ];
    }//end storedRoots()

    /**
     * @dataProvider storedRoots
     */
    public function testTheRootIsTheStoreRoot(string $stored): void
    {
        [$managed, $store] = $this->build(stored: $stored, objects: $this->createMock(MagicObjectFinderInterface::class));

        $this->assertSame($store->root(), $managed->root());
    }//end testTheRootIsTheStoreRoot()

    public function testTheOwnerIsTheObjectThatRecordsTheFolder(): void
    {
        $object = new ObjectEntity();
        $object->setFolder('42');
        $objects = $this->createMock(MagicObjectFinderInterface::class);
        $objects->method('findAcrossAllSources')->willReturn(['object' => $object]);
        [$managed, , $owners] = $this->build(stored: '', objects: $objects);
        $file = $this->fileIn(name: 'uuid-1', id: 42);

        $this->assertSame($object, $managed->ownerOf(file: $file));
        $this->assertSame($owners->find(file: $file), $managed->ownerOf(file: $file));
    }//end testTheOwnerIsTheObjectThatRecordsTheFolder()

    public function testNoOwnerIsNull(): void
    {
        $objects = $this->createMock(MagicObjectFinderInterface::class);
        $objects->method('findAcrossAllSources')->willThrowException(new DoesNotExistException('none'));
        [$managed] = $this->build(stored: '', objects: $objects);

        $this->assertNull($managed->ownerOf(file: $this->fileIn(name: 'uuid-2', id: 7)));
    }//end testNoOwnerIsNull()

    public function testAFailingLookupPropagates(): void
    {
        $objects = $this->createMock(MagicObjectFinderInterface::class);
        $objects->method('findAcrossAllSources')->willThrowException(new RuntimeException('database down'));
        [$managed] = $this->build(stored: '', objects: $objects);

        $this->expectException(RuntimeException::class);
        $managed->ownerOf(file: $this->fileIn(name: 'uuid-3', id: 7));
    }//end testAFailingLookupPropagates()
}//end class
