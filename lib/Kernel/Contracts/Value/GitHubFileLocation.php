<?php

/**
 * A file in a GitHub repository branch.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Value
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use InvalidArgumentException;

/**
 * The owner, repository, path and branch of one file, and its Contents API path.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
 */
final class GitHubFileLocation
{

	/**
	 * Constructor.
	 *
	 * @param string $owner  The repository owner.
	 * @param string $repo   The repository name.
	 * @param string $path   The file path in the repository.
	 * @param string $branch The branch.
	 */
	public function __construct(
		public readonly string $owner,
		public readonly string $repo,
		public readonly string $path,
		public readonly string $branch,
	) {
	}//end __construct()

	/**
	 * The repository as `owner/repo`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function repoPath(): string {
		return "{$this->owner}/{$this->repo}";
	}//end repoPath()

	/**
	 * The path with each segment URL-encoded and the slashes kept.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function encodedPath(): string {
		return implode('/', array_map('rawurlencode', explode('/', $this->path)));
	}//end encodedPath()
	/**
	 * The file's raw.githubusercontent.com URL, every segment percent-encoded.
	 *
	 * @return string The URL.
	 *
	 * @throws InvalidArgumentException When a segment is unsafe.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	public function rawUrl(): string {
		return 'https://raw.githubusercontent.com/'.implode(
			'/',
			[
				self::encodeRawSegments(value: $this->owner),
				self::encodeRawSegments(value: $this->repo),
				self::encodeRawSegments(value: $this->branch),
				self::encodeRawSegments(value: $this->path),
			]
		);
	}//end rawUrl()

	/**
	 * Percent-encode each path segment of a raw-URL component.
	 *
	 * SEC-SVC-8: a crafted owner/repo/branch/path cannot inject extra path
	 * traversal, query, or host components into the raw.githubusercontent.com
	 * URL. Empty segments are dropped; a `..` segment or one with control
	 * characters is refused outright.
	 *
	 * @param string $value The component.
	 *
	 * @return string The encoded component.
	 *
	 * @throws InvalidArgumentException When a segment is unsafe.
	 */
	private static function encodeRawSegments(string $value): string {
		$encoded = [];
		foreach (explode('/', $value) as $segment) {
			if ($segment === '') {
				continue;
			}

			if ($segment === '..' || preg_match('/[\x00-\x1f\x7f]/', $segment) === 1) {
				throw new InvalidArgumentException('Invalid path segment.');
			}

			$encoded[] = rawurlencode($segment);
		}

		return implode('/', $encoded);
	}//end encodeRawSegments()
}//end class
