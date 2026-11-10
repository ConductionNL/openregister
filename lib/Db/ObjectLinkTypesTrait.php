<?php

/**
 * OpenRegister ObjectLinkTypesTrait
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Registers the column types every object link starts with: the linked
 * object's uuid and its register and schema ids.
 *
 * @spec exclude shared entity plumbing, not a capability of its own
 */
trait ObjectLinkTypesTrait {

	/**
	 * Register the object uuid, register id and schema id field types, in that order.
	 *
	 * @return void
	 */
	private function addObjectLinkTypes(): void {
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
	}//end addObjectLinkTypes()
}//end trait
