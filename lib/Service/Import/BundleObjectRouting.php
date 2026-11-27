<?php

/**
 * Sends each object of a register bundle to the schema it names.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/capability-rows-closing-round/specs/data-import-export/spec.md#requirement-a-register-groups-record-types-and-moves-as-one-unit
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

/**
 * Groups a register bundle's objects by the schema each one names in `@self.schema`.
 *
 * A register export carries every schema of the register and, when asked, the
 * records of all of them in one `components.objects` list; each record names
 * its schema by slug. Importing it creates those schemas, and this decides
 * which schema each record is saved into. A record that names no schema of the
 * bundle is not guessed into one: it is reported.
 *
 * @spec openspec/changes/capability-rows-closing-round/specs/data-import-export/spec.md#requirement-a-register-groups-record-types-and-moves-as-one-unit
 */
final class BundleObjectRouting {

	/**
	 * The summary key the objects that name no schema of the bundle are reported under.
	 *
	 * @var string
	 */
	public const UNASSIGNED = 'unassigned';

	/**
	 * The bundle's objects grouped by the schema slug they name.
	 *
	 * @param array<mixed>      $objects The bundle's objects, in file order.
	 * @param array<int|string> $slugs   The slugs of the bundle's schemas.
	 *
	 * @return array{bySchema: array<string, list<array<mixed>>>, unassigned: list<array{index: int, schema: mixed}>}
	 *         The objects per schema slug (in the order the slugs are given, file order within each),
	 *         and the position and named schema of each object that could not be placed.
	 *
	 * @spec openspec/changes/capability-rows-closing-round/specs/data-import-export/spec.md#requirement-a-register-groups-record-types-and-moves-as-one-unit
	 */
	public static function group(array $objects, array $slugs): array {
		$known    = array_fill_keys(array_map('strval', $slugs), true);
		$bySchema = array_fill_keys(array_keys($known), []);
		$unassigned = [];

		foreach (array_values($objects) as $index => $object) {
			$named = null;
			if (is_array($object) === true) {
				$named = ($object['@self']['schema'] ?? null);
			}

			if (is_string($named) === true && isset($known[$named]) === true) {
				$bySchema[$named][] = $object;
				continue;
			}

			$unassigned[] = ['index' => $index, 'schema' => $named];
		}

		return ['bySchema' => array_filter($bySchema, static fn (array $group): bool => $group !== []), 'unassigned' => $unassigned];
	}//end group()

	/**
	 * The schema ids a bundle's register is created with.
	 *
	 * An export lists the register's schemas by slug; the register is created
	 * on this instance, where only ids link it to the schemas the import just
	 * created. Each listed slug becomes that schema's id here; a listed entry
	 * that is no schema of the bundle (an id from the exporting instance, a
	 * slug the bundle does not carry) is left out rather than linking the
	 * register to whatever row has that id here. A register that lists none
	 * gets every schema of the bundle.
	 *
	 * @param mixed              $listed    The register definition's `schemas`.
	 * @param array<string, int> $idsBySlug The ids of the schemas the import created, by slug.
	 *
	 * @return list<int> The ids, in listed order, each once.
	 *
	 * @spec openspec/changes/capability-rows-closing-round/specs/data-import-export/spec.md#requirement-a-register-groups-record-types-and-moves-as-one-unit
	 */
	public static function registerSchemaIds(mixed $listed, array $idsBySlug): array {
		if (is_array($listed) === false || $listed === []) {
			return array_values(array_unique(array_values($idsBySlug)));
		}

		$ids = [];
		foreach ($listed as $entry) {
			if (is_string($entry) === true && isset($idsBySlug[$entry]) === true && in_array($idsBySlug[$entry], $ids, true) === false) {
				$ids[] = $idsBySlug[$entry];
			}
		}

		return $ids;
	}//end registerSchemaIds()

	/**
	 * The import summary for the objects that could not be placed in a schema.
	 *
	 * @param list<array{index: int, schema: mixed}> $unassigned The unplaced objects.
	 * @param array<int|string>                      $slugs      The slugs of the bundle's schemas.
	 *
	 * @return array<string, mixed> A summary shaped like a per-schema one, every object an error.
	 *
	 * @spec openspec/changes/capability-rows-closing-round/specs/data-import-export/spec.md#requirement-a-register-groups-record-types-and-moves-as-one-unit
	 */
	public static function unassignedSummary(array $unassigned, array $slugs): array {
		$expected = implode(', ', array_map('strval', $slugs));
		$errors   = [];
		foreach ($unassigned as $entry) {
			$named = 'none';
			if (is_scalar($entry['schema']) === true) {
				$named = (string)$entry['schema'];
			}

			$errors[] = [
				'object' => ($entry['index'] + 1),
				'error' => sprintf('The object names schema "%s", which is not in this register bundle (%s).', $named, $expected),
				'type' => 'UnassignedSchema',
			];
		}

		return [
			'found' => count($unassigned),
			'created' => [],
			'updated' => [],
			'unchanged' => [],
			'errors' => $errors,
			'warnings' => [],
		];
	}//end unassignedSummary()
}//end class
