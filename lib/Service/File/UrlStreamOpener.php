<?php

/**
 * UrlStreamOpener
 *
 * Opens a URL as a stream without following redirects (REQ-FCU-001).
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
 * The one place a URL is opened: 30 seconds, no redirects, any status read.
 *
 * @spec openspec/changes/files-create-from-url/specs
 */
class UrlStreamOpener {

	/**
	 * The stream options: a 30 second timeout, redirects not followed, and a
	 * non-2xx answer readable so its status can be reported.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const OPTIONS = [
		'http' => [
			'timeout'         => 30,
			'user_agent'      => 'OpenRegister/1.0',
			'follow_location' => false,
			'max_redirects'   => 0,
			'ignore_errors'   => true,
		],
	];

	/**
	 * Open a URL.
	 *
	 * @param string $url The URL, already checked as safe to fetch.
	 *
	 * @return array{stream: resource|null, status: int|null} The body stream and the HTTP status; a null
	 *                                                         stream when the connection failed.
	 *
	 * @spec openspec/changes/files-create-from-url/specs
	 */
	public function open(string $url): array {
		$stream = @fopen($url, 'rb', false, stream_context_create(self::OPTIONS));
		if ($stream === false) {
			return ['stream' => null, 'status' => null];
		}

		return ['stream' => $stream, 'status' => self::statusOf(headers: stream_get_meta_data($stream)['wrapper_data'])];
	}//end open()

	/**
	 * The status of the first response line, or null.
	 *
	 * @param mixed $headers The wrapper's response header lines.
	 *
	 * @return int|null
	 */
	private static function statusOf(mixed $headers): ?int {
		if (is_array($headers) === false) {
			return null;
		}

		foreach ($headers as $line) {
			if (is_string($line) === true && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
				return (int) $match[1];
			}
		}

		return null;
	}//end statusOf()
}//end class
