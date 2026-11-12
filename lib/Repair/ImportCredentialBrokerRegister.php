<?php

/**
 * ImportCredentialBrokerRegister — materialises the credential-broker register.
 *
 * OpenRegister does not self-import its own register JSON at boot (ADR-037). This
 * repair step imports `lib/Settings/credential_broker_register.json` via
 * `ConfigurationService::importFromFilePath()` so the `credential` schema and its
 * two secret-less example objects land idempotently on `occ upgrade` (matched by
 * slug, `version_compare`-gated) — mirroring the register-import-via-Repair
 * convention of {@see SeedAppVirtualSchemas}. The provider catalogue is NOT imported
 * here: it is the runtime-immutable `lib/Settings/credential-providers.json` file
 * read directly by the broker (design.md D2). Never throws — a failure logs a warning
 * and leaves the instance otherwise healthy.
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
 * @spec openspec/specs/credential-broker/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

/**
 * Imports the credential-broker register descriptor idempotently on upgrade/install.
 */
class ImportCredentialBrokerRegister extends AbstractRegisterImportStep {
	/**
	 * App-relative path to the register descriptor imported by this step.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/credential_broker_register.json';

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
	protected const LABEL = 'Credential-broker';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'Credential-broker register imported (credential schema + example objects)';

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/credential-broker/spec.md
	 */
	public function getName(): string {
		return 'Import OpenRegister credential-broker register (credential schema + example objects)';
	}//end getName()
}//end class
