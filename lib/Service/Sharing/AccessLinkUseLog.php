<?php

/**
 * Where the uses of an access link are written.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * The audit trail every use is written to, and the log a failed write lands in.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
 */
final readonly class AccessLinkUseLog {

	/**
	 * Bundle the collaborators.
	 *
	 * @param AuditTrailMapperInterface $auditTrail The audit trail, where every use is written.
	 * @param LoggerInterface $logger PSR logger, for an audit write that fails.
	 */
	public function __construct(
		public AuditTrailMapperInterface $auditTrail,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
