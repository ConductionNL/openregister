<?php

/**
 * Vector Subject
 *
 * The entity one vector row embeds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

/**
 * The entity type and UUID a stored vector belongs to.
 *
 * @spec openspec/specs/vector-embeddings/spec.md
 */
final class VectorSubject {
	/**
	 * Constructor.
	 *
	 * @param string $entityType Entity type (object or file).
	 * @param string $entityId   Entity UUID.
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function __construct(
		public readonly string $entityType,
		public readonly string $entityId,
	) {
	}//end __construct()
}//end class
