<?php

/**
 * The document whose text an external call carries.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * A source type (`file`, `object`, ...) and its id.
 *
 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
 */
final readonly class TextDisclosureSource {
	/**
	 * Name the source.
	 *
	 * @param string $sourceType The source type: `file` for a Nextcloud file.
	 * @param int    $sourceId   The source id: the file id for a file.
	 */
	public function __construct(
		public string $sourceType,
		public int $sourceId,
	) {
	}//end __construct()

	/**
	 * The Nextcloud file id, or null when the source is not a file.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself-pipeline/specs/anonymisation-disclosure/spec.md#requirement-every-external-call-that-carries-a-documents-text-is-recorded-per-document-req-adi-005
	 */
	public function fileId(): ?int {
		if ($this->sourceType === 'file') {
			return $this->sourceId;
		}

		return null;
	}//end fileId()
}//end class
