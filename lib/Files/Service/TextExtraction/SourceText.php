<?php

/**
 * SourceText: the sanitised text payload of one source and the source's modification time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\TextExtraction
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

namespace OCA\OpenRegister\Files\Service\TextExtraction;

/**
 * The extracted text of a file or object, as chunked and stored, and when the source last changed.
 *
 * @spec openspec/specs/text-extraction/spec.md
 */
final class SourceText {
	/**
	 * Bundle the payload and the timestamp.
	 *
	 * @param array<string, mixed> $payload         The text payload (source_type, source_id, text, owner, organisation, ...).
	 * @param int                  $sourceTimestamp The source's modification time.
	 */
	public function __construct(
		public readonly array $payload,
		public readonly int $sourceTimestamp,
	) {
	}//end __construct()

	/**
	 * The source the chunks of this text belong to, owned as the payload says.
	 *
	 * @return ChunkSource The chunk source.
	 *
	 * @spec openspec/specs/text-extraction/spec.md
	 */
	public function chunkSource(): ChunkSource {
		return new ChunkSource(
			sourceType: $this->payload['source_type'],
			sourceId: $this->payload['source_id'],
			ownership: new ChunkOwnership(
				owner: $this->payload['owner'] ?? null,
				organisation: $this->payload['organisation'] ?? null
			),
			sourceTimestamp: $this->sourceTimestamp
		);
	}//end chunkSource()
}//end class
