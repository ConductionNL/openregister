<?php

/**
 * TraceArguments: run an operation whose exceptions carry no arguments in their stack traces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-an-import-must-not-write-its-configuration-into-the-log
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * Withhold arguments from the stack traces created while an operation runs.
 *
 * An exception records the arguments of every frame below it unless PHP runs
 * with `zend.exception_ignore_args` on, which the production php.ini sets and
 * a container without a php.ini does not. Nextcloud writes those arguments
 * into its log, five levels deep and unabridged at the debug level, and
 * `occ upgrade` always runs at the debug level.
 *
 * A configuration import passes the whole decoded configuration down its call
 * chain, and Nextcloud core logs a trace for every read of a table the same
 * request wrote to. Measured on an app with 538 schemas: 6.8 MB a log entry,
 * four entries a schema, and 895 of the import's 901 seconds spent writing
 * them (#9945).
 *
 * The setting is put back afterwards, so nothing outside the operation loses
 * the arguments of its traces.
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-an-import-must-not-write-its-configuration-into-the-log
 */
final class TraceArguments {

	private const SETTING = 'zend.exception_ignore_args';

	/**
	 * Run an operation with trace arguments withheld.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What the operation returns.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-an-import-must-not-write-its-configuration-into-the-log
	 */
	public function withheldDuring(callable $operation): mixed {
		$before = ini_set(self::SETTING, '1');

		try {
			return $operation();
		} finally {
			if ($before !== false) {
				ini_set(self::SETTING, $before);
			}
		}
	}//end withheldDuring()
}//end class
