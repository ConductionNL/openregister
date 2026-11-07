<?php

/**
 * OpenRegister object relations domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Export\ExportGate;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Relation\ObjectRelationServiceInterface;
use OCA\OpenRegister\Service\Relation\RelationGraphServiceInterface;

/**
 * The domain services of the object relations API.
 *
 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
 */
final class ObjectRelationsGraph {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectRelationServiceInterface $relations     The relation row service.
	 * @param RelationGraphServiceInterface  $graphs        The bounded graph walk.
	 * @param ObjectOperationsInterface      $objectService Reads and writes objects, with RBAC.
	 * @param ExportGate                     $exportGate    The export verb, checked before the graph leaves.
	 *
	 * @spec openspec/changes/relation-types-with-inverses/specs/referential-integrity/spec.md
	 */
	public function __construct(
		public readonly ObjectRelationServiceInterface $relations,
		public readonly RelationGraphServiceInterface $graphs,
		public readonly ObjectOperationsInterface $objectService,
		public readonly ExportGate $exportGate,
	) {
	}//end __construct()
}//end class
