<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * FIXME Auto-generated migration step: Please modify to your needs!
 */
class Version1Date20241128131427 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Update the openregister_objects table
		$table = $schema->getTable('openregister_objects');
		if ($table->hasColumn('uri') === false) {
			$table->addColumn(name: 'uri', typeName: Types::STRING, options: ['notnull' => true, 'length' => 255])->setDefault('');
		}
		if ($table->hasColumn('files') === false) {
			$table->addColumn(name: 'files', typeName: Types::JSON, options: ['notnull' => false])->setDefault(default: '{}');
		}
		if ($table->hasColumn('relations') === false) {
			$table->addColumn(name: 'relations', typeName: Types::JSON, options: ['notnull' => false])->setDefault(default: '{}');
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}