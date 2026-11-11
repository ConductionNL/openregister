<?php

/**
 * HistoryEntryRedactor - an object's history entries as one reader may see them
 *
 * Reading an object is not the same as reading every value it ever held. A
 * property hidden from a caller by a read rule, a `writeOnly` flag or the
 * object's lifecycle state is hidden on the object, and its old and new values
 * in the history would show it all the same. This class removes them, using the
 * rules the render path applies, through
 * `PropertyRbacHandler::filterReadableProperties()` and
 * `PropertyRbacHandler::stripWriteOnlyProperties()`, and withholds the `@self`
 * copies of a withheld property (the relations mirror and the name,
 * description, summary and image fields) as `RenderObject` does for the object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-an-objects-history-withholds-from-its-reader-what-the-object-withholds
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;

/**
 * Removes from history entries what one reader may not see on the object.
 */
class HistoryEntryRedactor {

	/**
	 * Constructor.
	 *
	 * @param PropertyRbacHandlerInterface $properties The render path's property read rules.
	 */
	public function __construct(
		private readonly PropertyRbacHandlerInterface $properties,
	) {
	}//end __construct()

	/**
	 * The entries as this reader is given them.
	 *
	 * @param array<AuditTrail> $rows     The object's entries.
	 * @param ReadableObject    $readable The object and its schema.
	 *
	 * @return array<int, array> The entries without the withheld fields and values.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-an-objects-history-withholds-from-its-reader-what-the-object-withholds
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
	 */
	public function redact(array $rows, ReadableObject $readable): array {
		$schema = $readable->schema;
		$hidden = $this->hiddenProperties(readable: $readable, rows: $rows);
		// Explicit-reveal values read masked in the history, as on the object;
		// their `@self` copies are withheld like a hidden property's.
		$masked = array_values(array_diff(ExplicitReveal::explicitProperties(schema: $schema), $hidden));
		$run = new HistoryRedactionRun(schema: $schema, hidden: $hidden, masked: $masked);

		$entries = [];
		foreach ($rows as $row) {
			$entries[] = $this->readerEntry(run: $run, entry: $row);
		}

		return $entries;
	}//end redact()

	/**
	 * The top-level properties this caller may not see on this object.
	 *
	 * Asked of `filterReadableProperties()` on the object's current data, with
	 * every property the history names added, so the answer comes from the one
	 * funnel the object render uses: `writeOnly`, the property's read rule and
	 * the lifecycle state's hidden fields. A property the history names that
	 * the object no longer holds is probed with a null value, so a rule on a
	 * property that was cleared still hides its history.
	 *
	 * @param ReadableObject    $readable The object and its schema.
	 * @param array<AuditTrail> $rows     The object's entries.
	 *
	 * @return array<int, string> The withheld property names.
	 */
	private function hiddenProperties(ReadableObject $readable, array $rows): array {
		$probe = $this->changedKeyProbe(object: $readable->object, rows: $rows);

		// Property rules match on the record's own metadata, as on the object read.
		$probe['@self'] = $readable->object->getObjectArray();

		$visible = $this->properties->filterReadableProperties(schema: $readable->schema, object: $probe);

		return $this->keysNotVisible(probe: $probe, visible: $visible);
	}//end hiddenProperties()

	/**
	 * The object's data, plus every property a history row changed that the object no longer carries (as null).
	 *
	 * @param ObjectEntity           $object The object the rows are about.
	 * @param array<int, AuditTrail> $rows   The history rows.
	 *
	 * @return array<string, mixed> The probe the property rules are run against.
	 */
	private function changedKeyProbe(ObjectEntity $object, array $rows): array {
		$probe = ($object->getObject() ?? []);
		foreach ($rows as $row) {
			foreach (array_keys($row->getChanged()) as $key) {
				$key = (string)$key;
				if (str_starts_with($key, '@') === false && array_key_exists($key, $probe) === false) {
					$probe[$key] = null;
				}
			}
		}

		return $probe;
	}//end changedKeyProbe()

