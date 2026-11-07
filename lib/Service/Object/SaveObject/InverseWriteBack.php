<?php

/**
 * OpenRegister InverseWriteBack
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObjectInterface;
use OCP\IURLGenerator;

/**
 * Writes a saved object back into the targets of its `inversedBy` properties with `writeBack`.
 *
 * When a community lists its participants, each participant gets the community's uuid
 * in its own inverse property. `writeBack`, `removeAfterWriteBack` and `inversedBy` are
 * read from the property, from its items, or from both.
 *
 * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to configuration.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class InverseWriteBack {

	/**
	 * @param MagicMapperInterface $objects Reads the targets.
	 * @param SaveObjectInterface  $saver   Saves the targets.
	 * @param SaveLookups          $lookups Resolves the target schema.
	 * @param IURLGenerator        $urls    Builds the schema object.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objects,
		private readonly SaveObjectInterface $saver,
		private readonly SaveLookups $lookups,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * Write the run's parent back into the targets of every write-back property of a schema.
	 *
	 * @param CascadeRun $run    The pass over the parent and its data, which loses removed properties.
	 * @param Schema     $schema The parent's schema.
	 *
	 * @return array The data, without the properties removed after their write-back.
	 *
	 * @throws Exception
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function write(CascadeRun $run, Schema $schema): array {
		try {
			$schemaObject = $schema->getSchemaObject($this->urls);
			$properties = json_decode(json_encode($schemaObject), associative: true)['properties'] ?? [];
		} catch (Exception $e) {
			return $run->data;
		}

		foreach (array_filter($properties, fn (array $property) => self::writesBack(property: $property)) as $name => $definition) {
			$run->property = $name;
			$run->definition = $definition;
			$this->writeProperty(run: $run);
		}

		return $run->data;
	}//end write()

	/**
	 * Whether a property writes back: inversedBy with writeBack on the property, on its items, or on an array of objects.
	 *
	 * @param array $property The property definition.
	 *
	 * @return bool True when the property writes back.
	 */
	private static function writesBack(array $property): bool {
		if (self::declaresOnProperty(definition: $property) === true) {
			return true;
		}

		if ($property['type'] !== 'array') {
			return false;
		}

		return self::levels(definition: $property) !== [];
	}//end writesBack()

	/**
	 * Whether a definition has inversedBy and writeBack at its own level.
	 *
	 * @param array $definition The property definition.
	 *
	 * @return bool True when both are there.
	 */
	private static function declaresOnProperty(array $definition): bool {
		return ($definition['inversedBy'] ?? null) !== null && ($definition['writeBack'] ?? null) === true;
	}//end declaresOnProperty()

	/**
	 * Where the settings of a definition come from.
	 *
	 * @param array $definition The property definition.
	 *
	 * @return array The level with the inverse property and target schema, then the level with
	 *               the register and removal flag; empty when the definition does not write back.
	 */
	private static function levels(array $definition): array {
		if (self::declaresOnProperty(definition: $definition) === true) {
			return [$definition, $definition];
		}

		$items = ($definition['items'] ?? []);
		if (($items['inversedBy'] ?? null) === null) {
			return [];
		}

		$flags = self::flagLevel(definition: $definition, items: $items);
		if (($flags['writeBack'] ?? null) !== true) {
			return [];
		}

		return [$items, $flags];
	}//end levels()

	/**
	 * The level that holds the writeBack flag of an items relation: the items when they set it.
	 *
	 * @param array $definition The property definition.
	 * @param array $items      Its items definition.
	 *
	 * @return array The level.
	 */
	private static function flagLevel(array $definition, array $items): array {
		if (($items['writeBack'] ?? null) === true) {
			return $items;
		}

		return $definition;
	}//end flagLevel()

	/**
	 * Write the parent into every target the run's property names, and drop the property when configured.
	 *
	 * @param CascadeRun $run The pass, on one property.
	 *
	 * @return void
	 */
	private function writeProperty(CascadeRun $run): void {
		$ids = self::targetIds(targets: $run->data[$run->property] ?? null);
		if ($ids === []) {
			return;
		}

		$settings = self::settings(run: $run);
		$link = $this->link(settings: $settings, source: $run->parent);
		if ($link === []) {
			return;
		}

		$this->writeToTargets(ids: $ids, link: $link);
		if ($settings->removeFromSource === true) {
			unset($run->data[$run->property]);
		}
	}//end writeProperty()

	/**
	 * The settings of the run's property. The target register defaults to the parent's register.
	 *
	 * @param CascadeRun $run The pass, on one property.
	 *
	 * @return WriteBackSettings The settings; empty when the property declares none.
	 */
	private static function settings(CascadeRun $run): WriteBackSettings {
		$settings = new WriteBackSettings();
		$levels = self::levels(definition: $run->definition);
		if ($levels === []) {
			return $settings;
		}

		[$source, $flags] = $levels;
		$settings->inverseProperty = $source['inversedBy'];
		$settings->targetSchema = $source['$ref'] ?? null;
		$settings->targetRegister = $flags['register'] ?? $run->parent->getRegister();
		$settings->removeFromSource = $flags['removeAfterWriteBack'] ?? false;

		return $settings;
	}//end settings()

	/**
	 * The inverse relation complete settings describe.
	 *
	 * @param WriteBackSettings $settings The settings.
	 * @param ObjectEntity      $source   The object written back.
	 *
	 * @return array The inverse property, register, schema and source; empty when the settings
	 *               are incomplete or the target schema is unknown.
	 */
	private function link(WriteBackSettings $settings, ObjectEntity $source): array {
		if (self::complete(settings: $settings) === false) {
			return [];
		}

		$schemaId = $this->lookups->schemaId(reference: $settings->targetSchema);
		if ($schemaId === null) {
			return [];
		}

		return [
			'inverseProperty' => $settings->inverseProperty,
			'register' => $settings->targetRegister,
			'schema' => $schemaId,
			'source' => $source->getUuid(),
		];
	}//end link()

	/**
	 * Whether settings name both an inverse property and a target schema.
	 *
	 * @param WriteBackSettings $settings The settings.
	 *
	 * @return bool True when both are set.
	 */
	private static function complete(WriteBackSettings $settings): bool {
		return in_array($settings->inverseProperty, [false, null], true) === false
			&& in_array($settings->targetSchema, [false, null], true) === false;
	}//end complete()

	/**
	 * The target identifiers of a write-back value that are object identifiers.
	 *
	 * Supports standard UUIDs, UUIDs without dashes, prefixed UUIDs, and numeric IDs.
	 *
	 * @param mixed $targets One identifier or a list of them.
	 *
	 * @return array The valid identifiers, keys kept.
	 */
	private static function targetIds(mixed $targets): array {
		if (is_array($targets) === false) {
			$targets = [$targets];
		}

		$item = new CascadeItem();

		return array_filter(
			$targets,
			fn ($id) => empty($id) === false && is_string($id) === true && trim($id) !== ''
				&& $item->isObjectIdentifier(value: $id) === true
		);
	}//end targetIds()

	/**
	 * Add the parent to the inverse property of each target.
	 *
	 * @param array $ids  The target identifiers.
	 * @param array $link The inverse relation.
	 *
	 * @return void
	 */
	private function writeToTargets(array $ids, array $link): void {
		foreach ($ids as $id) {
			$this->writeToTarget(reference: (string)$id, link: $link);
		}
	}//end writeToTargets()

	/**
	 * Add the parent to one target's inverse property and save the target.
	 *
	 * The target is saved by the uuid of the row found: the reference may be its
	 * id, which as a uuid would name another row. A target that cannot be read or
	 * saved does not stop the other targets.
	 *
	 * @param string $reference The target's uuid or id, as the parent holds it.
	 * @param array  $link      The inverse relation.
	 *
	 * @return void
	 */
	private function writeToTarget(string $reference, array $link): void {
		try {
			$target = $this->objects->find($reference);
			$uuid = $target->getUuid();
			if ($uuid === null || $uuid === '') {
				return;
			}

			$this->saveTarget(target: $target, link: $link);
		} catch (Exception $e) {
			return;
		}
	}//end writeToTarget()

	/**
	 * Save a target by its own uuid, with the source in its inverse property.
	 *
	 * @param ObjectEntity $target The target.
	 * @param array        $link   The inverse relation.
	 *
	 * @return void
	 */
	private function saveTarget(ObjectEntity $target, array $link): void {
		$this->saver->saveObject(
			register: $link['register'],
			schema: $link['schema'],
			data: array_replace($target->getObject(), [$link['inverseProperty'] => self::withSource(target: $target, link: $link)]),
			uuid: $target->getUuid()
		);
	}//end saveTarget()

	/**
	 * A target's inverse property as a list that holds the source.
	 *
	 * @param ObjectEntity $target The target.
	 * @param array        $link   The inverse relation.
	 *
	 * @return array The inverse property's new value.
	 */
	private static function withSource(ObjectEntity $target, array $link): array {
		$inverse = ($target->getObject()[$link['inverseProperty']] ?? []);
		if (is_array($inverse) === false) {
			$inverse = [$inverse];
		}

		if (in_array($link['source'], $inverse, true) === false) {
			$inverse[] = $link['source'];
		}

		return $inverse;
	}//end withSource()
}//end class
