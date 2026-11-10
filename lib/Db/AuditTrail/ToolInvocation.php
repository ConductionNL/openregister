<?php

/**
 * OpenRegister ToolInvocation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db\AuditTrail
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

namespace OCA\OpenRegister\Db\AuditTrail;

/**
 * One MCP tool invocation, as the audit trail records it.
 *
 * The operation object of AuditTrailMapper::recordToolInvocation().
 */
final class ToolInvocation {

	/**
	 * Constructor.
	 *
	 * @param string        $toolId        Full namespaced tool id, e.g. `pipelinq.lead.search`.
	 * @param string        $paramsDigest  SHA-256 hex digest of the invocation's canonical arguments (never raw values).
	 * @param array         $resultSummary Structured outcome summary (count/ids/isError/errorClass).
	 * @param AuditedObject $about         The register, schema and object the invocation touched, each when known.
	 */
	public function __construct(
		public readonly string $toolId,
		public readonly string $paramsDigest,
		public readonly array $resultSummary,
		public readonly AuditedObject $about = new AuditedObject(),
	) {
	}//end __construct()
}//end class
