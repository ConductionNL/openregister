<?php

/**
 * OpenRegister upload check
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
 * One uploaded file being checked against its property: the parsed file data, the prefix of every refusal, and the property's file configuration.
 *
 * Built by FileUploadGuard for one check and never leaves FileUploadGuard.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class UploadCheck {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $fileData    The parsed file data.
	 * @param string               $errorPrefix The error message prefix.
	 * @param array<string, mixed> $fileConfig  The file property configuration; empty when the check does not read it.
	 */
	public function __construct(
		public readonly array $fileData,
		public readonly string $errorPrefix,
		public readonly array $fileConfig = [],
	) {
	}//end __construct()
}//end class
