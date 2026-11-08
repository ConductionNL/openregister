<?php

/**
 * The services a schema-derived MCP tool reads, writes and audits objects through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;

/**
 * A dependency bundle of {@see SchemaDerivedToolProvider}.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class SchemaDerivedToolServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectOperationsInterface $objectService    RBAC-enforcing object read/write facade.
	 * @param AuditTrailMapperInterface $auditTrailMapper Immutable hash-chained audit-trail writer.
	 * @param LoggerInterface           $logger           PSR logger.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objectService,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
