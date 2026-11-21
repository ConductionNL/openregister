<?php

/**
 * Burns regions into the images inside a DOCX or ODT file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use ZipArchive;

/**
 * Replaces the affected media parts of an office file with burned rasters.
 *
 * The images of a DOCX live under `word/media/`, those of an ODT under
 * `Pictures/`. A region's `page` is the 1-based position of the image among
 * those media parts in archive order, so a detector or an OCR provider that
 * walked the same parts addresses the same image. Each affected part is
 * re-encoded in its own format under its own name, so the document's
 * references to it stay valid; every other part is copied unchanged.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
 */
class OfficeMediaBurner {

	/**
	 * The media folder of each office type.
	 */
	public const MEDIA_FOLDERS = [
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word/media/',
		'application/vnd.oasis.opendocument.text' => 'Pictures/',
	];

	/**
	 * The image type of each media extension the burner can re-encode.
	 */
	private const EXTENSIONS = [
		'png' => 'image/png',
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'webp' => 'image/webp',
	];

	/**
	 * Constructor.
	 *
	 * @param ImageBurner $burner The burner of one raster.
	 */
	public function __construct(
		private readonly ImageBurner $burner,
	) {
	}//end __construct()

	/**
	 * Whether a type is an office file whose media this burner handles.
	 *
	 * @param string $mimeType The type.
	 *
	 * @return bool True for DOCX and ODT.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public static function handles(string $mimeType): bool {
		return isset(self::MEDIA_FOLDERS[$mimeType]);
	}//end handles()

	/**
	 * The media parts of an office file, in archive order.
	 *
	 * @param string $bytes    The office file.
	 * @param string $mimeType Its type.
	 * @param string $fileName Its name, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When the file is not a readable archive.
	 *
	 * @return list<string> The part names; position n-1 is page n.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public function mediaParts(string $bytes, string $mimeType, string $fileName): array {
		$zip = $this->open(bytes: $bytes, fileName: $fileName);
		try {
			return $this->partsOf(zip: $zip['archive'], folder: (self::MEDIA_FOLDERS[$mimeType] ?? ''));
		} finally {
			$zip['archive']->close();
			unlink($zip['path']);
		}
	}//end mediaParts()

	/**
	 * Burn the regions into the media parts they address.
	 *
	 * @param string            $bytes    The office file.
	 * @param string            $mimeType Its type (DOCX or ODT).
	 * @param list<ImageRegion> $regions  The decided regions; `page` names the media part.
	 * @param string            $fileName Its name, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When a region addresses no image, or an image that cannot be burned.
	 *
	 * @return string The office file with the affected media replaced.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public function burn(string $bytes, string $mimeType, array $regions, string $fileName): string {
		$folder = (self::MEDIA_FOLDERS[$mimeType] ?? null);
		if ($folder === null) {
			throw new ImageRegionNotBurnableException(fileName: $fileName, reason: sprintf('%s files have no media folder this burner knows.', $mimeType));
		}

		$zip = $this->open(bytes: $bytes, fileName: $fileName);
		try {
			$parts = $this->partsOf(zip: $zip['archive'], folder: $folder);
			foreach ($this->regionsByPart(regions: $regions, parts: $parts, fileName: $fileName) as $part => $partRegions) {
				$type = (self::EXTENSIONS[strtolower(pathinfo($part, PATHINFO_EXTENSION))] ?? null);
				if ($type === null) {
					throw new ImageRegionNotBurnableException(
						fileName: $fileName,
						reason: sprintf('the image %s is in a format that cannot be burned.', $part),
						region: $partRegions[0]
					);
				}

				$burned = $this->burner->burn(
					bytes: (string)$zip['archive']->getFromName($part),
					mimeType: $type,
					regions: $partRegions,
					fileName: $fileName
				);
				$zip['archive']->addFromString($part, $burned);
			}

			$zip['archive']->close();
			return (string)file_get_contents($zip['path']);
		} finally {
			if (is_file($zip['path']) === true) {
				unlink($zip['path']);
			}
		}//end try
	}//end burn()

	/**
	 * Group the regions by the media part they address, as standalone regions.
	 *
	 * @param list<ImageRegion> $regions  The regions.
	 * @param list<string>      $parts    The media parts in archive order.
	 * @param string            $fileName The file name, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When a region addresses no image.
	 *
	 * @return array<string, list<ImageRegion>> The regions per part, each with page null.
	 */
	private function regionsByPart(array $regions, array $parts, string $fileName): array {
		$byPart = [];
		foreach ($regions as $region) {
			$part = ($parts[(($region->page ?? 0) - 1)] ?? null);
			if ($part === null) {
				throw new ImageRegionNotBurnableException(
					fileName: $fileName,
					reason: sprintf('the document has %d images, so the region addresses none of them.', count($parts)),
					region: $region
				);
			}

			$byPart[$part][] = new ImageRegion(
				page: null,
				box: $region->box,
				entityType: $region->entityType,
				confidence: $region->confidence,
				source: $region->source,
				relationId: $region->relationId
			);
		}

		return $byPart;
	}//end regionsByPart()

	/**
	 * The files directly or deeper under a folder of the archive, in archive order.
	 *
	 * @param ZipArchive $zip    The archive.
	 * @param string     $folder The folder, with a trailing slash.
	 *
	 * @return list<string> The part names.
	 */
	private function partsOf(ZipArchive $zip, string $folder): array {
		$parts = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			if (str_starts_with($name, $folder) === true && str_ends_with($name, '/') === false) {
				$parts[] = $name;
			}
		}

		return $parts;
	}//end partsOf()

	/**
	 * Open the bytes as an archive in a temporary file.
	 *
	 * @param string $bytes    The office file.
	 * @param string $fileName Its name, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When the bytes are not a readable archive.
	 *
	 * @return array{archive: ZipArchive, path: string} The open archive and its temporary path.
	 */
	private function open(string $bytes, string $fileName): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'or-media-');
		file_put_contents($path, $bytes);
		$zip = new ZipArchive();
		if ($bytes === '' || $zip->open($path) !== true) {
			unlink($path);
			throw new ImageRegionNotBurnableException(fileName: $fileName, reason: 'the document is corrupt or not a readable archive.');
		}

		return ['archive' => $zip, 'path' => $path];
	}//end open()
}//end class
