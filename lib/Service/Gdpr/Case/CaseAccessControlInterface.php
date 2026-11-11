<?php

/**
 * CaseAccessControlInterface: what callers outside the Service component use of CaseAccessControl.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Case;

/**
 * Decides whether the caller may act on a DSAR case.
 */
interface CaseAccessControlInterface {

	/**
	 * Whether the current caller may act on the given case.
	 *
	 * Object RBAC is assumed already satisfied by ObjectService on the load;
	 * this narrows to: caller IS the case handler, OR caller holds the
	 * configured officer role. Any inability to resolve the caller or the
	 * officer role denies.
	 *
	 * @param array<string, mixed> $case The loaded case payload (must carry `handler`).
	 *
	 * @return bool True when the caller may act on the case; false (fail-closed) otherwise.
	 *
	 * @spec openspec/specs/dsar-case-api/spec.md
	 */
	public function mayAct(array $case): bool;
}//end interface
