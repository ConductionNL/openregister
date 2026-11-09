<?php

/**
 * One forced re-import of a descriptor file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * One re-import of the non-mock descriptor declaring a slug: what was asked, what the base document declares, and who asked.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
final class DescriptorReimportRun {
	/**
	 * Hold the values.
	 *
	 * @param ShippedRegister    $register The app and slug being re-imported.
	 * @param array<int, string> $declared The slugs the app's base document declares.
	 * @param string             $via      Who asked.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function __construct(
		public readonly ShippedRegister $register,
		public readonly array $declared,
		public readonly string $via,
	) {
	}//end __construct()
}//end class
