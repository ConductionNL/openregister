<?php

/**
 * ProcessingCatalogueSeedInterface: writes an app's declared processing activities to the record of processing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * What a configuration import calls to seed the activities its document
 * declares, so the Configuration area reaches the Compliance seeder without
 * naming it (ADR-013 Rule 1). Implemented by Service\Gdpr\ProcessingCatalogueSeeder.
 *
 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-apps-must-contribute-processing-activities-and-attribution-declaratively-via-x-openregister-processing-or-pa-2
 */
interface ProcessingCatalogueSeedInterface {

	/**
	 * Seed the activities a configuration document declares: new codes as concepts, existing codes untouched.
	 *
	 * @param array<string, mixed> $document The decoded configuration document.
	 *
	 * @return array{created: list<string>, kept: list<string>, refused: array<string, string>} Codes per outcome.
	 *
	 * @spec openspec/specs/avg-verwerkingsregister/spec.md#requirement-apps-must-contribute-processing-activities-and-attribution-declaratively-via-x-openregister-processing-or-pa-2
	 */
	public function seed(array $document): array;
}//end interface
