<?php

/**
 * OpenRegister WriteCaller
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


/**
 * What a write takes from its caller: the uploaded files and whether RBAC and multitenancy apply.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class WriteCaller {
	/**
	 * Hold the caller's part of a write.
	 *
	 * @param array<mixed> $uploaded The uploaded files.
	 * @param bool         $scoped   True for everyone but an admin.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly array $uploaded,
		public readonly bool $scoped,
	) {
	}//end __construct()

	/**
	 * The uploaded files, or null when there are none.
	 *
	 * @return array<mixed>|null The files.
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function uploads(): ?array {
		if ($this->uploaded === []) {
			return null;
		}

		return $this->uploaded;
	}//end uploads()
}//end class
