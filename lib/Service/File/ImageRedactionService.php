<?php

/**
 * The seam every redaction path uses to find and burn regions in images.
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
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use DateTime;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\GdprEntity;
use OCA\OpenRegister\Db\GdprEntityMapperInterface;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use OCA\OpenRegister\Files\Db\AnonymisationLogMapper;
use OCA\OpenRegister\Files\Db\ChunkSourceReaderInterface;
use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCA\OpenRegister\Files\Service\TextExtraction\EntityRecognitionHandler;
use OCP\Files\File;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Finds regions through OCR word boxes and registered detectors, stores each
 * as an EntityRelation with `page` and `box` so it goes through the same
 * decision and review as a text finding, and burns the decided ones.
 *
 * An image nothing could look at is never reported clean: without OCR words
 * the detection says so, and a region that cannot be burned leaves no output.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md
 */
class ImageRedactionService {

	/**
	 * The standalone image types the seam detects.
	 *
	 * @var list<string>
	 */
	public const STANDALONE_TYPES = ['image/png', 'image/jpeg', 'image/tiff', 'image/webp'];

	/**
	 * The message when no OCR words with positions exist for a file.
	 */
	public const NO_OCR = 'No OCR words with positions were provided for this file, so it was not detected. '
		. 'Run OCR and pass its words to extractFromProvidedText() first.';

	/**
	 * Constructor.
	 *
	 * @param ChunkSourceReaderInterface    $chunks    The file's chunks, where the OCR words are kept.
	 * @param EntityRelationMapperInterface $relations The entity relations.
	 * @param GdprEntityMapperInterface     $entities  The entities.
	 * @param ImageRegionDetectorRegistry $detectors The registered detectors.
	 * @param OcrWordLocator              $locator   Finds values among OCR words.
	 * @param ImageBurner                 $burner    Burns regions into pixels.
	 * @param AnonymisationLogMapper|null $runs      Where detection runs are recorded; none records nothing.
	 * @param OfficeMediaBurner|null      $media     Burns regions into the images of DOCX and ODT files.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md
	 */
	public function __construct(
		private readonly ChunkSourceReaderInterface $chunks,
		private readonly EntityRelationMapperInterface $relations,
		private readonly GdprEntityMapperInterface $entities,
		private readonly ImageRegionDetectorRegistry $detectors,
		private readonly OcrWordLocator $locator,
		private readonly ImageBurner $burner,
		private readonly ?AnonymisationLogMapper $runs = null,
		private readonly ?OfficeMediaBurner $media = null,
	) {
	}//end __construct()

	/**
	 * Find the regions of a file and store each as an EntityRelation with page and box.
	 *
	 * @param File $file The file.
	 *
	 * @return ImageDetection The regions, each detector's status, or why the file was not detected.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public function detectImage(File $file): ImageDetection {
		$fileId = (int)$file->getId();
		$ocr = $this->ocrWordsOf(fileId: $fileId);
		$words = $ocr['words'];
		if ($ocr['chunkId'] === null || $words === []) {
			return $this->recordRun(file: $file, detection: ImageDetection::notDetected(reason: self::NO_OCR));
		}

		$regions = $this->textRegions(fileId: $fileId, words: $words);
		$statuses = [];
		$mimeType = (string)$file->getMimeType();
		foreach ($this->detectors->all() as $name => $detector) {
			$run = $this->runDetector(detector: $detector, file: $file, mimeType: $mimeType);
			$statuses[$name] = ['status' => $run['status'], 'reason' => $run['reason']];
			foreach ($run['regions'] as $region) {
				$regions[] = $this->storeDetectorRegion(region: $region, fileId: $fileId, chunkId: $ocr['chunkId']);
			}
		}

		return $this->recordRun(file: $file, detection: new ImageDetection(detected: true, regions: $regions, detectors: $statuses));
	}//end detectImage()

	/**
	 * Record a detection run on the anonymisation log.
	 *
	 * The row's steps carry `image-detection` with every detector's status and
	 * `objectDetection`, so a run where no detector looked at the image reads as
	 * `not-run`, never as clean. A file that could not be detected is a failed run
	 * with the reason.
	 *
	 * @param File           $file      The file that was detected.
	 * @param ImageDetection $detection The outcome.
	 *
	 * @return ImageDetection The same outcome.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-region-detectors-plug-into-the-seam-and-an-absent-one-is-never-read-as-run-req-ais-002
	 */
	private function recordRun(File $file, ImageDetection $detection): ImageDetection {
		if ($this->runs === null) {
			return $detection;
		}

		$outcome = $detection->toArray();
		$step = [
			'outcome' => 'ran',
			'objectDetection' => $outcome['objectDetection'],
			'objectDetectionReason' => $outcome['objectDetectionReason'],
			'detectors' => $outcome['detectors'],
			'regions' => count($outcome['regions']),
		];
		$run = new AnonymisationLog();
		$run->setFileId((int)$file->getId());
		$run->setMimeType((string)$file->getMimeType());
		$run->setEngine('ImageDetection');
		$run->setStatus(AnonymisationLog::STATUS_SUCCESS);
		$run->setReplacements(0);
		if ($detection->detected === false) {
			$step['outcome'] = 'skipped';
			$run->setStatus(AnonymisationLog::STATUS_FAILURE);
			$run->setReason($detection->reason);
		}

		$run->setSteps((string)json_encode(['image-detection' => $step]));
		$run->setCreated(new DateTime());
		$this->runs->insert(entity: $run);

		return $detection;
	}//end recordRun()

