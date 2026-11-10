<?php

/**
 * The state of one configuration import while it runs.
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

use OCA\OpenRegister\Db\Configuration;
use OCP\IUser;

/**
 * The configuration payload, who and what it is imported for, and the result
 * the import phases of {@see ImportHandler::importFromJson()} build up.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportRun
{

	/**
	 * The owner of the imported entities.
	 *
	 * @var string|null
	 */
	public ?string $owner = null;

	/**
	 * The importing app.
	 *
	 * @var string|null
	 */
	public ?string $appId = null;

	/**
	 * The configuration version.
	 *
	 * @var string|null
	 */
	public ?string $version = null;

	/**
	 * Whether version checks are bypassed.
	 *
	 * @var boolean
	 */
	public bool $force = false;

	/**
	 * Content hash of the payload as it arrived, before any phase changed it.
	 *
	 * @var string
	 */
	public string $definitionHash = '';

	/**
	 * Schema slug => the schema ids its declaring registers held before the import.
	 *
	 * @var array<string, list<int>>
	 */
	public array $slugToSchemaIds = [];

	/**
	 * Slug => id map of the entities the current phase resolves against.
	 *
	 * @var array<string, mixed>
	 */
	public array $slugsAndIdsMap = [];

	/**
	 * Set when a listener refused a listed object; the content hash is then not stored.
	 *
	 * @var boolean
	 */
	public bool $refusedByListener = false;

	/**
	 * The user listed objects are saved as; null until the object phase resolves it.
	 *
	 * @var IUser|null
	 */
	public ?IUser $actingUser = null;

	/**
	 * The import result.
	 *
	 * @var array<string, mixed>
	 */
	public array $result = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data          The configuration payload.
	 * @param Configuration        $configuration The configuration entity the import is tracked on.
	 */
	public function __construct(
		public array $data,
		public readonly Configuration $configuration,
	) {
	}//end __construct()
}//end class
