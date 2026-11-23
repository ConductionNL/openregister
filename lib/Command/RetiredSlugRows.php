<?php

/**
 * OpenRegister retired slug rows
 *
 * The schema rows an app owns under one retired slug.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCA\OpenRegister\Db\Schema;

/**
 * A retired slug as the operator named it, and every schema row the app owns
 * under it (none, one, or several when imports left duplicates behind).
 *
 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
 */
final class RetiredSlugRows {
	/**
	 * Describe the slug's rows.
	 *
	 * @param string             $slug    The retired slug as the operator named it.
	 * @param array<int, Schema> $schemas Every row the app owns under the slug.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public function __construct(
		public readonly string $slug,
		public readonly array $schemas,
	) {
	}//end __construct()
}//end class
