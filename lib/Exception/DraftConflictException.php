<?php

/**
 * OpenRegister DraftConflictException
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

use Exception;

/**
 * A draft cannot be promoted because fields it changes were also changed on the published object.
 *
 * Carries one entry per conflicting field: the field, the draft's value and
 * the published value, which the promote route answers as its 409 body.
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */
class DraftConflictException extends Exception {

	/**
	 * Constructor.
	 *
	 * @param array<int, array{field: string, draft: mixed, live: mixed}> $conflicts The conflicting fields.
	 */
	public function __construct(private readonly array $conflicts) {
		parent::__construct(
			message: 'The published object changed the same fields since the draft was made: ' . implode(', ', array_column($conflicts, 'field')) . '.',
			code: 409
		);
	}//end __construct()

	/**
	 * The conflicting fields.
	 *
	 * @return array<int, array{field: string, draft: mixed, live: mixed}>
	 *
	 * @spec openspec/changes/records-draft-versions/design.md
	 */
	public function getConflicts(): array {
		return $this->conflicts;
	}//end getConflicts()
}//end class
