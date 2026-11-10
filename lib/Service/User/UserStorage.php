<?php

/**
 * OpenRegister UserStorage
 *
 * Where the user service keeps user values, tokens and trails: config, database, audit trail and secure random.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\User
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/profile-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\User;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

/**
 * Where the user service keeps user values, tokens and trails: config, database, audit trail and secure random.
 *
 * @spec openspec/specs/profile-actions/spec.md
 */
final readonly class UserStorage {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IConfig                   $config           The configuration service.
	 * @param IDBConnection             $db               Database connection for direct queries.
	 * @param AuditTrailMapperInterface $auditTrailMapper The audit trail mapper.
	 * @param ISecureRandom             $secureRandom     Secure random generator.
	 */
	public function __construct(
		public IConfig $config,
		public IDBConnection $db,
		public AuditTrailMapperInterface $auditTrailMapper,
		public ISecureRandom $secureRandom,
	) {
	}//end __construct()
}//end class
