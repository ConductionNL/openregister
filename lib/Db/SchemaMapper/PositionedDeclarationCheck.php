<?php

/**
 * PositionedDeclarationCheck: a schema declaration check given as a position and a callable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use Closure;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\Core\SchemaDeclarationCheckInterface;

/**
 * Runs `$check($schema, $discarded)` at a slot of SchemaDeclarationOrder.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class PositionedDeclarationCheck implements SchemaDeclarationCheckInterface {

	/**
	 * Constructor.
	 *
	 * @param int     $position The slot of SchemaDeclarationOrder.
	 * @param Closure $check    Takes the schema and the discarded annotations.
	 */
	public function __construct(
		private readonly int $position,
		private readonly Closure $check,
	) {
	}//end __construct()

	/**
	 * The slot this check runs in.
	 *
	 * @return int The position.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function position(): int {
		return $this->position;
	}//end position()

	/**
	 * Run the check.
	 *
	 * @param Schema               $schema    The schema being saved.
	 * @param DiscardedAnnotations $discarded The annotations this save discards.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function check(Schema $schema, DiscardedAnnotations $discarded): void {
		($this->check)($schema, $discarded);
	}//end check()
}//end class
