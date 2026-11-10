<?php

/**
 * VectorSidecarTableInterface: the pgvector sidecar table name callers outside Search read.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

/**
 * The name of the unprefixed pgvector ANN sidecar table, for callers outside Search.
 *
 * PgVectorPlatform implements this, so PgVectorPlatform::SIDECAR_TABLE still resolves
 * to the same value; callers in other components read it from here.
 */
interface VectorSidecarTableInterface {

	/**
	 * Unprefixed pgvector ANN sidecar table name.
	 *
	 * Must never carry the Nextcloud table prefix: staying outside the
	 * `/^<prefix>/` migration filter is what keeps the vector-typed column
	 * invisible to Doctrine schema introspection.
	 */
	public const SIDECAR_TABLE = 'openregister_vec_ann';
}//end interface
