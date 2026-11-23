<?php

/**
 * UrlFileFetchInterface: the shared URL fetch, for callers outside the Files component to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Kernel
 * @package  OCA\OpenRegister\Kernel\Contracts
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

/**
 * The public surface of the Files component's UrlFileFetcher.
 *
 * @spec openspec/changes/files-create-from-url/specs
 */
interface UrlFileFetchInterface {

	/**
	 * Fetch the bytes at a URL: the address checked first, one request, at most the ceiling read.
	 *
	 * Strict refuses any answer outside 2xx and an empty body; not strict reads an
	 * answer below 400 (or one without a status line) and returns an empty body as it is.
	 *
	 * @param string $url      The URL.
	 * @param int    $maxBytes The size ceiling; reading stops past it.
	 * @param bool   $strict   Whether a non-2xx answer and an empty body are refused.
	 *
	 * @return string The body.
	 *
	 * @throws \RuntimeException A UrlFetchRefused carrying the reason when no bytes can be returned.
	 *
	 * @spec openspec/changes/files-create-from-url/specs
	 */
	public function fetch(string $url, int $maxBytes, bool $strict = true): string;
}//end interface
