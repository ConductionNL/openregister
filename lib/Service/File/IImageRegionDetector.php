<?php

/**
 * The seam a region detector implements.
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
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * A detector finds regions (signatures, faces, plates, ...) in image bytes.
 *
 * A detector that is not installed answers false from isInstalled() and is
 * recorded as `not-installed`, never as run.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
 */
interface IImageRegionDetector {

	/**
	 * The detector's name, the key of its run status.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function name(): string;

	/**
	 * Whether the detector can run on this instance.
	 *
	 * @return bool True when it is installed and enabled.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function isInstalled(): bool;

	/**
	 * Find regions in one image.
	 *
	 * @param string   $imageBytes The image.
	 * @param int|null $page       The page it is, null for a standalone image.
	 *
	 * @return array{regions: list<array<string, mixed>>, detector: array{name: string, version: string}} The regions and who found them.
	 *
	 * @throws \RuntimeException When the detector failed or answered malformed.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function detect(string $imageBytes, ?int $page): array;
}//end interface
