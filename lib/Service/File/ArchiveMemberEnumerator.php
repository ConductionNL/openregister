<?php

/**
 * ArchiveMemberEnumerator
 *
 * Lists the members of a zip archive, and of zips nested one level inside it,
 * under stated bounds, without writing any member to the file system
 * (REQ-AMS-001). Shared with the upload malware scan.
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
 * @spec openspec/changes/archive-members-are-searchable/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\Files\File;
use OCP\Files\IMimeTypeDetector;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;
use ZipArchive;

/**
 * Enumerates archive members and stops at the first bound exceeded.
 *
 * A member path that leaves the archive is listed as refused and never
 * resolved; an encrypted member is listed as encrypted and not opened. A
 * nested zip is read from the outer archive into a temporary file of its
 * already-bounded size, never extracted next to the archive.
 *
 * @spec openspec/changes/archive-members-are-searchable/specs
 */
class ArchiveMemberEnumerator {

	/**
	 * The MIME type of a zip.
	 *
	 * @var string
	 */
	private const ZIP_MIME = 'application/zip';

	/**
	 * Constructor.
	 *
	 * @param IMimeTypeDetector   $mimes    Detects a member's MIME type from its name.
	 * @param ITempManager        $temp     Temporary files for the archive and a nested zip.
	 * @param FileSettingsHandler $settings The file settings holding the bounds.
	 * @param LoggerInterface     $logger   The logger.
	 */
	public function __construct(
		private readonly IMimeTypeDetector $mimes,
		private readonly ITempManager $temp,
		private readonly FileSettingsHandler $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Enumerate a Nextcloud file that is a zip archive.
	 *
	 * @param File $file The archive.
	 *
	 * @return ArchiveListing
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function enumerate(File $file): ArchiveListing {
		$path = $this->temp->getTemporaryFile('.zip');
		if ($path === false) {
			return new ArchiveListing();
		}

		$source = $file->fopen('r');
		$target = fopen($path, 'w');
		if ($source === false || $target === false) {
			return new ArchiveListing();
		}

		stream_copy_to_stream($source, $target);
		fclose($source);
		fclose($target);

		return $this->enumeratePath(path: $path);
	}//end enumerate()

	/**
	 * Enumerate a zip archive at a local path.
	 *
	 * @param string             $path   The archive's local path.
	 * @param ArchiveBounds|null $bounds The bounds; the file settings' when null.
	 *
	 * @return ArchiveListing An empty listing when the file is not a readable zip.
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function enumeratePath(string $path, ?ArchiveBounds $bounds=null): ArchiveListing {
		$walk = new ArchiveWalk(bounds: ($bounds ?? $this->bounds()), listing: new ArchiveListing());
		$zip  = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			return $walk->listing;
		}

		$this->walk(zip: $zip, prefix: '', depth: 1, walk: $walk);
		$zip->close();

		return $walk->listing;
	}//end enumeratePath()

	/**
	 * Whether a member path leaves the archive: absolute, a drive letter, a
	 * NUL byte or a `..` segment.
	 *
	 * @param string $name The member path as stored.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public static function leavesTheArchive(string $name): bool {
		if ($name === '' || str_contains($name, "\0") === true || preg_match('#^([/\\\\]|[A-Za-z]:)#', $name) === 1) {
			return true;
		}

		return in_array('..', preg_split('#[/\\\\]#', $name), true);
	}//end leavesTheArchive()

	/**
	 * The configured bounds, else the defaults.
	 *
	 * @return ArchiveBounds
	 */
	private function bounds(): ArchiveBounds {
		try {
			$settings = $this->settings->getFileSettingsOnly();
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[ArchiveMemberEnumerator] File settings unreadable, using the default bounds',
				['exception' => $failure->getMessage()]
			);
			return new ArchiveBounds();
		}

		return ArchiveBounds::fromSettings(fileSettings: $settings);
	}//end bounds()

