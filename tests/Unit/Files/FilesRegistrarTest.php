<?php

/**
 * The Files component binds its interfaces to their classes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files;

use OCA\OpenRegister\Files\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFileRightsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FileTagCatalogueInterface;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuardInterface;
use OCA\OpenRegister\Files\Service\File\PreviewSize;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilderInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FileAccountInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFilesArchiveInterface;
use OCA\OpenRegister\Service\File\ObjectFilesArchive;
use OCA\OpenRegister\Kernel\Contracts\Core\FileReadRestrictionInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\PreviewDimensionsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RegisterFolderRecordsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ShareLinkUrlInterface;
use OCA\OpenRegister\Tests\Unit\AppHost\RecordingRegistrationContext;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Kernel\Contracts\FileOwnerLookupInterface;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\TextEntityDetectorInterface;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;

/**
 * @covers \OCA\OpenRegister\Files\FilesRegistrar
 */
class FilesRegistrarTest extends TestCase
{
    public function testEveryInterfaceIsBoundToItsImplementation(): void
    {
        $context = new RecordingRegistrationContext();
        FilesRegistrar::register(context: $context);

        $expected = [];
        foreach (FilesRegistrar::BINDINGS as $contract => $service) {
            $this->assertTrue(is_subclass_of($service, $contract), $service.' implements '.$contract);
            $expected[] = ['alias' => $contract, 'target' => $service];
        }

        $this->assertCount(37, $expected);
        $this->assertSame(ObjectFileAccess::class, FilesRegistrar::BINDINGS[ObjectFileRightsInterface::class]);
        $this->assertSame(expected: $expected, actual: $context->aliases);
    }//end testEveryInterfaceIsBoundToItsImplementation()

    /**
     * The tags API's tag catalogue port resolves to the file service contract, which extends it.
     *
     * @return void
     */
    public function testTheTagCatalogueIsAnsweredByTheFileService(): void
    {
        $context = new RecordingRegistrationContext();
        FilesRegistrar::register(context: $context);

        $this->assertContains(['alias' => FileTagCatalogueInterface::class, 'target' => FileServiceInterface::class], $context->aliases);
        $this->assertTrue(is_subclass_of(FileServiceInterface::class, FileTagCatalogueInterface::class));
    }//end testTheTagCatalogueIsAnsweredByTheFileService()

    public function testTheCorePortsTheFileHandlersAskAreAnsweredHere(): void
    {
        $this->assertSame(FileRestrictionGuard::class, FilesRegistrar::BINDINGS[FileReadRestrictionInterface::class]);
        $this->assertSame(ShareUrlBuilder::class, FilesRegistrar::BINDINGS[ShareLinkUrlInterface::class]);
        $this->assertSame(RegisterFolderRecorder::class, FilesRegistrar::BINDINGS[RegisterFolderRecordsInterface::class]);
        $this->assertSame(FileOwnershipHandler::class, FilesRegistrar::BINDINGS[FileAccountInterface::class]);
        $this->assertSame(ObjectFilesArchive::class, FilesRegistrar::BINDINGS[ObjectFilesArchiveInterface::class]);
        $this->assertSame(\OCA\OpenRegister\Files\Service\TextExtraction\FileTextReader::class, FilesRegistrar::BINDINGS[\OCA\OpenRegister\Contract\FileTextReaderInterface::class]);
        $this->assertSame(\OCA\OpenRegister\Service\Object\CopyFiles::class, FilesRegistrar::BINDINGS[\OCA\OpenRegister\Kernel\Contracts\ObjectFileCopierInterface::class]);
        $this->assertTrue(is_subclass_of(FileOwnershipHandlerInterface::class, FileAccountInterface::class));
        $this->assertSame(FileMapper::class, FilesRegistrar::BINDINGS[FileOwnerLookupInterface::class]);
        $this->assertTrue(is_subclass_of(FileMapperInterface::class, FileOwnerLookupInterface::class));
        $this->assertSame(EntityRecognitionHandler::class, FilesRegistrar::BINDINGS[TextEntityDetectorInterface::class]);
        $this->assertTrue(is_subclass_of(FileRestrictionGuardInterface::class, FileReadRestrictionInterface::class));
        $this->assertTrue(is_subclass_of(ShareUrlBuilderInterface::class, ShareLinkUrlInterface::class));
        $this->assertTrue(is_subclass_of(PreviewSize::class, PreviewDimensionsInterface::class));
    }//end testTheCorePortsTheFileHandlersAskAreAnsweredHere()

    public function testNoteServiceHasAFactory(): void
    {
        $context = new RecordingRegistrationContext();
        FilesRegistrar::register(context: $context);

        $this->assertContains(NoteService::class, $context->services);
    }//end testNoteServiceHasAFactory()
}//end class
