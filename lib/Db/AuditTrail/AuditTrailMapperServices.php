<?php

/**
 * AuditTrailMapperServices: the platform services of the audit trail mapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\AuditTrail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The container the mapper resolves its lazy collaborators from, its logger, and the
 * session and request an audit entry reads its actor and request context from.
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */
final readonly class AuditTrailMapperServices {
	/**
	 * Bundle the services.
	 *
	 * @param ContainerInterface $container   DI container for lazy collaborator resolution.
	 * @param LoggerInterface    $logger      Logger.
	 * @param IUserSession       $userSession User session service.
	 * @param IRequest           $request     Current request.
	 */
	public function __construct(
		public ContainerInterface $container,
		public LoggerInterface $logger,
		public IUserSession $userSession,
		public IRequest $request,
	) {
	}//end __construct()
}//end class
