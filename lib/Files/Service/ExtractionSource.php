<?php

/**
 * ExtractionSource: the source a text payload is built for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/text-extraction/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

/**
 * The source a text belongs to and how its text was obtained.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final readonly class ExtractionSource {
	/**
	 * Bundle the source facts.
	 *
	 * @param string               $sourceType The source type.
	 * @param int                  $sourceId   The source id.
	 * @param array<string, mixed> $sourceMeta The source metadata (file row).
	 * @param string               $method     How the text was obtained, recorded on the payload.
	 *
	 * @phpstan-param non-empty-string $sourceType
	 * @psalm-param   non-empty-string $sourceType
	 */
	public function __construct(
		public string $sourceType,
		public int $sourceId,
		public array $sourceMeta,
		public string $method,
	) {
	}//end __construct()
}//end class
