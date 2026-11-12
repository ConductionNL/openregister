<?php

/**
 * ReadPosture
 *
 * The access-control posture a read asks for: RBAC, multitenancy and whether multitenancy was asked for explicitly.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The request posture of one read: whether RBAC filtering is on, whether the
 * organisation filter is on (already resolved against the schema's public
 * read rule where the path resolves it) and whether the caller asked for the
 * organisation filter explicitly (`_multi=true`).
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ReadPosture
{

	/**
	 * Constructor.
	 *
	 * @param bool $rbac                 Whether RBAC filtering is on.
	 * @param bool $multitenancy         Whether the organisation filter is on.
	 * @param bool $multitenancyExplicit Whether the caller asked for it explicitly.
	 */
	public function __construct(
		public readonly bool $rbac,
		public readonly bool $multitenancy,
		public readonly bool $multitenancyExplicit,
	) {
	}//end __construct()
}//end class