	/**
	 * The file's decided regions: every relation with a box not marked skipAnonymization.
	 *
	 * @param File $file The file.
	 *
	 * @return list<ImageRegion> The regions.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public function decidedRegions(File $file): array {
		$regions = [];
		foreach ($this->relations->findByFileId((int)$file->getId()) as $relation) {
			$box = $relation->getBox();
			if (is_array($box) === false || $relation->getSkipAnonymization() === true) {
				continue;
			}

			$regions[] = new ImageRegion(
				page: $relation->getPage(),
				box: OcrWords::box(value: $box, label: 'relation.box'),
				entityType: $this->entityTypeOf(relation: $relation),
				confidence: (float)$relation->getConfidence(),
				source: (string)$relation->getDetectionMethod(),
				relationId: $relation->getId()
			);
		}

		return $regions;
	}//end decidedRegions()

	/**
	 * Burn the regions into the pixels and write the `_anonymized` sibling.
	 *
	 * Nothing is written unless every region was burned. For a DOCX or ODT the
	 * region's `page` is the 1-based position of the image among the document's
	 * media parts, and only those parts are replaced.
	 *
	 * @param File              $file    The image.
	 * @param list<ImageRegion> $regions The decided regions.
	 *
	 * @return File The anonymised sibling.
	 *
	 * @throws ImageRegionNotBurnableException When a region cannot be burned; no output is left behind.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-decided-regions-are-burned-into-the-pixels-req-ais-003
	 */
	public function redactImage(File $file, array $regions): File {
		$mimeType = (string)$file->getMimeType();
		if (OfficeMediaBurner::handles(mimeType: $mimeType) === true) {
			$burned = $this->burnOfficeMedia(bytes: (string)$file->getContent(), mimeType: $mimeType, regions: $regions, fileName: $file->getName());
			return $this->writeSibling(file: $file, bytes: $burned);
		}

		if (in_array($mimeType, self::STANDALONE_TYPES, true) === false) {
			throw new ImageRegionNotBurnableException(
				fileName: $file->getName(),
				reason: sprintf('burning image content inside %s files is not supported yet.', $mimeType)
			);
		}

		$burned = $this->burner->burn(bytes: (string)$file->getContent(), mimeType: $mimeType, regions: $regions, fileName: $file->getName());

		return $this->writeSibling(file: $file, bytes: $burned);
	}//end redactImage()

