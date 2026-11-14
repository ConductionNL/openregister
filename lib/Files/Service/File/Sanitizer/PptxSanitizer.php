<?php

/**
 * OpenRegister PPTX sanitiser
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File\Sanitizer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File\Sanitizer;

use DOMElement;
use OCA\OpenRegister\Files\Service\File\SanitizationHiddenCounts;
use OCA\OpenRegister\Files\Service\File\SanitizationReport;
use OCA\OpenRegister\Files\Service\File\SanitizationRevisionCounts;
use OCA\OpenRegister\Files\Service\File\SanitizationStructureCounts;

/**
 * Takes out of a presentation what an audience of the shown slides does not see.
 *
 * Hidden slides are removed with the parts only they used. Speaker notes,
 * comments (classic and modern) and the comment author lists are removed,
 * as are custom XML parts, and the package metadata is scrubbed.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
 */
final class PptxSanitizer implements SanitizerInterface {

	/**
	 * The PPTX MIME type.
	 */
	private const MIME = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

	/**
	 * PresentationML main namespace.
	 */
	private const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

	/**
	 * The presentation part.
	 */
	private const PRESENTATION = 'ppt/presentation.xml';

	/**
	 * The shared package steps, built with this sanitiser's sentinel.
	 *
	 * @var OoxmlCommonParts
	 */
	private readonly OoxmlCommonParts $common;

	/**
	 * Constructor.
	 *
	 * @param string $sentinel The sentinel applied to scrubbed metadata.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function __construct(
		private readonly string $sentinel = 'DocuDesk Anonymisation',
	) {
		$this->common = new OoxmlCommonParts(surgery: new OfficePartSurgery(sentinel: $sentinel));
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $mimeType The file MIME type.
	 *
	 * @return bool True for the PPTX MIME type.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function supports(string $mimeType): bool {
		return $mimeType === self::MIME;
	}//end supports()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $sourcePath Path to the source .pptx.
	 * @param string $destPath   Path to write the sanitised .pptx.
	 *
	 * @return SanitizationReport Per-category counts.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-spreadsheets-and-slides-are-sanitised-like-documents-req-rrs-003
	 */
	public function sanitize(string $sourcePath, string $destPath): SanitizationReport {
		$package = OoxmlPackage::read(path: $sourcePath);

		$hiddenSlides = $this->removeHiddenSlides(package: $package);
		$notes        = 0;
		$comments     = 0;
		foreach ($this->slideParts(package: $package) as $slide) {
			$notes    += $this->removeSlideParts(package: $package, slide: $slide, types: ['notesSlide']);
			$comments += $this->countComments(package: $package, slide: $slide);
			$this->removeSlideParts(package: $package, slide: $slide, types: ['comments']);
		}

		$this->removePresentationParts(package: $package, types: ['commentAuthors', 'authors']);
		$customXml = $this->common->removeCustomXml(package: $package);
		$metadata  = $this->common->scrubMetadata(package: $package);

		$package->write(path: $destPath);

		return new SanitizationReport(
			revisions: new SanitizationRevisionCounts(commentsRemoved: $comments),
			structure: new SanitizationStructureCounts(metadataFieldsScrubbed: $metadata, customXmlPartsDropped: $customXml),
			sentinelApplied: $this->sentinel,
			hidden: new SanitizationHiddenCounts(hiddenSlidesRemoved: $hiddenSlides, speakerNotesRemoved: $notes)
		);
	}//end sanitize()

	/**
	 * Remove every slide marked `show="0"`, with the parts only it used.
	 *
	 * @param OoxmlPackage $package The package.
	 *
	 * @return int The number of slides removed.
	 */
	private function removeHiddenSlides(OoxmlPackage $package): int {
		$presentation = $package->dom(name: self::PRESENTATION);
		if ($presentation === null) {
			return 0;
		}

		$targets = [];
		foreach ($package->relationships(part: self::PRESENTATION) as $relationship) {
			$targets[$relationship['id']] = $relationship['target'];
		}

		$removed = 0;
		foreach (iterator_to_array($presentation->getElementsByTagNameNS(self::NS_P, 'sldId')) as $entry) {
			assert($entry instanceof DOMElement);
			$id    = $entry->getAttributeNS(OoxmlPackage::NS_R, 'id');
			$slide = ($targets[$id] ?? null);
			if ($slide === null || $this->isHidden(package: $package, slide: $slide) === false) {
				continue;
			}

			$entry->parentNode?->removeChild($entry);
			$package->removeRelationships(part: self::PRESENTATION, ids: [$id]);
			$package->removeTree(name: $slide);
			$removed++;
		}

		if ($removed > 0) {
			$package->putDom(name: self::PRESENTATION, dom: $presentation);
		}

		return $removed;
	}//end removeHiddenSlides()

