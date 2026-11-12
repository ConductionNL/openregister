<?php

/**
 * OpenRegister flow node run mappers
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

use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The mappers and repositories of the flow node run API.
 *
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
 */
final class FlowNodeRunMappers {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface $schemas Resolves the subject's
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemas,
	) {
	}//end __construct()
}//end class
