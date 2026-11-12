<?php

/**
 * A request to explain where a caller's answer for each of a set of actions comes from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The schema (and optionally the object) whose rules are explained, the actions asked about and the caller.
 *
 * Built by PermissionHandler::provenanceFor() (ADR-011 Rule 2).
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ProvenanceRequest {

	/**
	 * Constructor for ProvenanceRequest.
	 *
	 * @param Schema            $schema  The schema whose rules are explained.
	 * @param array<int,string> $actions The actions asked about.
	 * @param string|null       $userId  The caller, or null for the session user.
	 * @param ObjectEntity|null $object  The object whose own block overrides the schema's, if any.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly array $actions,
		public readonly ?string $userId,
		public readonly ?ObjectEntity $object,
	) {
	}//end __construct()
}//end class
