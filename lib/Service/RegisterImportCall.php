<?php

/**
 * OpenRegister RegisterImportCall
 *
 * One shipped-register import operation and who asked for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use Closure;

/**
 * An import operation to run under the administrator's shipped-authorization decision,
 * and who asked for it.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
final class RegisterImportCall {

	/**
	 * Constructor.
	 *
	 * @param string   $via       Who asked, for the audit trail.
	 * @param Closure  $operation Runs the import; returns success, path, via, message, and importJobId and
	 *                            importAppId when it recorded an import job.
	 */
	public function __construct(
		public readonly string $via,
		public readonly Closure $operation,
	) {
	}//end __construct()
}//end class
