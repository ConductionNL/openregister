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

use OCA\OpenRegister\Files\Db\ChunkIndexOverviewInterface;
use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Files\Db\ChunkStoreInterface;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Files\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;
use OCA\OpenRegister\Files\Service\File\Office\ConversionManagerConverter;
use OCA\OpenRegister\Files\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCA\OpenRegister\Files\Service\File\RegisterFolderProvisioner;
use OCA\OpenRegister\Files\Service\File\RegisterFolderProvisionerInterface;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilder;
use OCA\OpenRegister\Files\Service\File\ShareUrlBuilderInterface;
use OCA\OpenRegister\Files\Service\File\TaggingHandler;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Files\Service\NoteVersionService;
use OCA\OpenRegister\Files\Service\RiskLevelService;
use OCA\OpenRegister\Files\Service\RiskLevelServiceInterface;
use OCA\OpenRegister\Files\Service\Sharing\AccessLinkReader;
use OCA\OpenRegister\Files\Service\TextExtractionService;
use OCA\OpenRegister\Files\Service\UploadService;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\DocumentProcessingHandlerInterface;
use OCA\OpenRegister\Kernel\Contracts\NoteServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;
use OCA\OpenRegister\Kernel\Contracts\TextExtractionServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\UploadServiceInterface;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Psr\Container\ContainerInterface;

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
        DocumentProcessingHandlerInterface::class => DocumentProcessingHandler::class,
        FileMapperInterface::class                => FileMapper::class,
        FileOwnershipHandlerInterface::class      => FileOwnershipHandler::class,
        NcOfficeConverterInterface::class         => ConversionManagerConverter::class,
        NoteServiceInterface::class               => NoteService::class,
        RegisterFolderProvisionerInterface::class => RegisterFolderProvisioner::class,
        RiskLevelServiceInterface::class          => RiskLevelService::class,
        ShareUrlBuilderInterface::class           => ShareUrlBuilder::class,
        TaggingHandlerInterface::class            => TaggingHandler::class,
        TextExtractionServiceInterface::class     => TextExtractionService::class,
        UploadServiceInterface::class             => UploadService::class,
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
            userSession: $container->get('OCP\IUserSession'),
            userManager: $container->get('OCP\IUserManager'),
            visibility: $container->get(TimelineVisibilityServiceInterface::class),
            versions: $container->get(NoteVersionService::class)
        );
    }//end buildNoteService()
}//end class
