<?php

/**
 * Reads a configuration file from inside the Nextcloud root.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use Exception;

/**
 * Contains a relative path inside the Nextcloud root, then parses the JSON behind it.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class LocalConfigurationFile
{

	private const FALLBACK_ROOT = '/var/www/html';

	/**
	 * Build the reader.
	 *
	 * @param string $appDataPath The app data path, three levels below the Nextcloud root.
	 */
	public function __construct(private readonly string $appDataPath)
	{
	}//end __construct()

	/**
	 * Read a configuration file and note where it came from, so the cron job can track it.
	 *
	 * @param string $filePath The path relative to the Nextcloud root.
	 *
	 * @return array<string, mixed> The configuration.
	 *
	 * @throws Exception When the path is refused or the file cannot be read or parsed.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function read(string $filePath): array
	{
		$this->refuseUnsafePath(filePath: $filePath);
		$data = $this->parse(filePath: $filePath, fullPath: $this->containedPath(filePath: $filePath));

		$data['x-openregister'] ??= [];
		$data['x-openregister']['sourceUrl']  ??= $filePath;
		$data['x-openregister']['sourceType'] ??= 'local';
		return $data;
	}//end read()

	/**
	 * Refuse absolute paths, traversal sequences and control characters up front.
	 *
	 * @param string $filePath The requested path.
	 *
	 * @return void
	 *
	 * @throws Exception When the path is refused.
	 */
	private function refuseUnsafePath(string $filePath): void
	{
		if (str_starts_with($filePath, '/') === true
			|| str_contains($filePath, '..') === true
			|| preg_match('/[\x00-\x1f]/', $filePath) === 1
		) {
			throw new Exception("Invalid configuration file path: {$filePath}");
		}
	}//end refuseUnsafePath()

	/**
	 * Resolve the path; the real path must live under the Nextcloud root.
	 *
	 * @param string $filePath The requested path.
	 *
	 * @return string The real path.
	 *
	 * @throws Exception When the file is missing or outside the root.
	 */
	private function containedPath(string $filePath): string
	{
		$root     = $this->appDataPath.'/../../../';
		$baseDir  = $this->realPathOr(path: $root, fallback: self::FALLBACK_ROOT);
		$fullPath = $this->realPathOr(path: $root.$filePath, fallback: self::FALLBACK_ROOT.'/'.$filePath);

		if ($fullPath === false || file_exists($fullPath) === false) {
			throw new Exception("Configuration file not found: {$filePath}");
		}

		if ($baseDir === false || str_starts_with($fullPath, $baseDir.'/') === false) {
			throw new Exception("Configuration file is outside the allowed directory: {$filePath}");
		}

		return $fullPath;
	}//end containedPath()

	/**
	 * The real path of a path, or else of its fallback.
	 *
	 * @param string $path     The path to try first.
	 * @param string $fallback The path to try next.
	 *
	 * @return string|false The real path, or false when neither exists.
	 */
	private function realPathOr(string $path, string $fallback): string|false
	{
		$real = realpath($path);
		if ($real !== false) {
			return $real;
		}

		return realpath($fallback);
	}//end realPathOr()

	/**
	 * Read and decode the file.
	 *
	 * @param string $filePath The requested path, for the error.
	 * @param string $fullPath The real path.
	 *
	 * @return array<string, mixed> The decoded JSON.
	 *
	 * @throws Exception When the file cannot be read, is not JSON or holds a single value.
	 */
	private function parse(string $filePath, string $fullPath): array
	{
		$jsonContent = file_get_contents($fullPath);
		if ($jsonContent === false) {
			throw new Exception("Failed to read configuration file: {$filePath}");
		}

		$data = json_decode($jsonContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON in configuration file: '.json_last_error_msg());
		}

		return $this->structured(data: $data, filePath: $filePath);
	}//end parse()

	/**
	 * Refuse a decoded document that cannot carry the x-openregister section.
	 *
	 * @param mixed  $data     The decoded JSON.
	 * @param string $filePath The requested path, for the error.
	 *
	 * @return array<string, mixed> The same document.
	 *
	 * @throws Exception When the document, or its x-openregister section, is a single value.
	 */
	private function structured(mixed $data, string $filePath): array
	{
		if (is_array($data) === false || is_array($data['x-openregister'] ?? []) === false) {
			throw new Exception("Configuration file is not a JSON object: {$filePath}");
		}

		return $data;
	}//end structured()
}//end class
