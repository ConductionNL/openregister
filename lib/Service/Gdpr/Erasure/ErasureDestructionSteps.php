<?php

/**
 * OpenRegister erasure destruction steps
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
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

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Service\Deletion\DestructionRecorder;
use OCA\OpenRegister\Service\Deletion\DestructionScopeService;

/**
 * The steps an erasure destroy walks through once the guard has let it pass.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
final class ErasureDestructionSteps {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ErasureSchemaResolver   $schemas      Schema resolution for the destruction scope.
	 * @param DestructionScopeService $scopeService What goes with the object.
	 * @param DestructionRecorder     $recorder     The record that outlives the object.
	 * @param MagicMapperInterface    $objectMapper The final delete.
	 */
	public function __construct(
		public readonly ErasureSchemaResolver $schemas,
		public readonly DestructionScopeService $scopeService,
		public readonly DestructionRecorder $recorder,
		public readonly MagicMapperInterface $objectMapper,
	) {
	}//end __construct()
}//end class
