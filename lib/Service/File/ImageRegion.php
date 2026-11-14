<?php

/**
 * One region of an image that holds something to redact.
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
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use InvalidArgumentException;

/**
 * A page (null for a standalone image), a box normalised to 0..1, what it
 * holds, how sure the finder was and which finder it was.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
 */
final class ImageRegion {

	/**
	 * The source of a region located through OCR word boxes.
	 */
	public const SOURCE_OCR = 'ocr-text';

	/**
	 * Constructor.
	 *
	 * @param int|null                                      $page       The page, null for a standalone image.
	 * @param array{x: float, y: float, w: float, h: float} $box        The box, each value 0..1.
	 * @param string                                        $entityType What the region holds (PERSON, SIGNATURE, ...).
	 * @param float                                         $confidence How sure the finder was, 0..1.
	 * @param string                                        $source     `ocr-text`, or the detector name and version.
	 * @param int|null                                      $relationId The stored EntityRelation, once stored.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public function __construct(
		public readonly ?int $page,
		public readonly array $box,
		public readonly string $entityType,
		public readonly float $confidence,
		public readonly string $source,
		public readonly ?int $relationId = null,
	) {
	}//end __construct()

	/**
	 * Build a region from a detector's answer, refusing a malformed one.
	 *
	 * @param mixed  $region The detector's region.
	 * @param string $source The detector name and version.
	 *
	 * @return self The region.
	 *
	 * @throws InvalidArgumentException When the region is malformed.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	public static function fromDetector(mixed $region, string $source): self {
		if (is_array($region) === false) {
			throw new InvalidArgumentException('A region must be an object.');
		}

		$page = ($region['page'] ?? null);
		if ($page !== null && (is_int($page) === false || $page < 1)) {
			throw new InvalidArgumentException('A region page must be null or a whole number from 1.');
		}

		$type = ($region['entityType'] ?? null);
		if (is_string($type) === false || trim($type) === '') {
			throw new InvalidArgumentException('A region needs an entityType.');
		}

		$confidence = ($region['confidence'] ?? null);
		if ((is_int($confidence) === false && is_float($confidence) === false) || $confidence < 0 || $confidence > 1) {
			throw new InvalidArgumentException('A region confidence must be a number from 0 to 1.');
		}

		return new self(
			page: $page,
			box: OcrWords::box(value: ($region['box'] ?? null), label: 'region.box'),
			entityType: strtoupper(trim($type)),
			confidence: (float)$confidence,
			source: $source
		);
	}//end fromDetector()

	/**
	 * The same region, now stored as the given relation.
	 *
	 * @param int $relationId The relation id.
	 *
	 * @return self The region.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public function withRelation(int $relationId): self {
		return new self(
			page: $this->page,
			box: $this->box,
			entityType: $this->entityType,
			confidence: $this->confidence,
			source: $this->source,
			relationId: $relationId
		);
	}//end withRelation()

	/**
	 * The region as an array.
	 *
	 * @return array{page: int|null, box: array{x: float, y: float, w: float, h: float}, entityType: string,
	 *     confidence: float, source: string, relationId: int|null} The region.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public function toArray(): array {
		return [
			'page' => $this->page,
			'box' => $this->box,
			'entityType' => $this->entityType,
			'confidence' => $this->confidence,
			'source' => $this->source,
			'relationId' => $this->relationId,
		];
	}//end toArray()
}//end class
