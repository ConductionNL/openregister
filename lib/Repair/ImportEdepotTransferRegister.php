<?php

/**
 * OpenRegister Repair — import the e-Depot transfer system register.
 *
 * OpenRegister does not self-import its own `lib/Settings` register
 * descriptors (ADR-037; the `ImportCredentialBrokerRegister` /
 * `ImportDsarRegisters` precedent). This step imports/upgrades the
 * `edepot-transfers` register (the `edepotTransfer` transfer-list schema +
 * the immutable `edepotTransferProof` schema) so the durable transfer +
 * proof-of-transfer records (archival-transfer-hardening, OR-AD-2/OR-AD-3)
 * have a home. Version-gated (`force: false`); re-runs are no-ops.
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
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;

/**
 * Import the e-Depot transfer + proof-of-transfer register from lib/Settings.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
 *   (Requirement: Durable transfer-list objects served over the API)
 */
class ImportEdepotTransferRegister extends AbstractRegisterImportStep {

	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/edepot_transfer_register.json';

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
	protected const LABEL = 'e-Depot transfer';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'e-Depot transfer register imported (edepotTransfer + edepotTransferProof)';

	/**
	 * Configuration identity for the descriptor (its own appId so the
	 * importFromApp version gate is independent of the other system registers).
	 *
	 * @var string
	 */
	protected const CONFIG_APP_ID = DescriptorConfigIdsInterface::EDEPOT_TRANSFERS;

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-proof-of-transfer/spec.md
	 *   (Requirement: Durable transfer-list objects served over the API)
	 */
	public function getName(): string {
		return 'Import OpenRegister e-Depot transfer register (transfer lists + proof-of-transfer records)';
	}//end getName()
}//end class
