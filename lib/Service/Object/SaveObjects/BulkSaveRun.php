<?php

/**
 * OpenRegister BulkSaveRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObjects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * One SaveObjects::saveObjects() call: its switches, its result so far and its timings.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class BulkSaveRun {

	/**
	 * The register context the caller passed.
	 *
	 * @var Register|string|integer|null
	 */
	public Register|string|int|null $register = null;

	/**
	 * The schema context the caller passed.
	 *
	 * @var Schema|string|integer|null
	 */
	public Schema|string|int|null $schema = null;

	/**
	 * The signed-in user the chunk guards ask create permission for; null when nobody is signed in.
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * Whether RBAC filtering applies.
	 *
	 * @var boolean
	 */
	public bool $rbac = true;

	/**
	 * Whether multi-organisation filtering applies.
	 *
	 * @var boolean
	 */
	public bool $multitenancy = true;

	/**
	 * Whether objects are validated against their schema.
	 *
	 * @var boolean
	 */
	public bool $validation = false;

	/**
	 * Whether lifecycle events are dispatched.
	 *
	 * @var boolean
	 */
	public bool $events = false;

	/**
	 * Whether audit trail rows are written.
	 *
	 * @var boolean
	 */
	public bool $audit = true;

	/**
	 * The bulk result, filled as the run goes.
	 *
	 * @var array
	 */
	public array $result = [];

	/**
	 * The number of objects the caller asked to save.
	 *
	 * @var integer
	 */
	public int $totalObjects = 0;

	/**
	 * When the run started, in microtime seconds.
	 *
	 * @var float
	 */
	public float $startTime = 0.0;

	/**
	 * The schemas the preparation resolved, for the chunks.
	 *
	 * @var array
	 */
	public array $schemaCache = [];

	/**
	 * How long the bulk safeguards took, in milliseconds.
	 *
	 * @var float
	 */
	public float $safeguardMs = 0.0;

	/**
	 * How long the preparation took, in milliseconds.
	 *
	 * @var float
	 */
	public float $prepareMs = 0.0;

	/**
	 * The index of the chunk being saved.
	 *
	 * @var integer
	 */
	public int $chunkIndex = 0;

	/**
	 * Uuids of rows that update a stored object, as the safeguard screen found them; null when it did not run.
	 *
	 * @var array<string, bool>|null
	 */
	public ?array $storedUuids = null;
}//end class
