<?php

/**
 * OpenRegister ChunkFieldTypes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Files\Db\Chunk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db\Chunk;

/**
 * The column types of a text chunk, in registration order.
 *
 * @spec exclude text chunk plumbing moved out of the entity, not a capability of its own
 */
final class ChunkFieldTypes {

	/**
	 * Field name to Nextcloud entity type.
	 *
	 * @var array<string, string>
	 */
	public const TYPES = [
		'uuid' => 'string',
		'sourceType' => 'string',
		'sourceId' => 'integer',
		'textContent' => 'string',
		'startOffset' => 'integer',
		'endOffset' => 'integer',
		'chunkIndex' => 'integer',
		'positionReference' => 'json',
		'language' => 'string',
		'languageLevel' => 'string',
		'languageConfidence' => 'float',
		'detectionMethod' => 'string',
		'indexed' => 'boolean',
		'vectorized' => 'boolean',
		'embeddingProvider' => 'string',
		'overlapSize' => 'integer',
		'owner' => 'string',
		'organisation' => 'string',
		'checksum' => 'string',
		'createdAt' => 'datetime',
		'updatedAt' => 'datetime',
	];
}//end class
