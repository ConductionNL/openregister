<?php

/**
 * RegisterImportRoute — the path a forced register import took.
 *
 * Carries which path ran ({@see AppRegisterImportRunner::PATH_APP} or
 * {@see AppRegisterImportRunner::PATH_OPENREGISTER}) and the method that
 * ran, and turns them into the runner's result shape.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Value object: import path and the method that ran.
 */
final class RegisterImportRoute {
	/**
	 * Constructor.
	 *
	 * @param string $path The import path (app|openregister).
	 * @param string $via  The method that ran, for the operator.
	 */
	public function __construct(
		public readonly string $path,
		public readonly string $via,
	) {
	}//end __construct()

	/**
	 * The result of an import that succeeded.
	 *
	 * @param string $message The app's message, if any.
	 *
	 * @return array{success: bool, path: string, via: string, message: string}
	 */
	public function succeeded(string $message): array {
		return ['success' => true, 'path' => $this->path, 'via' => $this->via, 'message' => $message];
	}//end succeeded()

	/**
	 * The result of an import that failed.
	 *
	 * @param string $message Why it failed.
	 *
	 * @return array{success: bool, path: string, via: string, message: string}
	 */
	public function failed(string $message): array {
		return ['success' => false, 'path' => $this->path, 'via' => $this->via, 'message' => $message];
	}//end failed()
}//end class