	/**
	 * List one archive's members into the walk; false when a bound stopped it.
	 *
	 * @param ZipArchive  $zip    The open archive.
	 * @param string      $prefix The path of the archive inside the outer one, with a trailing slash, or ''.
	 * @param int         $depth  1 for the outer archive.
	 * @param ArchiveWalk $walk   The running totals and the listing.
	 *
	 * @return bool
	 */
	private function walk(ZipArchive $zip, string $prefix, int $depth, ArchiveWalk $walk): bool {
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$stat = $zip->statIndex($index);
			if ($stat === false || str_ends_with((string) $stat['name'], '/') === true) {
				continue;
			}

			if ($this->member(zip: $zip, stat: $stat, prefix: $prefix, depth: $depth, walk: $walk) === false) {
				return false;
			}
		}

		return true;
	}//end walk()

	/**
	 * List one member, and open it when it is a nested zip; false when a bound stopped the walk.
	 *
	 * @param ZipArchive           $zip    The open archive.
	 * @param array<string, mixed> $stat   The member's statIndex() entry.
	 * @param string               $prefix The archive's path prefix.
	 * @param int                  $depth  The archive's depth.
	 * @param ArchiveWalk          $walk   The running totals and the listing.
	 *
	 * @return bool
	 */
	private function member(ZipArchive $zip, array $stat, string $prefix, int $depth, ArchiveWalk $walk): bool {
		$name       = (string) $stat['name'];
		$path       = $prefix.$name;
		$size       = (int) ($stat['size'] ?? 0);
		$compressed = (int) ($stat['comp_size'] ?? 0);

		$walk->members++;
		if ($walk->members > $walk->bounds->maxMembers) {
			$walk->listing->stop(bound: 'maxMembers', member: $path);
			return false;
		}

		$entry = [
			'path'           => $path,
			'size'           => $size,
			'compressedSize' => $compressed,
			'mimeType'       => '',
			'status'         => ArchiveListing::LISTED,
			'depth'          => $depth,
		];
		if (self::leavesTheArchive(name: $name) === true) {
			$walk->listing->add(member: array_merge($entry, ['status' => ArchiveListing::REFUSED]));
			return true;
		}

		$entry['mimeType'] = $this->mimes->detectPath($name);
		if ((int) ($stat['encryption_method'] ?? 0) !== 0) {
			$walk->listing->add(member: array_merge($entry, ['status' => ArchiveListing::ENCRYPTED]));
			return true;
		}

		$bound = $walk->exceeds(size: $size, compressed: $compressed);
		if ($bound !== null) {
			$walk->listing->stop(bound: $bound, member: $path);
			return false;
		}

		$walk->listing->add(member: $entry);
		if ($entry['mimeType'] !== self::ZIP_MIME) {
			return true;
		}

		if ($depth + 1 > $walk->bounds->maxDepth) {
			$walk->listing->stop(bound: 'maxDepth', member: $path);
			return false;
		}

		return $this->nested(zip: $zip, name: $name, path: $path, depth: $depth + 1, walk: $walk);
	}//end member()

	/**
	 * Open a nested zip from a temporary copy and list its members.
	 *
	 * @param ZipArchive  $zip   The outer archive.
	 * @param string      $name  The member's name in the outer archive.
	 * @param string      $path  The member's listed path.
	 * @param int         $depth The nested archive's depth.
	 * @param ArchiveWalk $walk  The running totals and the listing.
	 *
	 * @return bool
	 */
	private function nested(ZipArchive $zip, string $name, string $path, int $depth, ArchiveWalk $walk): bool {
		$copy = $this->temp->getTemporaryFile('.zip');
		if ($copy === false) {
			return true;
		}

		$source = $zip->getStream($name);
		$target = fopen($copy, 'w');
		if ($source === false || $target === false) {
			return true;
		}

		stream_copy_to_stream($source, $target);
		fclose($source);
		fclose($target);

		$inner = new ZipArchive();
		if ($inner->open($copy, ZipArchive::RDONLY) !== true) {
			return true;
		}

		$complete = $this->walk(zip: $inner, prefix: $path.'/', depth: $depth, walk: $walk);
		$inner->close();

		return $complete;
	}//end nested()
}//end class
