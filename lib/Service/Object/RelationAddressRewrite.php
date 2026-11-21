<?php

/**
 * OpenRegister RelationAddressRewrite
 *
 * A relation row stores the register and schema of both of its ends, because
 * that is what the deep link and the graph node are built from. Those two ids
 * are the one part of an object's address a move changes; the uuid stays. A
 * row left naming the old address opens the object where it no longer lives,
 * so a move rewrites them eagerly (design D-3).
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectRelation;
use OCA\OpenRegister\Db\ObjectRelationMapperInterface;

/**
 * Rewrites the stored addresses of an object's relation rows after a move.
 *
 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
 */
final class RelationAddressRewrite implements RelationAddressRewriteInterface {

	/**
	 * Constructor.
	 *
	 * @param ObjectRelationMapperInterface $relations The relation rows.
	 */
	public function __construct(
		private readonly ObjectRelationMapperInterface $relations,
	) {
	}//end __construct()

	/**
	 * Rewrite the register and schema every relation row stores for an object.
	 *
	 * @param string $uuid     The object's uuid.
	 * @param int    $register The register it now lives in.
	 * @param int    $schema   The schema it now lives in.
	 *
	 * @return int The number of rows rewritten.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
	 */
	public function rewrite(string $uuid, int $register, int $schema): int {
		if ($uuid === '') {
			return 0;
		}

		$rewritten = 0;
		foreach ($this->relations->findTouching(uuids: [$uuid]) as $row) {
			if ($this->readdress(row: $row, uuid: $uuid, register: $register, schema: $schema) === true) {
				$this->relations->save(relation: $row);
				$rewritten++;
			}
		}

		return $rewritten;
	}//end rewrite()

	/**
	 * Point whichever end of a row is the moved object at its new address.
	 *
	 * A row from the object to itself has both ends rewritten.
	 *
	 * @param ObjectRelation $row      The row.
	 * @param string         $uuid     The moved object.
	 * @param int            $register Its new register.
	 * @param int            $schema   Its new schema.
	 *
	 * @return bool Whether the row changed.
	 *
	 * @spec openspec/changes/identity-survives-a-move/specs/objects-crud/spec.md#requirement-the-old-address-keeps-answering
	 */
	private function readdress(ObjectRelation $row, string $uuid, int $register, int $schema): bool {
		$changed = false;
		if ($row->getSourceUuid() === $uuid
			&& ($row->getSourceRegister() !== $register || $row->getSourceSchema() !== $schema)
		) {
			$row->setSourceRegister($register);
			$row->setSourceSchema($schema);
			$changed = true;
		}

		if ($row->getTargetUuid() === $uuid
			&& ($row->getTargetRegister() !== $register || $row->getTargetSchema() !== $schema)
		) {
			$row->setTargetRegister($register);
			$row->setTargetSchema($schema);
			$changed = true;
		}

		return $changed;
	}//end readdress()
}//end class
