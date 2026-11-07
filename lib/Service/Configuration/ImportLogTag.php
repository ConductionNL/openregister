<?php

/**
 * The tag that opens every log line of a configuration import.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
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

namespace OCA\OpenRegister\Service\Configuration;

/**
 * One place for the prefix the import collaborators put before a log message,
 * so an operator can filter all import lines on a single tag.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportLogTag {
	/**
	 * The prefix, trailing space included.
	 */
	public const PREFIX = '[ImportHandler] ';
}//end class
