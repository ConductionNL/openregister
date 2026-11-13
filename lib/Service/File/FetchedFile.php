<?php

/**
 * FetchedFile
 *
 * The bytes of a file fetched from a URL, with where and when they came from
 * (REQ-FCU-001).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/files-create-from-url/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * Fetched bytes and their provenance: the URL, the moment and the SHA-256.
 *
 * @spec openspec/changes/files-create-from-url/specs
 */
final class FetchedFile {

	/**
	 * Constructor.
	 *
	 * @param string $bytes     The fetched body.
	 * @param string $sourceUrl The URL the body came from.
	 * @param string $fetchedAt The moment of the fetch, ISO 8601 in UTC.
	 * @param string $sha256    The SHA-256 of the body, lowercase hex.
	 */
	public function __construct(
		public readonly string $bytes,
		public readonly string $sourceUrl,
		public readonly string $fetchedAt,
		public readonly string $sha256,
	) {
	}//end __construct()

	/**
	 * The provenance as the file's metadata.
	 *
	 * @return array{sourceUrl: string, fetchedAt: string, sha256: string}
	 *
	 * @spec openspec/changes/files-create-from-url/specs
	 */
	public function metadata(): array {
		return [
			'sourceUrl' => $this->sourceUrl,
			'fetchedAt' => $this->fetchedAt,
			'sha256'    => $this->sha256,
		];
	}//end metadata()

	/**
	 * The body as a stream, so the writer stores the bytes as they are and
	 * never reads them as base64.
	 *
	 * @return resource The stream, rewound.
	 *
	 * @spec openspec/changes/files-create-from-url/specs
	 */
	public function stream() {
		$stream = fopen('php://temp', 'r+b');
		fwrite($stream, $this->bytes);
		rewind($stream);

		return $stream;
	}//end stream()
}//end class
