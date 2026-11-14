<?php

/**
 * OpenRegister VersionMismatch
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;


use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A stored object and the two versions that disagree about it.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
 */
final class VersionMismatch {
	/**
	 * Hold the object and both versions.
	 *
	 * @param ObjectEntity $stored   The object as stored.
	 * @param string       $expected The version the caller held.
	 * @param string       $current  The version stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-every-write-path-asserts-the-expected-version-req-cso-003
	 */
	public function __construct(
		public readonly ObjectEntity $stored,
		public readonly string $expected,
		public readonly string $current,
	) {
	}//end __construct()
}//end class
