<?php

/**
 * The endpoint log and agent mappers of the endpoint service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\EndpointLogMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;

/**
 * The endpoint log and agent mappers of the endpoint service.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class EndpointMappers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param EndpointLogMapperInterface $endpointLogMapper Endpoint call log mapper.
	 * @param AgentMapperInterface       $agentMapper       Agent mapper.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function __construct(
		public readonly EndpointLogMapperInterface $endpointLogMapper,
		public readonly AgentMapperInterface $agentMapper,
	) {
	}//end __construct()
}//end class
