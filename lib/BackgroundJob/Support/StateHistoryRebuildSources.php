<?php

/**
 * OpenRegister state history rebuild sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\History\StateHistoryRebuildInterface;

/**
 * The rebuild, the audit trail it replays and the schemas that name each lifecycle property.
 *
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */
final readonly class StateHistoryRebuildSources {
	/**
	 * Bundle the services.
	 *
	 * @param StateHistoryRebuildInterface $rebuild Rebuilds one object's intervals.
	 * @param AuditTrailMapperInterface    $audit   The audit trail walked.
	 * @param SchemaMapperInterface        $schemas Resolves each object's schema.
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public StateHistoryRebuildInterface $rebuild,
		public AuditTrailMapperInterface $audit,
		public SchemaMapperInterface $schemas,
	) {
	}//end __construct()
}//end class
