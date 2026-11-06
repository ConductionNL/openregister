<?php

/**
 * FilePreviewHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCP\Files\File;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IPreview;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Files\Service\File\PreviewSize;

/**
 * Handles file preview and thumbnail generation.
 *
 * Uses Nextcloud's IPreview service to generate thumbnails for files.
 * Supports configurable dimensions and fallback for unsupported file types.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class FilePreviewHandler {

	/**
	 * The message thrown when no preview can be made.
	 *
	 * @var string
	 */
	private const UNAVAILABLE = 'Preview not available for this file type';

	/**
	 * Constructor for FilePreviewHandler.
	 *
	 * @param IPreview        $previewManager Preview manager for generating thumbnails.
	 * @param LoggerInterface $logger         Logger for logging operations.
	 */
	public function __construct(
		private readonly IPreview $previewManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get a preview for a file.
	 *
	 * @param File             $file The file to generate a preview for.
	 * @param PreviewSize|null $size The preview size (default: 256x256).
	 *
	 * @return ISimpleFile The preview image file.
	 *
	 * @throws Exception If preview cannot be generated.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getPreview(File $file, ?PreviewSize $size = null): ISimpleFile {
		if ($this->previewManager->isAvailable($file) === false) {
			throw new Exception(self::UNAVAILABLE);
		}

		try {
			return $this->generate(file: $file, size: $size ?? new PreviewSize());
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[FilePreviewHandler] Failed to generate preview: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw new Exception(self::UNAVAILABLE);
		}
	}//end getPreview()

	/**
	 * Generates the preview and logs its size.
	 *
	 * @param File        $file The file to generate a preview for.
	 * @param PreviewSize $size The preview size.
	 *
	 * @return ISimpleFile The preview image file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	private function generate(File $file, PreviewSize $size): ISimpleFile {
		$preview = $this->previewManager->getPreview($file, $size->width, $size->height);

		$this->logger->debug(
			message: "[FilePreviewHandler] Generated preview for file {$file->getName()} ({$size->label()})",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $preview;
	}//end generate()
}//end class
