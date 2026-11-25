<?php

/**
 * UrlFileFetcher
 *
 * Fetches a file's bytes from a URL behind the anti-SSRF guard, with the size
 * ceiling enforced while reading (REQ-FCU-001, REQ-FCU-002).
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

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\UrlFileFetchInterface;
use OCA\OpenRegister\Service\SecurityService;

/**
 * The shared fetch: the address checked first, one request, at most the
 * ceiling read, and every failure reported with its reason.
 *
 * @spec openspec/changes/files-create-from-url/specs
 */
class UrlFileFetcher implements UrlFileFetchInterface {

	/**
	 * The bytes read per chunk.
	 *
	 * @var int
	 */
	private const CHUNK = 8192;

	/**
	 * Constructor.
	 *
	 * @param UrlStreamOpener $opener Opens the URL.
	 */
	public function __construct(
		private readonly UrlStreamOpener $opener,
	) {
	}//end __construct()

	/**
	 * Fetch the bytes at a URL.
	 *
	 * Strict (the default, creating a file from a URL) refuses any answer outside
	 * 2xx and an empty body. Not strict keeps the object-save file property's
	 * long-standing contract: an answer below 400, or one without a status line,
	 * is read, and an empty body is returned as it is.
	 *
	 * @param string $url      The URL.
	 * @param int    $maxBytes The size ceiling; reading stops past it.
	 * @param bool   $strict   Whether a non-2xx answer and an empty body are refused.
	 *
	 * @return string The body.
	 *
	 * @throws UrlFetchRefused With the reason when no bytes can be returned.
	 *
	 * @spec openspec/changes/files-create-from-url/specs
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The address guard is SecurityService's static check, shared by every fetch.
	 */
	public function fetch(string $url, int $maxBytes, bool $strict = true): string {
		try {
			SecurityService::assertSafeFetchUrl($url);
		} catch (InvalidArgumentException $refusal) {
			throw new UrlFetchRefused(reason: UrlFetchRefused::REFUSED_ADDRESS, message: $refusal->getMessage());
		}

		$opened = $this->opener->open(url: $url);
		if ($opened['stream'] === null) {
			throw new UrlFetchRefused(reason: UrlFetchRefused::TIMEOUT, message: 'The URL could not be reached in time.');
		}

		try {
			$status = $opened['status'];
			if (self::isRefusedStatus(status: $status, strict: $strict) === true) {
				throw new UrlFetchRefused(reason: UrlFetchRefused::STATUS, message: sprintf('The URL answered with status %s.', ($status ?? 'unknown')));
			}

			$body = self::read(stream: $opened['stream'], maxBytes: $maxBytes);
		} finally {
			fclose($opened['stream']);
		}

		if ($body === '' && $strict === true) {
			throw new UrlFetchRefused(reason: UrlFetchRefused::EMPTY, message: 'The URL answered with an empty body.');
		}

		return $body;
	}//end fetch()

	/**
	 * Whether an answer's status refuses the fetch.
	 *
	 * @param int|null $status The HTTP status, null without a status line.
	 * @param bool     $strict Whether only 2xx is accepted.
	 *
	 * @return bool
	 */
	private static function isRefusedStatus(?int $status, bool $strict): bool {
		if ($strict === false) {
			return $status !== null && $status >= 400;
		}

		return $status === null || $status < 200 || $status > 299;
	}//end isRefusedStatus()

	/**
	 * Read a stream, stopping as soon as it passes the ceiling.
	 *
	 * @param resource $stream   The body stream.
	 * @param int      $maxBytes The ceiling.
	 *
	 * @return string
	 *
	 * @throws UrlFetchRefused When the body passes the ceiling or the read times out.
	 */
	private static function read($stream, int $maxBytes): string {
		$body = '';
		while (feof($stream) === false) {
			$chunk = fread($stream, self::CHUNK);
			if ($chunk === false) {
				break;
			}

			$body .= $chunk;
			if (strlen($body) > $maxBytes) {
				throw new UrlFetchRefused(reason: UrlFetchRefused::TOO_LARGE, message: sprintf('The file is larger than %d bytes.', $maxBytes));
			}

			if (stream_get_meta_data($stream)['timed_out'] === true) {
				throw new UrlFetchRefused(reason: UrlFetchRefused::TIMEOUT, message: 'The URL stopped answering in time.');
			}
		}

		return $body;
	}//end read()
}//end class
