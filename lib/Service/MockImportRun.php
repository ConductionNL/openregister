<?php

/**
 * One import of a mock descriptor.
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
 * One import of a mock descriptor under its own configuration identity.
 *
 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
 */
final class MockImportRun {
	/**
	 * Hold the values.
	 *
	 * @param ShippedRegister      $register   The app and slug the mock declares.
	 * @param array<string, mixed> $descriptor The mock descriptor (file, data, registers).
	 * @param string               $configId   The configuration id the mock imports under.
	 *
	 * @spec openspec/changes/reimport-real-register-configuration/specs/register-descriptor-admin/spec.md
	 */
	public function __construct(
		public readonly ShippedRegister $register,
		public readonly array $descriptor,
		public readonly string $configId,
	) {
	}//end __construct()
}//end class
