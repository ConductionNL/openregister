<?php

/**
 * ShareGrantorInterface: what the occ federation share-rights command asks of ShareGrantor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Federation
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

namespace OCA\OpenRegister\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;

/**
 * Whether the caller may grant a federated share, as the occ share-rights
 * command asks it. Only the method it calls; the check itself stays in
 * ShareGrantor.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */
interface ShareGrantorInterface {

	/**
	 * Why the caller may not grant this share, or null when they hold every right it hands out.
	 *
	 * @param FederatedShare $share The share to grant, or one already granted.
	 *
	 * @return string|null The refusal, naming the right that is missing.
	 *
	 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
	 */
	public function refusal(FederatedShare $share): ?string;
}//end interface
