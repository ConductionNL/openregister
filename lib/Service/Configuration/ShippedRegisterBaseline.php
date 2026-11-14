<?php

/**
 * Records the definition an app shipped for a register.
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
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedDefinition;

/**
 * Keeps the register descriptor an app shipped as the register's baseline.
 *
 * The register counterpart of {@see ShippedSchemaBaseline}'s record step:
 * called once the register row was written, on create and on an applied
 * update, never on an import the version gate skipped, so the baseline always
 * names a release the instance actually ran. Only the parts the app shipped
 * are kept, and the declared configuration block is one of them.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
final class ShippedRegisterBaseline
{

	/**
	 * The register parts a baseline keeps.
	 *
	 * @var array<int, string>
	 */
	public const PARTS = ['title', 'description', 'authorization', 'configuration'];

	/**
	 * Constructor.
	 *
	 * @param ShippedConfigurationGuard|null $guard The guard that keeps baselines; none records nothing.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function __construct(
		private readonly ?ShippedConfigurationGuard $guard,
	) {
	}//end __construct()

	/**
	 * Record the shipped parts of a register an app import just wrote.
	 *
	 * Nothing is recorded without an app id (not app-shipped), without a slug
	 * (no address), or when the app shipped none of the parts.
	 *
	 * @param array               $data    The register definition as the app shipped it.
	 * @param SchemaImportOptions $options The app id and version of the import.
	 *
	 * @return bool True when a baseline was recorded.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function record(array $data, SchemaImportOptions $options): bool
	{
		$slug = strtolower((string) ($data['slug'] ?? ''));
		if ($this->guard === null || $options->appId === null || $slug === '') {
			return false;
		}

		$parts = self::shippedParts(data: $data);
		if ($parts === []) {
			return false;
		}

		return $this->guard->recordShippedRegister(
			slug: $slug,
			shipped: new ShippedDefinition(definition: $parts, app: $options->appId, appVersion: ($options->version ?? ''))
		);
	}//end record()

	/**
	 * The parts of the definition the app shipped, in declared order.
	 *
	 * @param array $data The register definition.
	 *
	 * @return array<string, mixed> The shipped parts.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public static function shippedParts(array $data): array
	{
		$parts = [];
		foreach (self::PARTS as $part) {
			if (array_key_exists($part, $data) === true) {
				$parts[$part] = $data[$part];
			}
		}

		return $parts;
	}//end shippedParts()
}//end class
