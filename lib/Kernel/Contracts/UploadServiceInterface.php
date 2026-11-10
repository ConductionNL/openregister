<?php

/**
 * UploadServiceInterface: the public surface of UploadService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service
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

namespace OCA\OpenRegister\Kernel\Contracts;

use OCP\AppFramework\Http\JSONResponse;

/**
 * Every public method of UploadService. Callers depend on this interface, not on the class.
 */
interface UploadServiceInterface {

	/**
	 * Gets the uploaded JSON from the request data and returns it as a PHP array
	 *
	 * Processes uploaded JSON data from multiple sources in priority order:
	 * 1. Uploaded file (if 'file' key present)
	 * 2. URL (if 'url' key present - fetches JSON from URL)
	 * 3. Direct JSON (if 'json' key present - JSON string or array)
	 *
	 * Removes internal parameters (starting with '_') before processing.
	 *
	 * @param array<string, mixed> $data All request parameters
	 *
	 * @return array<string, mixed>|JSONResponse PHP array with uploaded JSON data or JSONResponse with error message
	 *
	 * @throws \Exception If file processing fails
	 * @throws \GuzzleHttp\Exception\GuzzleException If URL fetching fails
	 *
	 * @spec exclude Source-dispatch helper routing to per-source (file/url/json) processors; no business rule of its own.
	 */
	public function getUploadedJson(array $data): array|JSONResponse;
}//end interface
