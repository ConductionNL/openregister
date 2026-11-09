<?php

/**
 * OpenRegister render derivations
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Service\UrnService;

/**
 * What a render derives values and links with.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RenderDerivations {
	/**
	 * Bundle the services.
	 *
	 * @param ComputedFieldHandler               $computedFieldHandler Evaluates computed fields.
	 * @param CalculationEvaluatorInterface      $calculationEvaluator Evaluates derived properties.
	 * @param LinkedEntityEnricher               $linkedEntityEnricher Enriches linked entity metadata.
	 * @param UrnService                         $urnService           Resolves register, schema and object URNs.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly ComputedFieldHandler $computedFieldHandler,
		public readonly CalculationEvaluatorInterface $calculationEvaluator,
		public readonly LinkedEntityEnricher $linkedEntityEnricher,
		public readonly UrnService $urnService,
	) {
	}//end __construct()
}//end class
