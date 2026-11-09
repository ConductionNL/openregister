<?php

/**
 * OpenRegister Object Preview Targets
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/config-preview-duplicates-and-rest/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The registers and schemas a previewed object can land in: the local ones, and the ones the import creates.
 *
 * @spec openspec/changes/config-preview-duplicates-and-rest/specs/data-import-export/spec.md
 */
final class ObjectPreviewTargets {

	/**
	 * Local registers by lowercased slug.
	 *
	 * @var array<string, Register>
	 */
	private array $registersBySlug;

	/**
	 * Local schemas by lowercased slug.
	 *
	 * @var array<string, Schema>
	 */
	private array $schemasBySlug;

	/**
	 * Register and schema slugs this import creates.
	 *
	 * @var array{registers: array<string, true>, schemas: array<string, true>}
	 */
	private array $planned;

	/**
	 * Constructor.
	 *
	 * @param array<string, Register>                                              $registersBySlug Local registers by lowercased slug.
	 * @param array<string, Schema>                                                $schemasBySlug   Local schemas by lowercased slug.
	 * @param array{registers: array<string, true>, schemas: array<string, true>} $planned         Register and schema slugs this import creates.
	 *
	 * @spec openspec/changes/config-preview-duplicates-and-rest/specs/data-import-export/spec.md
	 */
	public function __construct(array $registersBySlug, array $schemasBySlug, array $planned) {
		$this->registersBySlug = $registersBySlug;
		$this->schemasBySlug = $schemasBySlug;
		$this->planned = $planned;
	}//end __construct()

	/**
	 * The local register and schema a preview row names, or null when either is not local.
	 *
	 * @param array{register: string, schema: string} $row The preview row.
	 *
	 * @return array{register: Register, schema: Schema}|null
	 *
	 * @spec openspec/changes/config-preview-duplicates-and-rest/specs/data-import-export/spec.md
	 */
	public function local(array $row): ?array {
		$register = ($this->registersBySlug[strtolower($row['register'])] ?? null);
		$schema = ($this->schemasBySlug[strtolower($row['schema'])] ?? null);
		if ($register === null || $schema === null) {
			return null;
		}

		return ['register' => $register, 'schema' => $schema];
	}//end local()

	/**
	 * Whether the register and the schema a row names are each local or created by the same import.
	 *
	 * When they are, the object is created too (live pass O13).
	 *
	 * @param array{register: string, schema: string} $row The preview row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/config-preview-duplicates-and-rest/specs/data-import-export/spec.md
	 */
	public function arrives(array $row): bool {
		$register = strtolower($row['register']);
		$schema = strtolower($row['schema']);
		$registerComes = (isset($this->registersBySlug[$register]) === true || isset($this->planned['registers'][$register]) === true);
		$schemaComes = (isset($this->schemasBySlug[$schema]) === true || isset($this->planned['schemas'][$schema]) === true);
		return $registerComes === true && $schemaComes === true;
	}//end arrives()
}//end class
