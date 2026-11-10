<?php

/**
 * BulkCaller
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
 * The caller of a bulk filter, resolved once for every object it looks at.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class BulkCaller {
	/**
	 * Whether the caller is an administrator.
	 *
	 * @var bool
	 */
	public bool $isAdmin = false;

	/**
	 * Whether each object is put through the RBAC check.
	 *
	 * @var bool
	 */
	public bool $checksRbac = false;

	/**
	 * Whether each object's organisation is compared.
	 *
	 * @var bool
	 */
	public bool $checksOrganisation = false;

	/**
	 * The caller's organisation, if any.
	 *
	 * @var string|null
	 */
	public ?string $organisation = null;

	/**
	 * Name the caller.
	 *
	 * @param string|null $userId      The session user, null when anonymous
	 * @param bool        $commandLine Whether PHP runs on the command line
	 */
	public function __construct(
		public readonly ?string $userId,
		public readonly bool $commandLine,
	) {
	}//end __construct()

	/**
	 * Whether the object is a draft its schema keeps from this caller.
	 *
	 * @param ObjectEntity $object The object
	 * @param Schema       $schema Its schema
	 *
	 * @return bool True when the object is left out
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-a-draft-must-not-be-changed-by-somebody-who-cannot-read-it
	 */
	public function keptFromDraft(ObjectEntity $object, Schema $schema): bool {
		return (new DraftCallerRule())->keptFromCaller(
			object: $object,
			schema: $schema,
			caller: new DraftCaller(userId: $this->userId, isAdmin: $this->isAdmin, commandLine: $this->commandLine)
		);
	}//end keptFromDraft()
}//end class
