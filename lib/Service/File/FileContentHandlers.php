<?php

/**
 * The handlers that tag, format, process and publish file content.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Kernel\Contracts\DocumentProcessingHandlerInterface;
use OCA\OpenRegister\Kernel\Contracts\TaggingHandlerInterface;

/**
 * The tagging, formatting, document processing and publishing handlers of the file service.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileContentHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param TaggingHandlerInterface            $taggingHandler    Tagging handler
	 * @param FileFormattingHandler              $fileFormatHandler File formatting handler
	 * @param DocumentProcessingHandlerInterface $docProcHandler    Document processing handler
	 * @param FilePublishingHandler              $filePubHandler    File publishing handler
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly TaggingHandlerInterface $taggingHandler,
		public readonly FileFormattingHandler $fileFormatHandler,
		public readonly DocumentProcessingHandlerInterface $docProcHandler,
		public readonly FilePublishingHandler $filePubHandler,
	) {
	}//end __construct()
}//end class
