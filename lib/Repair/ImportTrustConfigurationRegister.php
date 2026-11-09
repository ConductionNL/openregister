<?php

/**
 * OpenRegister Repair — import the MDM trust-configuration register.
 *
 * OpenRegister does not self-import its own `lib/Settings` register
 * descriptors (ADR-037; the `ImportCredentialBrokerRegister` /
 * `ImportDsarRegister` / `ImportEdepotTransferRegister` precedent). This step
 * imports/upgrades the `trust-configuration` register (the `trustConfiguration`
 * schema) that the survivorship engine reads: `TrustTierResolver` resolves a
 * `(entityType, attribute, sourceSystem)` tuple to the tier that decides which
 * upstream source wins for a golden-record attribute.
 *
 * Without this step the register never exists, so every consumer degrades:
 * `SurvivorshipRecomputeListener::loadTrustRows()` swallows the lookup failure
 * and returns `[]` — silently falling back to each annotation's `defaultTier` —
 * while a leaf app seeding its own rows (pipelinq's `SeedTrustConfigurationRows`)
 * fails loudly with "Did expect one result but found none". `trust_configuration_register.json`
 * was the only descriptor in `lib/Settings/` shipped without an importer.
 * Version-gated (`force: false`); re-runs are no-ops.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
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

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;

/**
 * Import the MDM trust-configuration register from lib/Settings.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 */
class ImportTrustConfigurationRegister extends AbstractRegisterImportStep {

	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/trust_configuration_register.json';

	/**
	 * Descriptor version passed to the importer's version_compare gate.
	 *
	 * @var string
	 */
	protected const REGISTER_VERSION = '1.0.0';

	/**
	 * Name of the register in the step's output lines.
	 *
	 * @var string
	 */
	protected const LABEL = 'Trust-configuration';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'MDM trust-configuration register imported (trustConfiguration)';

	/**
	 * Configuration identity for the descriptor (its own appId so the
	 * importFromApp version gate is independent of the other system registers).
	 *
	 * @var string
	 */
	protected const CONFIG_APP_ID = DescriptorConfigIdsInterface::TRUST_CONFIGURATION;

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 */
	public function getName(): string {
		return 'Import OpenRegister MDM trust-configuration register (source trust tiers for survivorship)';
	}//end getName()
}//end class
