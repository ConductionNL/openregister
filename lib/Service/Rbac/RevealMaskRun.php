<?php

/**
 * One masking pass of an object's explicit-reveal properties.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;

/**
 * The schema, the object as masked so far, and the ids it answers to.
 *
 * Built and consumed by ExplicitRevealMasker only; it never leaves that class.
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */
final class RevealMaskRun {
	/**
	 * Start the run.
	 *
	 * @param Schema|null          $schema    The object's schema.
	 * @param array<string, mixed> $object    The object, masked in place as the run goes.
	 * @param array<int, string>   $objectIds The ids the object answers to, completed by the masker.
	 */
	public function __construct(
		public readonly ?Schema $schema,
		public array $object,
		public array $objectIds,
	) {
	}//end __construct()
}//end class