	/**
	 * Burn the regions into the media of an office file's bytes.
	 *
	 * The text path writes the `_anonymized` DOCX or ODT first; this burns the
	 * decided image regions into that output, so text and images come out of one
	 * call. Nothing is returned unless every region was burned.
	 *
	 * @param string            $bytes    The office file, after its text was replaced.
	 * @param string            $mimeType Its type (DOCX or ODT).
	 * @param list<ImageRegion> $regions  The decided regions; `page` is the image's position among the media parts.
	 * @param string            $fileName The source name, for the refusal.
	 *
	 * @throws ImageRegionNotBurnableException When a region cannot be burned.
	 *
	 * @return string The office file with the affected media replaced.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-every-redaction-path-reaches-the-seam-req-ais-004
	 */
	public function burnOfficeMedia(string $bytes, string $mimeType, array $regions, string $fileName): string {
		$media = ($this->media ?? new OfficeMediaBurner(burner: $this->burner));

		return $media->burn(bytes: $bytes, mimeType: $mimeType, regions: $regions, fileName: $fileName);
	}//end burnOfficeMedia()

	/**
	 * Write the `_anonymized` sibling, overwriting an earlier one.
	 *
	 * @param File   $file  The source file.
	 * @param string $bytes The redacted content.
	 *
	 * @return File The sibling.
	 */
	private function writeSibling(File $file, string $bytes): File {
		$burned = $bytes;

		$parent = $file->getParent();
		$name = $this->anonymizedName(name: $file->getName());
		if ($parent->nodeExists($name) === true) {
			$existing = $parent->get($name);
			if ($existing instanceof File) {
				$existing->putContent($burned);
				return $existing;
			}
		}

		return $parent->newFile($name, $burned);
	}//end writeSibling()

	/**
	 * Map the file's detected text entities to boxes and store page and box on each relation.
	 *
	 * The n-th relation of a value (in text order) takes the n-th place the
	 * value appears among the words.
	 *
	 * @param int                                                                                  $fileId The file.
	 * @param list<array{text: string, page: int|null, box: array{x: float, y: float, w: float, h: float}}> $words  The OCR words.
	 *
	 * @return list<ImageRegion> The located regions.
	 */
	private function textRegions(int $fileId, array $words): array {
		$places = [];
		$seen = [];
		$regions = [];
		foreach ($this->relations->findEntitiesForFile($fileId) as $row) {
			$value = (string)($row['entity_value'] ?? '');
			if (isset($places[$value]) === false) {
				$places[$value] = $this->locator->locate(value: $value, words: $words);
			}

			$nth = ($seen[$value] ?? 0);
			$seen[$value] = ($nth + 1);
			if (isset($places[$value][$nth]) === false) {
				continue;
			}

			$place = $places[$value][$nth];
			$relation = $this->relations->find((int)$row['relation_id']);
			$relation->setPage($place['page']);
			$relation->setBox($place['box']);
			$this->relations->update($relation);

			$regions[] = new ImageRegion(
				page: $place['page'],
				box: $place['box'],
				entityType: (string)($row['entity_type'] ?? ''),
				confidence: (float)($row['confidence'] ?? 0.0),
				source: ImageRegion::SOURCE_OCR,
				relationId: (int)$row['relation_id']
			);
		}//end foreach

		return $regions;
	}//end textRegions()

	/**
	 * Run one detector over the file.
	 *
	 * @param IImageRegionDetector $detector The detector.
	 * @param File                 $file     The file.
	 * @param string               $mimeType Its MIME type.
	 *
	 * @return array{status: string, reason: ?string, regions: list<ImageRegion>} The run.
	 */
	private function runDetector(IImageRegionDetector $detector, File $file, string $mimeType): array {
		if ($detector->isInstalled() === false) {
			return ['status' => ImageDetection::DETECTOR_NOT_INSTALLED, 'reason' => 'not-installed', 'regions' => []];
		}

		if (in_array($mimeType, self::STANDALONE_TYPES, true) === false) {
			return [
				'status' => ImageDetection::DETECTOR_FAILED,
				'reason' => sprintf('page images of %s files are not handed to detectors yet', $mimeType),
				'regions' => [],
			];
		}

		try {
			$answer = $detector->detect(imageBytes: (string)$file->getContent(), page: null);
			$source = $answer['detector']['name'] . ' ' . $answer['detector']['version'];
			$regions = [];
			foreach ($answer['regions'] as $region) {
				$regions[] = ImageRegion::fromDetector(region: $region, source: $source);
			}
		} catch (Throwable $e) {
			return ['status' => ImageDetection::DETECTOR_FAILED, 'reason' => $e->getMessage(), 'regions' => []];
		}

		return ['status' => ImageDetection::DETECTOR_RAN, 'reason' => null, 'regions' => $regions];
	}//end runDetector()

