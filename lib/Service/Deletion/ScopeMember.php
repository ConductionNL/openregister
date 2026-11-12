<?php

/**
 * ScopeMember — one declared destruction-scope member and the count its preview promised.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

/**
 * A declared scope member (one of the {@see DestructionScope} member names) and the
 * number of rows the preview counted for it.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
final class ScopeMember {

	/**
	 * Constructor.
	 *
	 * @param string $name     The declared member.
	 * @param int    $expected The count the preview promised.
	 */
	public function __construct(
		public readonly string $name,
		public readonly int $expected,
	) {
	}//end __construct()
}//end class
