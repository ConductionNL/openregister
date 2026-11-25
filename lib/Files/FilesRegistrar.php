<?php

/**
 * FilesRegistrar: container registrations of the Files component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files;

use OCA\OpenRegister\Service\File\AntivirusScannerAdapter;
use OCA\OpenRegister\Service\File\AntivirusScannerInterface;
use OCA\OpenRegister\Files\Db\ChunkIndexOverviewInterface;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Files\Db\ChunkStoreInterface;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Db\RegisterFolderRecorder;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFileRecordsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFilesArchiveInterface;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCA\OpenRegister\Files\Service\File\FileAuditHandlerInterface;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Files\Service\File\FileReadScope;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuardInterface;
use OCA\OpenRegister\Files\Service\File\FileScopeReader;
use OCA\OpenRegister\Files\Service\File\FileSharingHandler;
use OCA\OpenRegister\Files\Service\File\FileSharingHandlerInterface;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\File\ObjectFilesArchive;
use OCA\OpenRegister\Files\Service\File\Office\ConversionManagerConverter;
use OCA\OpenRegister\Files\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCA\OpenRegister\Files\Service\File\RegisterFolderProvisioner;
use OCA\OpenRegister\Files\Service\File\RegisterFolderProvisionerInterface;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilderInterface;
use OCA\OpenRegister\Files\Service\File\TaggingHandler;
use OCA\OpenRegister\Files\Service\NoteAccounts;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Files\Service\NoteVersionService;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\RiskLevelServiceInterface;
use OCA\OpenRegister\Files\Service\Sharing\AccessLinkReader;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\PatternSetVersion;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Kernel\Contracts\FileOwnerLookupInterface;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FileSizeTotalsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ObjectFileRightsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FileTagCatalogueInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ReadableFileHitsInterface;
use OCA\OpenRegister\Kernel\Contracts\Compliance\DetectionPatternsVersionInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FileAccountInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\FileReadRestrictionInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RegisterFolderRecordsInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\ShareLinkUrlInterface;
use OCA\OpenRegister\Kernel\Contracts\DocumentProcessingHandlerInterface;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectFileCopierInterface;
use OCA\OpenRegister\Kernel\Contracts\Search\FileScopeInterface;
use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;
use OCA\OpenRegister\Kernel\Contracts\TextExtractionServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\UploadServiceInterface;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCA\OpenRegister\Service\FileServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\UrlFileFetchInterface;
use OCA\OpenRegister\Service\File\UrlFileFetcher;
use OCA\OpenRegister\Service\Object\CopyFiles;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Kernel\Contracts\TextEntityDetectorInterface;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;

/**
 * Binds the Files component's interfaces and builds its services.
 */
final class FilesRegistrar
{
    /**
     * Interface => the class behind it. Office conversion goes through Nextcloud's
     * conversion API, so whichever office server registered a provider does the work.
     */
    public const BINDINGS = [
        AccessLinkReaderInterface::class          => AccessLinkReader::class,
        ChunkIndexOverviewInterface::class        => ChunkMapper::class,
        ChunkMapperInterface::class               => ChunkMapper::class,
        ChunkSourceReaderInterface::class         => ChunkMapper::class,
        ChunkStoreInterface::class                => ChunkMapper::class,
        DetectionPatternsVersionInterface::class  => PatternSetVersion::class,
        DocumentProcessingHandlerInterface::class => DocumentProcessingHandler::class,
        TextEntityDetectorInterface::class        => EntityRecognitionHandler::class,
        FileAccountInterface::class               => FileOwnershipHandler::class,
        FileAuditHandlerInterface::class          => FileAuditHandler::class,
        FileMapperInterface::class                => FileMapper::class,
        FileOwnerLookupInterface::class           => FileMapper::class,
        FileSizeTotalsInterface::class            => FileMapper::class,
        FileOwnershipHandlerInterface::class      => FileOwnershipHandler::class,
        AntivirusScannerInterface::class          => AntivirusScannerAdapter::class,
        FileReadRestrictionInterface::class       => FileRestrictionGuard::class,
        FileRestrictionGuardInterface::class      => FileRestrictionGuard::class,
        FileScopeInterface::class                 => FileScopeReader::class,
        FileSharingHandlerInterface::class        => FileSharingHandler::class,
        FileTagCatalogueInterface::class          => FileServiceInterface::class,
        NcOfficeConverterInterface::class         => ConversionManagerConverter::class,
        NoteServiceInterface::class               => NoteService::class,
        ObjectFileCopierInterface::class          => CopyFiles::class,
        ObjectFileRightsInterface::class          => ObjectFileAccess::class,
        ObjectFileRecordsInterface::class         => FileMapper::class,
        ObjectFilesArchiveInterface::class        => ObjectFilesArchive::class,
        ReadableFileHitsInterface::class          => FileReadScope::class,
        RegisterFolderProvisionerInterface::class => RegisterFolderProvisioner::class,
        RegisterFolderRecordsInterface::class     => RegisterFolderRecorder::class,
        RiskLevelServiceInterface::class          => RiskLevelService::class,
        ShareLinkUrlInterface::class              => ShareUrlBuilder::class,
        ShareUrlBuilderInterface::class           => ShareUrlBuilder::class,
        TaggingHandlerInterface::class            => TaggingHandler::class,
        TextExtractionServiceInterface::class     => TextExtractionService::class,
        UploadServiceInterface::class             => UploadService::class,
        UrlFileFetchInterface::class              => UrlFileFetcher::class,
    ];

    /**
     * Register the Files component's services.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec exclude Container wiring; no behaviour of its own.
     */
    public static function register(IRegistrationContext $context): void
    {
        $context->registerService(NoteService::class, self::buildNoteService(...));

        foreach (self::BINDINGS as $contract => $service) {
            $context->registerServiceAlias($contract, $service);
        }
    }//end register()

    /**
     * Build the NoteService service.
     *
     * @param ContainerInterface $container The container.
     *
     * @return NoteServiceInterface
     */
    private static function buildNoteService(ContainerInterface $container): NoteServiceInterface
    {
        return new NoteService(
            commentsManager: $container->get('OCP\Comments\ICommentsManager'),
            accounts: new NoteAccounts(
                userSession: $container->get('OCP\IUserSession'),
                userManager: $container->get('OCP\IUserManager')
            ),
            visibility: $container->get(TimelineVisibilityServiceInterface::class),
            versions: $container->get(NoteVersionService::class),
            events: $container->get('OCP\EventDispatcher\IEventDispatcher')
        );
    }//end buildNoteService()
}//end class
