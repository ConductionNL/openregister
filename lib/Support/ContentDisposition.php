<?php

/**
 * ContentDisposition — an RFC 6266 Content-Disposition header value.
 *
 * Emits a sanitised ASCII `filename="..."` fallback plus a UTF-8 `filename*`
 * parameter, so a hostile or non-ASCII file name cannot split the header or
 * corrupt the response.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\OpenRegister\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Support;

/**
 * How a file is offered ('inline' or 'attachment') and under which name.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class ContentDisposition {
	/**
	 * Build the disposition.
	 *
	 * @param string $disposition Either 'inline' or 'attachment'.
	 * @param string $filename    The raw file name.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly string $disposition,
		public readonly string $filename,
	) {
	}//end __construct()

	/**
	 * The encoded header value.
	 *
	 * @return string `<disposition>; filename="<ascii>"; filename*=UTF-8''<encoded>`.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function headerValue(): string {
		// Strip control characters (incl. CR/LF) that could split headers.
		$clean = preg_replace('/[\x00-\x1F\x7F]/', '', $this->filename);
		if ($clean === null) {
			$clean = '';
		}

		// ASCII fallback: replace non-ASCII and quote/backslash with underscore.
		$ascii = preg_replace('/[^\x20-\x7E]/', '_', $clean);
		if ($ascii === null) {
			$ascii = '';
		}

		$ascii = str_replace(['\\', '"'], '_', $ascii);

		// RFC 5987 / 6266 UTF-8 encoded form for capable clients.
		$encoded = rawurlencode($clean);

		return $this->disposition.'; filename="'.$ascii.'"; filename*=UTF-8\'\''.$encoded;
	}//end headerValue()
}//end class
