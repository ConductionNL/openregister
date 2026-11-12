<?php

/**
 * FlowDefinitionMapperInterface: what callers outside the Db component use of FlowDefinitionMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * The methods of FlowDefinitionMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to FlowDefinitionMapper. Guarded by C04InterfaceBindingsTest.
 */
interface FlowDefinitionMapperInterface {

	/**
	 * Find a definition by its hash.
	 *
	 * @param string $hash The canonical hash.
	 *
	 * @return FlowDefinition|null The definition, or null when unknown.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function findByHash(string $hash): ?FlowDefinition;

	/**
	 * Store a definition under its hash, or return the one already there.
	 *
	 * 🔑 THE INSERT CAN LOSE A RACE AND THAT IS FINE. Two workers pinning the
	 * same unedited flow at the same moment both miss on the read and both
	 * insert; the unique index rejects one. Because the hash IS the content,
	 * the row the loser then reads back is byte-identical to the one it tried
	 * to write — so re-reading is a complete recovery, not a compromise.
	 *
	 * @param FlowDefinition $definition The canonical hash, definition JSON and originating flow; its created stamp is set here.
	 *
	 * @return FlowDefinition|null The stored definition, or null when it could not be stored.
	 *
	 * @spec openspec/specs/flow-definition-versioning/spec.md
	 */
	public function store(FlowDefinition $definition): ?FlowDefinition;
}//end interface
