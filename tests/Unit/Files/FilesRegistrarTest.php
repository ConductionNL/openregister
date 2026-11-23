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

use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Files\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Files\Service\File\CallerFileAccess;
use OCA\OpenRegister\Files\Service\File\ManagedFiles;
use OCA\OpenRegister\Files\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCA\OpenRegister\Files\Service\File\RegisterFolderProvisionerInterface;
use OCA\OpenRegister\Files\Service\FileSettingRules;
use OCA\OpenRegister\Kernel\Contracts\Files\CallerFileRightsInterface;
use OCA\OpenRegister\Kernel\Contracts\Files\EntityMentionDecisionsInterface;
use OCA\OpenRegister\Kernel\Contracts\Files\FileSettingRulesInterface;
use OCA\OpenRegister\Kernel\Contracts\Files\ManagedFilesInterface;
use OCA\OpenRegister\Kernel\Contracts\Files\OfficeDocumentConversionInterface;
use OCA\OpenRegister\Kernel\Contracts\Files\RegisterFolderProvisioningInterface;
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

        $this->assertCount(39, $expected);
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
        $this->assertSame(\OCA\OpenRegister\Service\Object\CopyFiles::class, FilesRegistrar::BINDINGS[\OCA\OpenRegister\Kernel\Contracts\ObjectFileCopierInterface::class]);
        $this->assertTrue(is_subclass_of(FileOwnershipHandlerInterface::class, FileAccountInterface::class));
        $this->assertTrue(is_subclass_of(FileRestrictionGuardInterface::class, FileReadRestrictionInterface::class));
        $this->assertTrue(is_subclass_of(ShareUrlBuilderInterface::class, ShareLinkUrlInterface::class));
        $this->assertTrue(is_subclass_of(PreviewSize::class, PreviewDimensionsInterface::class));
    }//end testTheCorePortsTheFileHandlersAskAreAnsweredHere()

    /**
     * Each Files-owned port is answered by the Files class or interface that already does the work.
     *
     * @return void
     */
    public function testTheFilesPortsAreAnsweredByTheirFilesImplementations(): void
    {
        $this->assertSame(EntityRelationMapperInterface::class, FilesRegistrar::BINDINGS[EntityMentionDecisionsInterface::class]);
        $this->assertSame(CallerFileAccess::class, FilesRegistrar::BINDINGS[CallerFileRightsInterface::class]);
        $this->assertSame(ManagedFiles::class, FilesRegistrar::BINDINGS[ManagedFilesInterface::class]);
        $this->assertSame(FileSettingRules::class, FilesRegistrar::BINDINGS[FileSettingRulesInterface::class]);
        $this->assertSame(FilesRegistrar::BINDINGS[RegisterFolderProvisionerInterface::class], FilesRegistrar::BINDINGS[RegisterFolderProvisioningInterface::class]);
        $this->assertSame(FilesRegistrar::BINDINGS[NcOfficeConverterInterface::class], FilesRegistrar::BINDINGS[OfficeDocumentConversionInterface::class]);
        $this->assertTrue(is_subclass_of(EntityRelationMapperInterface::class, EntityMentionDecisionsInterface::class));
        $this->assertTrue(is_subclass_of(RegisterFolderProvisionerInterface::class, RegisterFolderProvisioningInterface::class));
        $this->assertTrue(is_subclass_of(NcOfficeConverterInterface::class, OfficeDocumentConversionInterface::class));
    }//end testTheFilesPortsAreAnsweredByTheirFilesImplementations()

    public function testNoteServiceHasAFactory(): void
    {
        $context = new RecordingRegistrationContext();
        FilesRegistrar::register(context: $context);

        $this->assertContains(NoteService::class, $context->services);
    }//end testNoteServiceHasAFactory()
}//end class