	/**
	 * Store a detector's region as an EntityRelation, once per file.
	 *
	 * @param ImageRegion $region  The region.
	 * @param int         $fileId  The file.
	 * @param int         $chunkId The file's metadata chunk, which holds its OCR words.
	 *
	 * @return ImageRegion The region with its relation.
	 */
	private function storeDetectorRegion(ImageRegion $region, int $fileId, int $chunkId): ImageRegion {
		$entity = $this->regionEntity(region: $region);
		foreach ($this->relations->findByEntityId((int)$entity->getId()) as $existing) {
			if ($existing->getFileId() === $fileId) {
				return $region->withRelation(relationId: (int)$existing->getId());
			}
		}

		$relation = new EntityRelation();
		$relation->setEntityId((int)$entity->getId());
		$relation->setChunkId($chunkId);
		$relation->setFileId($fileId);
		$relation->setPositionStart(0);
		$relation->setPositionEnd(0);
		$relation->setConfidence($region->confidence);
		$relation->setDetectionMethod(mb_substr($region->source, 0, 50));
		$relation->setPage($region->page);
		$relation->setBox($region->box);
		$relation->setCreatedAt(new DateTime());
		$stored = $this->relations->insert($relation);

		return $region->withRelation(relationId: (int)$stored->getId());
	}//end storeDetectorRegion()

	/**
	 * The entity a detector region stands for: its type at its place.
	 *
	 * @param ImageRegion $region The region.
	 *
	 * @return GdprEntity The entity.
	 */
	private function regionEntity(ImageRegion $region): GdprEntity {
		$value = sprintf(
			'%s region p%d %.4F,%.4F,%.4F,%.4F',
			$region->entityType,
			($region->page ?? 1),
			$region->box['x'],
			$region->box['y'],
			$region->box['w'],
			$region->box['h']
		);
		$existing = $this->entities->findOneByValueAndType($value, $region->entityType);
		if ($existing !== null) {
			return $existing;
		}

		$entity = new GdprEntity();
		$entity->setUuid((string)Uuid::v4());
		$entity->setType($region->entityType);
		$entity->setValue($value);
		$entity->setCategory(EntityRecognitionHandler::getCategoryForType(type: $region->entityType));
		$entity->setDetectedAt(new DateTime());
		$entity->setUpdatedAt(new DateTime());

		return $this->entities->insert($entity);
	}//end regionEntity()

	/**
	 * The entity type of a stored relation.
	 *
	 * @param EntityRelation $relation The relation.
	 *
	 * @return string The type, or '' when the entity is gone.
	 */
	private function entityTypeOf(EntityRelation $relation): string {
		try {
			return (string)$this->entities->find((int)$relation->getEntityId())->getType();
		} catch (Throwable $e) {
			return '';
		}
	}//end entityTypeOf()

	/**
	 * The file's metadata chunk id and the OCR words it carries.
	 *
	 * @param int $fileId The file.
	 *
	 * @return array{chunkId: int|null, words: list<array{text: string, page: int|null,
	 *     box: array{x: float, y: float, w: float, h: float}}>} The chunk and words.
	 */
	private function ocrWordsOf(int $fileId): array {
		foreach ($this->chunks->findBySource(sourceType: 'file', sourceId: $fileId) as $chunk) {
			if ($chunk->getChunkIndex() !== -1) {
				continue;
			}

			$words = ($chunk->getPositionReference()['ocrWords'] ?? null);
			if (is_array($words) === false) {
				$words = [];
			}

			return ['chunkId' => (int)$chunk->getId(), 'words' => OcrWords::normalise(words: $words)];
		}

		return ['chunkId' => null, 'words' => []];
	}//end ocrWordsOf()

	/**
	 * `<name>_anonymized.<ext>`, the name the text path writes.
	 *
	 * @param string $name The file name.
	 *
	 * @return string The output name.
	 */
	private function anonymizedName(string $name): string {
		$extension = pathinfo($name, PATHINFO_EXTENSION);
		$out = pathinfo($name, PATHINFO_FILENAME) . '_anonymized';
		if ($extension !== '') {
			$out .= '.' . $extension;
		}

		return $out;
	}//end anonymizedName()
}//end class
