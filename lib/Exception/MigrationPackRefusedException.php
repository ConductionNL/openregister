<?php

/**
 * OpenRegister MigrationPackRefusedException
 *
 * The common parent of every migration pack refusal the caller caused: a
 * malformed request (400) or a refused pack definition (422).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * A migration pack request refused because of what the caller sent.
 *
 * MigrationPacksController catches this one type and lets
 * MigrationPackErrorResponder pick the status and error code from the
 * concrete subclass: MigrationPackRequestException answers 400, any other
 * refusal 422. Anything that is not a refusal is an unexpected failure and
 * answered with 500.
 *
 * @spec openspec/changes/migration-pack-editor-ui/specs/migration-mapping-packs/spec.md#requirement-pack-changes-must-be-limited-to-administrators-and-logged
 */
abstract class MigrationPackRefusedException extends InvalidArgumentException {
	/**
	 * The reasons for the refusal, one per entry, for `details.errors`.
	 *
	 * @return list<string> Empty when the refusal has no itemised reasons.
	 *
	 * @spec openspec/specs/migration-mapping-packs/spec.md#the-system-must-validate-migration-pack-definitions-structurally-before-storing-them
	 */
	public function getErrors(): array {
		return [];
	}//end getErrors()
}//end class
