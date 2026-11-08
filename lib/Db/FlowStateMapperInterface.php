<?php

/**
 * FlowStateMapperInterface: what callers outside the component use of FlowStateMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * The flow state lookup the flow endpoints use.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FlowStateMapperInterface {

	/**
	 * The state of one flow, or null when it has none yet.
	 *
	 * @param string $flowId The flow's uuid.
	 *
	 * @return FlowState|null The stored state, or null.
	 */
	public function findByFlow(string $flowId): ?FlowState;
}//end interface