	/**
	 * The probe's own properties (not `@` metadata) the property rules did not leave visible.
	 *
	 * @param array<string, mixed> $probe   The probe.
	 * @param array<string, mixed> $visible What the property rules left readable.
	 *
	 * @return array<int, string> The hidden property names.
	 */
	private function keysNotVisible(array $probe, array $visible): array {
		$hidden = [];
		foreach (array_keys($probe) as $key) {
			$key = (string)$key;
			if (str_starts_with($key, '@') === false && array_key_exists($key, $visible) === false) {
				$hidden[] = $key;
			}
		}

		return $hidden;
	}//end keysNotVisible()

	/**
	 * One entry as a reader is given it.
	 *
	 * @param HistoryRedactionRun $run   The schema, and the withheld and masked property names.
	 * @param AuditTrail          $entry The entry.
	 *
	 * @return array The entry, without the withheld fields and values.
	 */
	private function readerEntry(HistoryRedactionRun $run, AuditTrail $entry): array {
		$row = $entry->jsonSerialize();

		foreach (ReadableAuditTrailLister::WITHHELD_FIELDS as $field) {
			unset($row[$field]);
		}

		if (is_array($row['changed'] ?? null) === true) {
			$row['changed'] = $this->filterChanged(run: $run, changed: $row['changed']);
		}

		return $row;
	}//end readerEntry()

	/**
	 * Remove what the reader may not see from one entry's change set.
	 *
	 * @param HistoryRedactionRun $run     The schema, and the withheld and masked property names.
	 * @param array               $changed The change set, keyed by property.
	 *
	 * @return array The change set the reader is given.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
	 */
	private function filterChanged(HistoryRedactionRun $run, array $changed): array {
		foreach ($run->hidden as $property) {
			unset($changed[$property]);
		}

		$changed = ExplicitReveal::maskChanges(schema: $run->schema, changed: $changed);

		$changed = $this->stripNestedWriteOnly(changed: $changed, schema: $run->schema);

		if (is_array($changed['cascadeContext'] ?? null) === true) {
			$changed['cascadeContext'] = $this->filterCascade(cascade: $changed['cascadeContext'], hidden: $run->hidden);
		}

		if (is_array($changed['@self'] ?? null) === false) {
			return $changed;
		}

		foreach (['old', 'new'] as $side) {
			if (is_array($changed['@self'][$side] ?? null) === true) {
				$changed['@self'][$side] = $this->filterSelf(run: $run, self: $changed['@self'][$side]);
			}
		}

		return $changed;
	}//end filterChanged()

	/**
	 * The cascade context of a referential-integrity entry, as a reader is given it.
	 *
	 * The context names the object whose delete cascaded into this one
	 * (`triggerObject`, `triggerSchema`), and that object may be one the reader
	 * may not read: the object read path would not tell them it exists. They are
	 * withheld for every reader; the kind of cascade stays, and the property that
	 * carried the reference stays unless the reader may not see that property.
	 *
	 * @param array              $cascade The `cascadeContext` of one entry.
	 * @param array<int, string> $hidden  Withheld property names.
	 *
	 * @return array The context without the other object's identity.
	 */
	private function filterCascade(array $cascade, array $hidden): array {
		$cascade['triggerObject'] = null;
		$cascade['triggerSchema'] = null;

		$property = ($cascade['property'] ?? null);
		if (is_string($property) === true && in_array(explode('.', $property)[0], $hidden, true) === true) {
			$cascade['property'] = null;
		}

		return $cascade;
	}//end filterCascade()

	/**
	 * Strip declared nested write-only paths from the old and new values.
	 *
	 * The before and after values are rebuilt as two object bodies and handed
	 * to `stripWriteOnlyProperties()`, the same strip the render path applies
	 * to the object body, so a secret nested in an untyped property is removed
	 * from its history by the rule that removes it from the object.
	 *
	 * @param array  $changed The change set.
	 * @param Schema $schema  The object's schema.
	 *
	 * @return array The change set without nested write-only values.
	 */
	private function stripNestedWriteOnly(array $changed, Schema $schema): array {
		$sides = $this->changeSides(changed: $changed);
		if ($sides['old'] === []) {
			return $changed;
		}

		$stripped = [
			'old' => $this->properties->stripWriteOnlyProperties(schema: $schema, object: $sides['old']),
			'new' => $this->properties->stripWriteOnlyProperties(schema: $schema, object: $sides['new']),
		];

		return $this->withStrippedSides(changed: $changed, stripped: $stripped);
	}//end stripNestedWriteOnly()

