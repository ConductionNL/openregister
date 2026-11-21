<?php

/**
 * What one detection run over an image found, and what looked at it.
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
 * A file that nothing could look at is `detected = false` with the reason,
 * never an empty region list that reads as "no findings".
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
 */
final class ImageDetection {

	public const DETECTOR_RAN = 'ran';

	public const DETECTOR_NOT_INSTALLED = 'not-installed';

	public const DETECTOR_FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param bool                                                    $detected  Whether the file could be looked at.
	 * @param list<ImageRegion>                                       $regions   The regions found.
	 * @param array<string, array{status: string, reason: ?string}>   $detectors Per detector: ran, not-installed or failed, with the reason.
	 * @param string|null                                             $reason    Why the file was not detected.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function __construct(
		public readonly bool $detected,
		public readonly array $regions = [],
		public readonly array $detectors = [],
		public readonly ?string $reason = null,
	) {
	}//end __construct()

	/**
	 * A file that could not be looked at.
	 *
	 * @param string $reason Why, naming the missing step.
	 *
	 * @return self The result.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public static function notDetected(string $reason): self {
		return new self(detected: false, reason: $reason);
	}//end notDetected()

	/**
	 * `ran` when at least one detector ran, else `not-run`.
	 *
	 * @return string The object-detection status.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function objectDetection(): string {
		foreach ($this->detectors as $state) {
			if ($state['status'] === self::DETECTOR_RAN) {
				return 'ran';
			}
		}

		return 'not-run';
	}//end objectDetection()

	/**
	 * Why object detection did not run, or null when it did.
	 *
	 * @return string|null The reason: each detector's status, or that none is registered.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function objectDetectionReason(): ?string {
		if ($this->objectDetection() === 'ran') {
			return null;
		}

		if ($this->detectors === []) {
			return 'no detector registered';
		}

		$parts = [];
		foreach ($this->detectors as $name => $state) {
			$parts[] = trim($name . ': ' . $state['status'] . ' ' . (string)($state['reason'] ?? ''));
		}

		return implode('; ', $parts);
	}//end objectDetectionReason()

	/**
	 * The run as an array, for the run record and the API.
	 *
	 * @return array<string, mixed> The run.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public function toArray(): array {
		return [
			'detected' => $this->detected,
			'reason' => $this->reason,
			'regions' => array_map(static fn (ImageRegion $region): array => $region->toArray(), $this->regions),
			'detectors' => $this->detectors,
			'objectDetection' => $this->objectDetection(),
			'objectDetectionReason' => $this->objectDetectionReason(),
		];
	}//end toArray()
}//end class