	/**
	 * Whether a slide is hidden from the show.
	 *
	 * @param OoxmlPackage $package The package.
	 * @param string       $slide   The slide part.
	 *
	 * @return bool
	 */
	private function isHidden(OoxmlPackage $package, string $slide): bool {
		$root = $package->dom(name: $slide)?->documentElement;
		if ($root === null) {
			return false;
		}

		return in_array(strtolower($root->getAttribute('show')), ['0', 'false'], true);
	}//end isHidden()

	/**
	 * The slide parts the presentation still lists.
	 *
	 * @param OoxmlPackage $package The package.
	 *
	 * @return array<int, string>
	 */
	private function slideParts(OoxmlPackage $package): array {
		$slides = [];
		foreach ($package->relationships(part: self::PRESENTATION) as $relationship) {
			$target = $relationship['target'];
			if (str_ends_with($relationship['type'], '/slide') === true && $target !== null && $package->has(name: $target) === true) {
				$slides[] = $target;
			}
		}

		return $slides;
	}//end slideParts()

	/**
	 * The comments on a slide, classic (`p:cm`) and modern (`p188:cm`) alike.
	 *
	 * @param OoxmlPackage $package The package.
	 * @param string       $slide   The slide part.
	 *
	 * @return int
	 */
	private function countComments(OoxmlPackage $package, string $slide): int {
		$count = 0;
		foreach ($package->relationships(part: $slide) as $relationship) {
			if (basename($relationship['type']) === 'comments' && $relationship['target'] !== null) {
				$count += ($package->dom(name: $relationship['target'])?->getElementsByTagNameNS('*', 'cm')->length ?? 0);
			}
		}

		return $count;
	}//end countComments()

	/**
	 * Remove a slide's related parts of the given relationship types.
	 *
	 * @param OoxmlPackage       $package The package.
	 * @param string             $slide   The slide part.
	 * @param array<int, string> $types   Relationship types, by their last path segment.
	 *
	 * @return int The number of parts removed.
	 */
	private function removeSlideParts(OoxmlPackage $package, string $slide, array $types): int {
		return $this->removeRelated(package: $package, part: $slide, types: $types);
	}//end removeSlideParts()

	/**
	 * Remove the presentation's related parts of the given relationship types.
	 *
	 * @param OoxmlPackage       $package The package.
	 * @param array<int, string> $types   Relationship types, by their last path segment.
	 *
	 * @return int The number of parts removed.
	 */
	private function removePresentationParts(OoxmlPackage $package, array $types): int {
		return $this->removeRelated(package: $package, part: self::PRESENTATION, types: $types);
	}//end removePresentationParts()

	/**
	 * Remove the parts a part points to with the given relationship types, and the relationships.
	 *
	 * @param OoxmlPackage       $package The package.
	 * @param string             $part    The source part.
	 * @param array<int, string> $types   Relationship types, by their last path segment.
	 *
	 * @return int The number of parts removed.
	 */
	private function removeRelated(OoxmlPackage $package, string $part, array $types): int {
		$ids     = [];
		$targets = [];
		foreach ($package->relationships(part: $part) as $relationship) {
			if (in_array(basename($relationship['type']), $types, true) === true) {
				$ids[] = $relationship['id'];
				if ($relationship['target'] !== null) {
					$targets[] = $relationship['target'];
				}
			}
		}

		$package->removeRelationships(part: $part, ids: $ids);
		$removed = 0;
		foreach (array_unique($targets) as $target) {
			if ($package->has(name: $target) === true && $package->isReferenced(name: $target) === false) {
				$package->removeTree(name: $target);
				$removed++;
			}
		}

		return $removed;
	}//end removeRelated()
}//end class
