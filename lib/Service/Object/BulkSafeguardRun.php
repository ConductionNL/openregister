<?php

/**
 * BulkSafeguardRun
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

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * One pass of the bulk-save safeguards: the caller, the effective flags, the batch
 * defaults, and what the current row resolved to.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */
final class BulkSafeguardRun {
	/**
	 * Whether the caller is an administrator.
	 *
	 * @var bool
	 */
	public bool $isAdmin = false;

	/**
	 * Whether RBAC applies; always true for a caller who is not an administrator.
	 *
	 * @var bool
	 */
	public bool $rbac = true;

	/**
	 * Whether rows are validated against their schema.
	 *
	 * @var bool
	 */
	public bool $validation = false;

	/**
	 * The batch's schema, used when a row names none.
	 *
	 * @var Schema|null
	 */
	public ?Schema $defaultSchema = null;

	/**
	 * Whether the caller named a batch schema that could not be loaded.
	 *
	 * @var bool
	 */
	public bool $schemaUnresolved = false;

	/**
	 * The batch's register, used when a row names none.
	 *
	 * @var Register|null
	 */
	public ?Register $defaultRegister = null;

	/**
	 * The stored rows found for the batch's uuids, per register and schema.
	 *
	 * @var array
	 */
	public array $existingByUuid = [];

	/**
	 * The current row's schema.
	 *
	 * @var Schema|null
	 */
	public ?Schema $rowSchema = null;

	/**
	 * The current row's uuid, when it names one.
	 *
	 * @var mixed
	 */
	public mixed $rowUuid = null;

	/**
	 * The current row's action, `create` or `update`.
	 *
	 * @var string
	 */
	public string $rowAction = 'create';

	/**
	 * The stored object the current row updates.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $rowExisting = null;

	/**
	 * Start a pass for a caller.
	 *
	 * @param IUser|null $currentUser The caller, or null without a session
	 */
	public function __construct(public readonly ?IUser $currentUser) {
	}//end __construct()

	/**
	 * The caller's user id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	public function userId(): ?string {
		return $this->currentUser?->getUID();
	}//end userId()
}//end class
