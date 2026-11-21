<?php

/**
 * OpenRegister GGM upload
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SchemaImport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\SchemaImport;

/**
 * An uploaded, normalised GGM intermediate and the object type to import from it.
 *
 * @spec openspec/specs/schema-import/spec.md
 */
final class GgmUpload {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $normalised  The normalised GGM intermediate.
	 * @param string               $reference   The objecttype id/name to import.
	 * @param string               $sourceLabel A provenance label for the upload.
	 */
	public function __construct(
		public readonly array $normalised,
		public readonly string $reference,
		public readonly string $sourceLabel = 'upload',
	) {
	}//end __construct()
}//end class
