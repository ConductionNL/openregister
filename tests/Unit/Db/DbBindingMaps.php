<?php

/**
 * The registrars that bind lib/Db interfaces, one per functional area.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\DbRegistrar;
use OCA\OpenRegister\Db\PlatformMapperRegistrar;
use OCA\OpenRegister\Db\ConfigurationMapperRegistrar;
use OCA\OpenRegister\Db\ComplianceMapperRegistrar;
use OCA\OpenRegister\Db\FilesMapperRegistrar;
use OCA\OpenRegister\Db\IntegrationsMapperRegistrar;
use OCA\OpenRegister\Db\ProcessesMapperRegistrar;

/**
 * Lists the registrars that bind lib/Db interfaces, so a binding test finds
 * a binding whichever area's registrar holds it.
 */
final class DbBindingMaps {

	/**
	 * DbRegistrar binds Core; each area binds its own mappers.
	 *
	 * @var list<class-string>
	 */
	public const REGISTRARS = [
		DbRegistrar::class,
		PlatformMapperRegistrar::class,
		ConfigurationMapperRegistrar::class,
		ComplianceMapperRegistrar::class,
		FilesMapperRegistrar::class,
		IntegrationsMapperRegistrar::class,
		ProcessesMapperRegistrar::class,
	];

	/**
	 * Every lib/Db binding, interface => class.
	 *
	 * @return array<class-string, class-string>
	 */
	public static function all(): array {
		$bindings = [];
		foreach (self::REGISTRARS as $registrar) {
			$bindings += constant($registrar . '::BINDINGS');
		}

		return $bindings;
	}//end all()
}//end class
