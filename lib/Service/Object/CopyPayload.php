<?php

/**
 * CopyPayload: the body a copy of an object is created from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * Builds the create body of a copy: the source's own fields with the overrides applied.
 *
 * Never carried over: the identity (`id`, `uuid`), the `@self` metadata, any
 * `_`-prefixed control key, and every property the schema marks as minted by
 * the platform (`x-openregister-generated`, such as a case number), so the
 * copy gets its own. An override may set any field except those, which a
 * create would otherwise take as a claim on the source's identity.
 *
 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
 */
final class CopyPayload {

	/**
	 * The keys that name an object rather than describe it.
	 *
	 * @var array<int, string>
	 */
	private const IDENTITY = ['id', 'uuid', '@self'];

	/**
	 * The create body of a copy.
	 *
	 * @param array<string, mixed> $source    The source object's own fields.
	 * @param array<string, mixed> $overrides The fields the caller sets on the copy.
	 * @param Schema               $schema    The schema the copy is created in.
	 *
	 * @return array<string, mixed> The body.
	 *
	 * @spec openspec/changes/records-copy-with-links/specs/objects-crud/spec.md#requirement-an-object-can-be-copied-with-the-links-the-caller-chooses
	 */
	public function of(array $source, array $overrides, Schema $schema): array {
		$generated = $this->generated(schema: $schema);

		return array_replace(
			$this->carried(fields: $source, generated: $generated),
			$this->carried(fields: $overrides, generated: $generated)
		);
	}//end of()

	/**
	 * The fields that may be carried into a create.
	 *
	 * @param array<string, mixed> $fields    The fields.
	 * @param array<int, string>   $generated The schema's platform-minted property names.
	 *
	 * @return array<string, mixed>
	 */
	private function carried(array $fields, array $generated): array {
		$carried = [];
		foreach ($fields as $key => $value) {
			$name = (string) $key;
			if (in_array($name, self::IDENTITY, true) === true
				|| str_starts_with($name, '_') === true
				|| in_array($name, $generated, true) === true
			) {
				continue;
			}

			$carried[$name] = $value;
		}

		return $carried;
	}//end carried()

	/**
	 * The properties the schema marks as minted by the platform.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, string> The property names.
	 */
	private function generated(Schema $schema): array {
		$names = [];
		foreach (($schema->getProperties() ?? []) as $name => $config) {
			if (is_array($config) === true && ($config[MoveObject::GENERATED] ?? null) !== null) {
				$names[] = (string) $name;
			}
		}

		return $names;
	}//end generated()
}//end class
