<?php

/**
 * FederatedShareMapperInterface: what callers outside the component use of FederatedShareMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * The federated share lookup the federation endpoints use.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface FederatedShareMapperInterface {

	/**
	 * Find a federated share by its scoped share token.
	 *
	 * Used by the federation serving endpoint to resolve an incoming request;
	 * the organisation filter is intentionally NOT applied because the token
	 * itself is the access credential (the caller is a remote instance, not a
	 * local session).
	 *
	 * @param string $shareToken The scoped bearer token.
	 *
	 * @return FederatedShare The share.
	 */
	public function findByToken(string $shareToken): FederatedShare;
}//end interface
