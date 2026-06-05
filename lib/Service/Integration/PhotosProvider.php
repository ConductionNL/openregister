<?php

/**
 * PhotosProvider — OpenRegister Photos integration provider.
 *
 * Registers the Photos integration with id='photos', group='docs',
 * requiredApp='photos', storage='link-table'. Photos are a filtered view
 * of the Files integration, sharing the same NC folder storage and filtered
 * by image MIME type at query time (ADR-019, AD-1).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-photos/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Photos integration provider.
 *
 * Declares the 'photos' integration for the registry. Photos share the NC
 * folder-based file storage with the Files integration; PhotoService filters
 * to image MIME types and adds EXIF enrichment per AD-1 and AD-2.
 */
class PhotosProvider implements IntegrationProvider
{
    /**
     * Image MIME types recognised as photos.
     *
     * @var string[]
     */
    public const IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/heic',
        'image/heif',
        'image/tiff',
        'image/bmp',
        'image/svg+xml',
    ];

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getId(): string
    {
        return 'photos';
    }//end getId()

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Photos';
    }//end getLabel()

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getIcon(): string
    {
        return 'Image';
    }//end getIcon()

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getGroup(): string
    {
        return 'docs';
    }//end getGroup()

    /**
     * {@inheritDoc}
     *
     * @return string|null
     */
    public function getRequiredApp(): ?string
    {
        return 'photos';
    }//end getRequiredApp()

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    /**
     * {@inheritDoc}
     *
     * @return string|null
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()
}//end class
