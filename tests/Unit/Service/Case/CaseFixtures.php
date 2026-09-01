<?php

/**
 * Shared row factory for the case-layer unit tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;

/**
 * Builds plan-item rows.
 */
final class CaseFixtures {

	/**
	 * The demo anchor.
	 */
	public const OBJECT = '00000000-0000-0000-0000-0000000000aa';

	/**
	 * One row.
	 *
	 * @param int $id Row id.
	 * @param string $key Item key.
	 * @param string $type Plan-item type.
	 * @param string $state State.
	 * @param int|null $parentId Parent row id.
	 * @param bool $required Required-ness.
	 * @param bool $discretionary Discretionary-ness.
	 *
	 * @return CaseItem The row.
	 */
	public static function row(
		int $id,
		string $key,
		string $type,
		string $state,
		?int $parentId = null,
		bool $required = true,
		bool $discretionary = false,
	): CaseItem {
		$row = new CaseItem();
		$row->setId($id);
		$row->setUuid('item-' . $id);
		$row->setItemKey($key);
		$row->setName(ucfirst($key));
		$row->setObjectUuid(self::OBJECT);
		$row->setRegisterId(1);
		$row->setSchemaId(1);
		$row->setOrigin(CaseItem::ORIGIN_DEFINED);
		$row->setDefinitionItemKey($key);
		$row->setParentItemId($parentId);
		$row->setPlanItemType($type);
		$row->setPosition($id);
		$row->setState($state);
		$row->setIsTerminal(in_array($state, CaseItem::TERMINAL_STATES, true));
		$row->setRequired($required);
		$row->setDiscretionary($discretionary);
		$row->setRealisationCount(1);
		$row->setRealisationKind(CaseItem::REALISATION_NONE);
		$row->setPlanSettings(['authorization' => ['demo-behandelaars']]);
		$row->resetUpdatedFields();

		return $row;
	}//end row()
}//end class
