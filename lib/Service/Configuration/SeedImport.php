<?php

/**
 * The state of one seed-data import while it runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The configuration a seed-data pass imports from, the register its objects
 * go to, the schema whose objects are being imported, and the import result
 * it adds to.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SeedImport
{

	/**
	 * The importing app.
	 *
	 * @var string|null
	 */
	public ?string $appId = null;

	/**
	 * Whether a user is logged in; tasks and notes need one.
	 *
	 * @var boolean
	 */
	public bool $hasUserContext = false;

	/**
	 * The register seed objects go to unless an object names another.
	 *
	 * @var Register|null
	 */
	public ?Register $targetRegister = null;

	/**
	 * The target register's id, 0 when there is none.
	 *
	 * @var integer|string|null
	 */
	public int|string|null $targetRegisterId = 0;

	/**
	 * The slug of the schema whose objects are being imported.
	 *
	 * @var integer|string
	 */
	public int|string $schemaSlug = '';

	/**
	 * That schema's objects, as the configuration lists them.
	 *
	 * @var mixed
	 */
	public mixed $objects = null;

	/**
	 * That schema, once resolved.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema = null;

	/**
	 * The import result, shared with the caller.
	 *
	 * @var array<string, mixed>
	 */
	public array $result;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $configData The configuration payload.
	 * @param array<string, mixed> $result     The import result, updated in place.
	 *
	 * @psalm-suppress UnusedParam `$result` is bound by reference below; psalm does not count that binding as a use.
	 */
	public function __construct(
		public readonly array $configData,
		array &$result,
	) {
		$this->result = &$result;
	}//end __construct()
}//end class
