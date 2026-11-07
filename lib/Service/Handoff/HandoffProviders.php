<?php

/**
 * OpenRegister Handoff Providers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Handoff
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

namespace OCA\OpenRegister\Service\Handoff;

use OCA\OpenRegister\Service\SemanticTypeResolver;

/**
 * Finds the schema that provides a target kind and maps the source data onto it.
 *
 * @spec openspec/specs/semantic-object-handoff/spec.md#requirement-handoffservice-executes-conversions-on-top-of-semantictyperesolver
 */
final readonly class HandoffProviders {

	/**
	 * Bundle the collaborators.
	 *
	 * @param SemanticTypeResolver $semanticTypeResolver Kind URI → installed schema resolution.
	 * @param HandoffMappingEvaluator $mappingEvaluator The five-expression mapping evaluator.
	 */
	public function __construct(
		public SemanticTypeResolver $semanticTypeResolver,
		public HandoffMappingEvaluator $mappingEvaluator,
	) {
	}//end __construct()
}//end class
