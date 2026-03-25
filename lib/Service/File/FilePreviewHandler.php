<?php

/**
 * FilePreviewHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IPreview;
use Psr\Log\LoggerInterface;

/**
 * Handles file preview and thumbnail generation.
 *
 * Uses Nextcloud's IPreview service to generate thumbnails for files.
 * Supports configurable dimensions and fallback for unsupported file types.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FilePreviewHandler
{

    /**
     * Default preview width in pixels.
     *
     * @var int
     */
    private const DEFAULT_WIDTH = 256;

    /**
     * Default preview height in pixels.
     *
     * @var int
     */
    private const DEFAULT_HEIGHT = 256;

    /**
     * Constructor for FilePreviewHandler.
     *
     * @param IPreview        $previewManager Preview manager for generating thumbnails.
     * @param IRootFolder     $rootFolder     Root folder for file access.
     * @param LoggerInterface $logger         Logger for logging operations.
     */
    public function __construct(
        private readonly IPreview $previewManager,
        private readonly IRootFolder $rootFolder,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Get a preview for a file.
     *
     * @param File     $file   The file to generate a preview for.
     * @param int|null $width  Optional width in pixels (default: 256).
     * @param int|null $height Optional height in pixels (default: 256).
     *
     * @return ISimpleFile The preview image file.
     *
     * @throws Exception If preview cannot be generated.
     */
    public function getPreview(File $file, ?int $width=null, ?int $height=null): ISimpleFile
    {
        $width  = $width ?? self::DEFAULT_WIDTH;
        $height = $height ?? self::DEFAULT_HEIGHT;

        // Check if preview is available for this file type.
        if ($this->previewManager->isAvailable($file) === false) {
            throw new Exception('Preview not available for this file type');
        }

        try {
            $preview = $this->previewManager->getPreview($file, $width, $height);

            $this->logger->debug(
                message: "[FilePreviewHandler] Generated preview for file {$file->getName()} ({$width}x{$height})",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            return $preview;
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[FilePreviewHandler] Failed to generate preview: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            throw new Exception('Preview not available for this file type');
        }//end try
    }//end getPreview()

    /**
     * Check if a preview is available for a given file.
     *
     * @param File $file The file to check.
     *
     * @return bool True if a preview can be generated.
     */
    public function isPreviewAvailable(File $file): bool
    {
        return $this->previewManager->isAvailable($file);
    }//end isPreviewAvailable()

    /**
     * Get the MIME type icon URL for a file type.
     *
     * Used as a fallback when preview is not available.
     *
     * @param string $mimeType The MIME type.
     *
     * @return string The icon URL path.
     */
    public function getMimeTypeIconUrl(string $mimeType): string
    {
        return $this->previewManager->isMimeSupported($mimeType) === true ? '' : '/core/img/filetypes/file.svg';
    }//end getMimeTypeIconUrl()
}//end class
