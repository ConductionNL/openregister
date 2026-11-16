<?php

/**
 * PdfSanitizationReport
 *
 * What a PDF sanitisation removed, as counts, and which PDF/A-3 archival
 * attachments it kept, by name and relationship. Never removed content.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Files\Service\File
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use JsonSerializable;

/**
 * Counts of one PDF sanitisation.
 */
final class PdfSanitizationReport implements JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param int                                                  $metadataFieldsScrubbed   /Info identity fields replaced.
	 * @param bool                                                 $xmpScrubbed              Whether XMP identity namespaces were stripped.
	 * @param int                                                  $annotationsRemoved       Annotations removed (form field widgets stay).
	 * @param int                                                  $embeddedFilesRemoved     Embedded files removed.
	 * @param int                                                  $javascriptRemoved        JavaScript entries and actions removed.
	 * @param int                                                  $openActionsRemoved       /OpenAction entries removed.
	 * @param int                                                  $additionalActionsRemoved /AA entries removed.
	 * @param list<array{name: string, relationship: string}>      $preservedAttachments     PDF/A-3 archival attachments kept.
	 */
	public function __construct(
		public readonly int $metadataFieldsScrubbed = 0,
		public readonly bool $xmpScrubbed = false,
		public readonly int $annotationsRemoved = 0,
		public readonly int $embeddedFilesRemoved = 0,
		public readonly int $javascriptRemoved = 0,
		public readonly int $openActionsRemoved = 0,
		public readonly int $additionalActionsRemoved = 0,
		public readonly array $preservedAttachments = [],
	) {
	}//end __construct()

	/**
	 * The report as plain data.
	 *
	 * @return array<string, mixed> The counts and the preserved attachments.
	 */
	public function jsonSerialize(): array {
		return [
			'metadataFieldsScrubbed' => $this->metadataFieldsScrubbed,
			'xmpScrubbed' => $this->xmpScrubbed,
			'annotationsRemoved' => $this->annotationsRemoved,
			'embeddedFilesRemoved' => $this->embeddedFilesRemoved,
			'javascriptRemoved' => $this->javascriptRemoved,
			'openActionsRemoved' => $this->openActionsRemoved,
			'additionalActionsRemoved' => $this->additionalActionsRemoved,
			'preservedAttachments' => $this->preservedAttachments,
		];

	}//end jsonSerialize()
}//end class