	/**
	 * The before and after values of a row's property changes, as two objects.
	 *
	 * @param array<string, mixed> $changed The row's changed map.
	 *
	 * @return array{old: array<string, mixed>, new: array<string, mixed>} The before and after sides.
	 */
	private function changeSides(array $changed): array {
		$sides = ['old' => [], 'new' => []];
		foreach ($changed as $key => $value) {
			if ($this->isPropertyChange(key: (string)$key, value: $value) === true) {
				$sides['old'][$key] = $value['old'];
				$sides['new'][$key] = $value['new'];
			}
		}

		return $sides;
	}//end changeSides()

	/**
	 * Rewrite a row's property changes from their stripped sides; a change stripped from both sides is dropped.
	 *
	 * @param array<string, mixed>                                         $changed  The row's changed map.
	 * @param array{old: array<string, mixed>, new: array<string, mixed>} $stripped The sides with write-only properties removed.
	 *
	 * @return array<string, mixed> The changed map.
	 */
	private function withStrippedSides(array $changed, array $stripped): array {
		foreach (array_keys($changed) as $key) {
			if ($this->isPropertyChange(key: (string)$key, value: $changed[$key]) === false) {
				continue;
			}

			if (array_key_exists($key, $stripped['old']) === false && array_key_exists($key, $stripped['new']) === false) {
				unset($changed[$key]);
				continue;
			}

			$changed[$key] = ['old' => ($stripped['old'][$key] ?? null), 'new' => ($stripped['new'][$key] ?? null)];
		}

		return $changed;
	}//end withStrippedSides()

	/**
	 * Whether a change-set entry is a property's old and new value.
	 *
	 * @param string $key   The change-set key.
	 * @param mixed  $value The change-set value.
	 *
	 * @return bool True for a `{old, new}` pair on a property.
	 */
	private function isPropertyChange(string $key, mixed $value): bool {
		return str_starts_with($key, '@') === false
			&& is_array($value) === true
			&& array_key_exists('old', $value) === true
			&& array_key_exists('new', $value) === true;
	}//end isPropertyChange()

	/**
	 * Withhold the `@self` copies of withheld properties.
	 *
	 * The relations mirror holds reference-shaped values under the property's
	 * name, and the schema's name, description, summary and image fields copy a
	 * property into `@self`. Whatever is withheld from the body is withheld from
	 * those copies, by the rule `RenderObject::withholdRowCopies()` applies.
	 *
	 * @param HistoryRedactionRun $run  The schema, and the withheld and masked property names.
	 * @param array               $self One side of the `@self` change.
	 *
	 * @return array The `@self` side without the copies.
	 */
	private function filterSelf(HistoryRedactionRun $run, array $self): array {
		if (is_array($self['relations'] ?? null) === true) {
			$self['relations'] = $this->filterRelations(run: $run, relations: $self['relations']);
		}

		$hidden = $run->withheld();
		$configuration = ($run->schema->getConfiguration() ?? []);
		foreach (RenderObject::METADATA_COPIES as $configKey => $field) {
			$source = trim((string)($configuration[$configKey] ?? ''));
			if ($source !== '' && array_key_exists($field, $self) === true
				&& in_array(explode('.', $source)[0], $hidden, true) === true
			) {
				$self[$field] = null;
			}
		}

		return $self;
	}//end filterSelf()

	/**
	 * The relations mirror without write-only paths and withheld properties.
	 *
	 * @param HistoryRedactionRun $run       The schema, and the withheld and masked property names.
	 * @param array               $relations The relations mirror.
	 *
	 * @return array The mirror the reader is given.
	 */
	private function filterRelations(HistoryRedactionRun $run, array $relations): array {
		$relations = $this->properties->stripWriteOnlyProperties(schema: $run->schema, object: $relations);
		foreach (array_keys($relations) as $key) {
			foreach ($run->withheld() as $property) {
				if ((string)$key === $property || str_starts_with((string)$key, $property.'.') === true) {
					unset($relations[$key]);
					break;
				}
			}
		}

		return $relations;
	}//end filterRelations()
}//end class
