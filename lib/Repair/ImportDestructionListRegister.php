<?php

/**
 * OpenRegister Repair — import the destruction-list system register.
 *
 * OpenRegister does not self-import its own `lib/Settings` register
 * descriptors (ADR-037; the e-Depot transfer register precedent). This
 * step imports/upgrades the `destruction-lists` register (the
 * `destructionList` schema), so the destruction sweep and the review
 * endpoints have a register an administrator, or an example set, can name in
 * the `archival` setting. Version-gated (`force: false`); re-runs are no-ops.
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
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Configuration\DescriptorConfigIdsInterface;

/**
 * Import the destruction-list register from lib/Settings.
 *
 * @psalm-suppress UnusedClass Instantiated by the NC repair framework (appinfo/info.xml).
 *
 * @spec openspec/specs/retention-management/spec.md
 *   (Requirement: OpenRegister ships a register for destruction lists)
 */
class ImportDestructionListRegister extends AbstractRegisterImportStep {

	/**
	 * App-relative descriptor path.
	 *
	 * @var string
	 */
	protected const REGISTER_PATH = '/lib/Settings/destruction_list_register.json';

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
	protected const LABEL = 'destruction-list';

	/**
	 * Output line after a successful import.
	 *
	 * @var string
	 */
	protected const IMPORTED = 'destruction-list register imported (destructionList)';

	/**
	 * Configuration identity for the descriptor (its own appId so the
	 * importFromApp version gate is independent of the other system registers).
	 *
	 * @var string
	 */
	protected const CONFIG_APP_ID = DescriptorConfigIdsInterface::DESTRUCTION_LISTS;

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 *   (Requirement: OpenRegister ships a register for destruction lists)
	 */
	public function getName(): string {
		return 'Import OpenRegister destruction-list register';
	}//end getName()
}//end class
