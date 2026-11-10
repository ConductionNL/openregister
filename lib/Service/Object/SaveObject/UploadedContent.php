<?php

/**
 * OpenRegister uploaded content
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

/**
 * The content of one uploaded file being screened for executable code, with its name and the prefix of every refusal.
 *
 * Built by FileUploadGuard::blockExecutableContent() and never leaves FileUploadGuard.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class UploadedContent {
	/**
	 * Constructor.
	 *
	 * @param string $content     The file content to check.
	 * @param string $errorPrefix The error message prefix.
	 * @param string $fileName    The filename, used for its extension only; '' when unknown.
	 */
	public function __construct(
		public readonly string $content,
		public readonly string $errorPrefix,
		public readonly string $fileName,
	) {
	}//end __construct()
}//end class
