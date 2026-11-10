<?php

/**
 * OpenRegister ConfigurationScope
 *
 * The lower layers a configuration read is made within: a register, a
 * bundle and a subject, each optional.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

/**
 * The register, bundle and subject a setting is read within.
 *
 * A layer whose reference is null is not part of the read: the instance
 * layer alone answers when none is named.
 *
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */
final class ConfigurationScope {

	/**
	 * Name the scope.
	 *
	 * @param string|null $register The register to read within, when there is one.
	 * @param string|null $bundle   The bundle bound to the subject, when there is one.
	 * @param string|null $subject  The schema, case type or role, when there is one.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function __construct(
		public readonly ?string $register = null,
		public readonly ?string $bundle = null,
		public readonly ?string $subject = null,
	) {
	}//end __construct()
}//end class
