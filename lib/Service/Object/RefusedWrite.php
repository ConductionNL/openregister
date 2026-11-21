<?php

/**
 * OpenRegister refused write
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * A write refused because the object changed since the caller read it.
 *
 * @spec openspec/specs/objects-crud/spec.md#requirement-a-refused-write-names-the-values-that-conflict-req-cso-001
 */
final class RefusedWrite {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $stored The object as it is now.
	 * @param Schema               $schema Its schema, for the field filter.
	 * @param array<string, mixed> $sent   What the caller is trying to write.
	 */
	public function __construct(
		public readonly ObjectEntity $stored,
		public readonly Schema $schema,
		public readonly array $sent,
	) {
	}//end __construct()
}//end class
