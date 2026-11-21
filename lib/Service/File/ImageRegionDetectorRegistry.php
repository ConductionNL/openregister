<?php

/**
 * The region detectors registered on the image seam.
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
 * Holds the detectors by name; anonymiq's adapter is always registered, so
 * its absence is reported as `not-installed` rather than left unmentioned.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
 */
class ImageRegionDetectorRegistry {

	/**
	 * The detectors by name.
	 *
	 * @var array<string, IImageRegionDetector>
	 */
	private array $detectors = [];

	/**
	 * Constructor.
	 *
	 * @param AnonymiqRegionDetector $anonymiq The anonymiq adapter.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function __construct(AnonymiqRegionDetector $anonymiq) {
		$this->register(detector: $anonymiq);
	}//end __construct()

	/**
	 * Register a detector; a later one with the same name replaces the earlier.
	 *
	 * @param IImageRegionDetector $detector The detector.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function register(IImageRegionDetector $detector): void {
		$this->detectors[$detector->name()] = $detector;
	}//end register()

	/**
	 * The registered detectors, by name.
	 *
	 * @return array<string, IImageRegionDetector> The detectors.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function all(): array {
		return $this->detectors;
	}//end all()
}//end class
