<?php

/**
 * GrantableRightsIndexInterface: what callers outside the component use of GrantableRightsIndex.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

/**
 * Declares only what callers outside the component use of GrantableRightsIndex; the rest
 * of the class stays its own.
 */
interface GrantableRightsIndexInterface {

	/**
	 * The rights that may be offered, as a list of
	 * `{register, schema, schemaId, action, source}` entries.
	 *
	 * Rebuilt on a miss. A rebuild failure returns an EMPTY list rather than a
	 * partial one and caches nothing: a half-built permission menu is a wrong
	 * answer that looks like a right one, and the next read gets to try again.
	 *
	 * @return array<int, array<string, mixed>> The grantable rights.
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public function getIndex(): array;

	/**
	 * Drop the index.
	 *
	 * Called from the schema create/update/delete listener. There is no TTL to
	 * fall back on by design: a right removed from a schema must stop being
	 * offered at the moment of the write, not at the end of some window.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/declared-actions/spec.md
	 */
	public function invalidate(): void;
}//end interface
