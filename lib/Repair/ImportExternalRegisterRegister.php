<?php

/**
 * ImportExternalRegisterRegister — materialises the example sourced schemas
 * the external-register leaf shows: a BAG address, a BRK parcel and a WOZ value.
 *
 * `external_register_register.json` is a `type: core` descriptor declaring the
 * `external-registers` register and its `bag-adres`, `brk-perceel` and
 * `woz-waarde` schemas, each served by the `openconnector-http` object source.
 * Per ADR-005 Rule 1 shipping the JSON alone does nothing at runtime: without
 * a Repair step the schemas never appear on any instance.
 *
 * Each schema ships with an empty `config.source`, so it is off until an
 * administrator names the OpenConnector source that holds the credentials: a
 * read before then is the explained not-configured state, never a request.
 * Records are read live and never stored, so this import writes no objects.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;

/**
 * Imports the external-registers register (bag-adres, brk-perceel, woz-waarde).
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */
class ImportExternalRegisterRegister extends AbstractRegisterImportStep {

	/**
	 * Path of the descriptor, relative to the app root.
	 */
	protected const REGISTER_PATH = '/lib/Settings/external_register_register.json';

	/**
	 * Version the descriptor is imported under.
	 */
	protected const REGISTER_VERSION = '1.0.0';

	/**
	 * What the step's messages call the descriptor.
	 */
	protected const LABEL = 'External-registers';

	/**
	 * What the step reports after a good import.
	 */
	protected const IMPORTED = 'External-registers register imported (bag-adres, brk-perceel, woz-waarde)';

	/**
	 * The configuration id the descriptor imports under.
	 */
	protected const CONFIG_APP_ID = DescriptorConfigIdsInterface::EXTERNAL_REGISTERS;

	/**
	 * The repair step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
	 */
	public function getName(): string {
		return 'Import OpenRegister external-registers register (BAG, BRK and WOZ sourced schemas)';
	}//end getName()
}//end class
