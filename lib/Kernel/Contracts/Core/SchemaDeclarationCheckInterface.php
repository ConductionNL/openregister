<?php

/**
 * SchemaDeclarationCheckInterface: one save-time check of a schema declaration that a higher area contributes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;

/**
 * A check the schema save runs at a fixed place in its sequence.
 *
 * The schema mapper (Core) owns the sequence; an area whose declarations it
 * validates contributes its checks through the SchemaDeclarationChecksEvent.
 * The position is a slot of SchemaDeclarationOrder, so a schema with several
 * faults is refused for the same one whichever area checks it.
 */
interface SchemaDeclarationCheckInterface {

	/**
	 * The slot of SchemaDeclarationOrder this check runs in.
	 *
	 * @return int The position.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function position(): int;

	/**
	 * Refuse the schema, by throwing, or record an advisory annotation it discards.
	 *
	 * @param Schema               $schema    The schema being saved.
	 * @param DiscardedAnnotations $discarded The annotations this save discards.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function check(Schema $schema, DiscardedAnnotations $discarded): void;
}//end interface
