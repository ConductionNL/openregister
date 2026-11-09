<?php

/**
 * One reading of the caller's written rule for a verb, during an access review.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * The permission handler, the schema, the verb and the record a written rule
 * is read for. Built and consumed by AccessReviewGate only; it never leaves
 * that class.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
final class WrittenRuleRun {
	/**
	 * Set the fields.
	 *
	 * @param PermissionHandlerInterface $handler The permission handler.
	 * @param Schema                     $schema  The schema.
	 * @param string                     $action  The verb.
	 * @param ObjectEntity|null          $object  The record, or null for a schema-level question.
	 */
	public function __construct(
		public readonly PermissionHandlerInterface $handler,
		public readonly Schema $schema,
		public readonly string $action,
		public readonly ?ObjectEntity $object,
	) {
	}//end __construct()
}//end class
