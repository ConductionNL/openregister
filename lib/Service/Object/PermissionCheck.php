<?php

/**
 * PermissionCheck
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * One permission check in progress: what is asked, about which object, by whom,
 * and what the rule chain has resolved so far.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */
final class PermissionCheck {
	/**
	 * The caller's user id; resolved from the session when it was not given.
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * The object owner, for the owner bypass.
	 *
	 * @var string|null
	 */
	public ?string $objectOwner = null;

	/**
	 * The object, for conditional matching and per-object rules.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $object = null;

	/**
	 * The object's data, for conditional matching.
	 *
	 * @var array|null
	 */
	public ?array $objectData = null;

	/**
	 * The object's organisation, for conditional matching.
	 *
	 * @var string|null
	 */
	public ?string $objectOrganisation = null;

	/**
	 * The effective authorization block, once resolved.
	 *
	 * @var array|null
	 */
	public ?array $authorization = null;

	/**
	 * The caller's group ids, once resolved.
	 *
	 * @var list<string>
	 */
	public array $userGroups = [];

	/**
	 * Start a check.
	 *
	 * @param Schema $schema The schema being checked
	 * @param string $action The action asked for
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $action,
	) {
	}//end __construct()

	/**
	 * Set the object the check is about, with the data and organisation it is matched on.
	 *
	 * @param ObjectEntity|null $object The object, or null for a schema-level check
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function setObject(?ObjectEntity $object): void {
		$this->object = $object;
		if ($object === null) {
			return;
		}

		$this->objectData         = $object->getObject();
		$this->objectOrganisation = $object->getOrganisation();
	}//end setObject()
}//end class
