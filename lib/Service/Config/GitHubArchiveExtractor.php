<?php

/**
 * Unpacks a GitHub repository tarball into the files a read wants.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/store-through-federated-config
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use FilesystemIterator;
use OCP\ITempManager;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Turns the bytes of `GET /repos/{o}/{r}/tarball/{ref}` into a `path => contents` map.
 *
 * GitHub's tarball holds one root folder, `<owner>-<repo>-<sha>/`; paths come
 * back without it. Makes no outbound call. Never partial: any failure, or
 * wanted files over the byte bound, answers null, so the caller can fall back
 * to a file-by-file read instead of merging two partial sets.
 *
 * @spec openspec/changes/store-through-federated-config
 */
class GitHubArchiveExtractor {

	/**
	 * Constructor.
	 *
	 * @param ITempManager|null $tempManager Hands out temporary paths; the system temp folder when null.
	 */
	public function __construct(
		private readonly ?ITempManager $tempManager = null,
	) {
	}//end __construct()

	/**
	 * The wanted files of a tarball, or null when it cannot be read within the bound.
	 *
	 * @param string   $body     The tarball bytes.
	 * @param callable $wanted   `fn (string $path): bool` on paths without the archive root.
	 * @param int      $maxBytes The most bytes the archive, and the wanted files together, may hold.
	 *
	 * @return array<string, string>|null
	 *
	 * @spec openspec/changes/store-through-federated-config
	 */
	public function extract(string $body, callable $wanted, int $maxBytes): ?array {
		if ($body === '' || strlen($body) > $maxBytes) {
			return null;
		}

		$archivePath = $this->temporaryFile();
		$extractDir  = $this->temporaryFolder();
		try {
			if ($archivePath === null || $extractDir === null || file_put_contents($archivePath, $body) === false) {
				return null;
			}

			(new PharData($archivePath))->extractTo($extractDir, null, true);
			$root = self::singleRoot(extractDir: $extractDir);
			if ($root === null) {
				return null;
			}

			return self::collect(root: $root, wanted: $wanted, maxBytes: $maxBytes);
		} catch (Throwable $e) {
			return null;
		} finally {
			self::cleanup(archivePath: $archivePath, extractDir: $extractDir);
		}//end try
	}//end extract()

	/**
	 * A temporary file path ending in `.tar.gz`, or null.
	 *
	 * @return string|null
	 */
	private function temporaryFile(): ?string {
		if ($this->tempManager !== null) {
			$path = $this->tempManager->getTemporaryFile('.tar.gz');
			if (is_string($path) === false) {
				return null;
			}

			return $path;
		}

		$base = tempnam(sys_get_temp_dir(), 'or-archive-');
		if ($base === false) {
			return null;
		}

		unlink($base);
		return $base . '.tar.gz';
	}//end temporaryFile()

	/**
	 * A fresh temporary folder, or null.
	 *
	 * @return string|null
	 */
	private function temporaryFolder(): ?string {
		if ($this->tempManager !== null) {
			$path = $this->tempManager->getTemporaryFolder();
			if (is_string($path) === false) {
				return null;
			}

			return rtrim($path, '/');
		}

		$path = sys_get_temp_dir() . '/or-extract-' . bin2hex(random_bytes(8));
		if (mkdir($path, 0700) === false) {
			return null;
		}

		return $path;
	}//end temporaryFolder()

	/**
	 * The archive's one root folder, or null when it has none or several.
	 *
	 * @param string $extractDir Where the archive was unpacked.
	 *
	 * @return string|null
	 */
	private static function singleRoot(string $extractDir): ?string {
		$roots = array_values(
			array_filter(
				(array)scandir($extractDir),
				static fn ($entry): bool => (is_string($entry) === true && $entry !== '.' && $entry !== '..' && is_dir($extractDir . '/' . $entry) === true)
			)
		);

		if (count($roots) !== 1) {
			return null;
		}

		return $extractDir . '/' . $roots[0];
	}//end singleRoot()

	/**
	 * The wanted files below a root, sorted by path, or null when they exceed the bound.
	 *
	 * @param string   $root     The archive's root folder.
	 * @param callable $wanted   Whether a relative path is wanted.
	 * @param int      $maxBytes The most bytes the wanted files may hold together.
	 *
	 * @return array<string, string>|null
	 */
	private static function collect(string $root, callable $wanted, int $maxBytes): ?array {
		$files = [];
		$bytes = 0;
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $info) {
			if (($info instanceof SplFileInfo) === false || $info->isFile() === false || $info->isLink() === true) {
				continue;
			}

			$relative = ltrim(str_replace('\\', '/', substr($info->getPathname(), strlen($root))), '/');
			if ($wanted($relative) !== true) {
				continue;
			}

			$contents = file_get_contents($info->getPathname());
			if ($contents === false) {
				return null;
			}

			$bytes += strlen($contents);
			if ($bytes > $maxBytes) {
				return null;
			}

			$files[$relative] = $contents;
		}//end foreach

		ksort($files, SORT_STRING);
		return $files;
	}//end collect()

	/**
	 * Remove the temporary archive and the unpacked tree.
	 *
	 * @param string|null $archivePath The archive file.
	 * @param string|null $extractDir  The unpack folder.
	 *
	 * @return void
	 */
	private static function cleanup(?string $archivePath, ?string $extractDir): void {
		if ($archivePath !== null && is_file($archivePath) === true) {
			unlink($archivePath);
		}

		if ($extractDir === null || is_dir($extractDir) === false) {
			return;
		}

		$entries = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($entries as $info) {
			if (($info instanceof SplFileInfo) === false) {
				continue;
			}

			if ($info->isDir() === true && $info->isLink() === false) {
				rmdir($info->getPathname());
				continue;
			}

			unlink($info->getPathname());
		}

		rmdir($extractDir);
	}//end cleanup()
}//end class
