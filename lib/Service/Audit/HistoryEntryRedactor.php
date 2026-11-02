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
use OCA\OpenRegister\Service\PropertyRbacHandler;

/**
 * Removes from history entries what one reader may not see on the object.
 */
class HistoryEntryRedactor {

	/**
	 * Constructor.
	 *
	 * @param PropertyRbacHandler $properties The render path's property read rules.
	 */
	public function __construct(
		private readonly PropertyRbacHandler $properties,
	) {
	}//end __construct()

	/**
	 * The entries as this reader is given them.
	 *
	 * @param array<AuditTrail> $rows   The object's entries.
	 * @param Schema            $schema The object's schema.
	 * @param ObjectEntity      $object The object.
	 *
	 * @return array<int, array> The entries without the withheld fields and values.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-an-objects-history-withholds-from-its-reader-what-the-object-withholds
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
	 */
	public function redact(array $rows, Schema $schema, ObjectEntity $object): array {
		$hidden = $this->hiddenProperties(schema: $schema, object: $object, rows: $rows);
		// Explicit-reveal values read masked in the history, as on the object;
		// their `@self` copies are withheld like a hidden property's.
		$masked = array_values(array_diff(ExplicitReveal::explicitProperties(schema: $schema), $hidden));

		$entries = [];
		foreach ($rows as $row) {
			$entries[] = $this->readerEntry(entry: $row, schema: $schema, hidden: $hidden, masked: $masked);
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
	 * @param Schema            $schema The object's schema.
	 * @param ObjectEntity      $object The object.
	 * @param array<AuditTrail> $rows   The object's entries.
	 *
	 * @return array<int, string> The withheld property names.
	 */
	private function hiddenProperties(Schema $schema, ObjectEntity $object, array $rows): array {
		$probe = ($object->getObject() ?? []);
		foreach ($rows as $row) {
			foreach (array_keys($row->getChanged()) as $key) {
				$key = (string)$key;
				if (str_starts_with($key, '@') === false && array_key_exists($key, $probe) === false) {
					$probe[$key] = null;
				}
			}
		}

		// Property rules may match on the object's organisation or owner, as
		// RenderObject provides them on the object read.
		$probe['@self'] = [
			'organisation' => $object->getOrganisation(),
			'owner' => $object->getOwner(),
		];

		$visible = $this->properties->filterReadableProperties(schema: $schema, object: $probe);

		$hidden = [];
		foreach (array_keys($probe) as $key) {
			$key = (string)$key;
			if (str_starts_with($key, '@') === false && array_key_exists($key, $visible) === false) {
				$hidden[] = $key;
			}
		}

		return $hidden;
	}//end hiddenProperties()

	/**
	 * One entry as a reader is given it.
	 *
	 * @param AuditTrail         $entry  The entry.
	 * @param Schema             $schema The object's schema.
	 * @param array<int, string> $hidden Withheld property names.
	 * @param array<int, string> $masked Explicit-reveal property names, shown masked.
	 *
	 * @return array The entry, without the withheld fields and values.
	 */
	private function readerEntry(AuditTrail $entry, Schema $schema, array $hidden, array $masked = []): array {
		$row = $entry->jsonSerialize();

		foreach (ReadableAuditTrailLister::WITHHELD_FIELDS as $field) {
			unset($row[$field]);
		}

		if (is_array($row['changed'] ?? null) === true) {
			$row['changed'] = $this->filterChanged(changed: $row['changed'], schema: $schema, hidden: $hidden, masked: $masked);
		}

		return $row;
	}//end readerEntry()

	/**
	 * Remove what the reader may not see from one entry's change set.
	 *
	 * @param array              $changed The change set, keyed by property.
	 * @param Schema             $schema  The object's schema.
	 * @param array<int, string> $hidden  Withheld property names.
	 * @param array<int, string> $masked  Explicit-reveal property names, whose `@self` copies are withheld too.
	 *
	 * @return array The change set the reader is given.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExplicitReveal's rules are pure functions.
	 */
	private function filterChanged(array $changed, Schema $schema, array $hidden, array $masked = []): array {
		foreach ($hidden as $property) {
			unset($changed[$property]);
		}

		$changed = ExplicitReveal::maskChanges(schema: $schema, changed: $changed);

		$changed = $this->stripNestedWriteOnly(changed: $changed, schema: $schema);

		if (is_array($changed['cascadeContext'] ?? null) === true) {
			$changed['cascadeContext'] = $this->filterCascade(cascade: $changed['cascadeContext'], hidden: $hidden);
		}

		if (is_array($changed['@self'] ?? null) === false) {
			return $changed;
		}

		foreach (['old', 'new'] as $side) {
			if (is_array($changed['@self'][$side] ?? null) === true) {
				$changed['@self'][$side] = $this->filterSelf(
					self: $changed['@self'][$side],
					schema: $schema,
					hidden: array_merge($hidden, $masked)
				);
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
		$before = [];
		$after = [];
		foreach ($changed as $key => $value) {
			if ($this->isPropertyChange(key: (string)$key, value: $value) === true) {
				$before[$key] = $value['old'];
				$after[$key] = $value['new'];
			}
		}

		if ($before === []) {
			return $changed;
		}

		$before = $this->properties->stripWriteOnlyProperties(schema: $schema, object: $before);
		$after = $this->properties->stripWriteOnlyProperties(schema: $schema, object: $after);

		foreach (array_keys($changed) as $key) {
			if ($this->isPropertyChange(key: (string)$key, value: $changed[$key]) === false) {
				continue;
			}

			if (array_key_exists($key, $before) === false && array_key_exists($key, $after) === false) {
				unset($changed[$key]);
				continue;
			}

			$changed[$key] = ['old' => ($before[$key] ?? null), 'new' => ($after[$key] ?? null)];
		}

		return $changed;
	}//end stripNestedWriteOnly()

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
	 * @param array              $self   One side of the `@self` change.
	 * @param Schema             $schema The object's schema.
	 * @param array<int, string> $hidden Withheld property names.
	 *
	 * @return array The `@self` side without the copies.
	 */
	private function filterSelf(array $self, Schema $schema, array $hidden): array {
		if (is_array($self['relations'] ?? null) === true) {
			$self['relations'] = $this->filterRelations(relations: $self['relations'], schema: $schema, hidden: $hidden);
		}

		$configuration = ($schema->getConfiguration() ?? []);
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
	 * @param array              $relations The relations mirror.
	 * @param Schema             $schema    The object's schema.
	 * @param array<int, string> $hidden    Withheld property names.
	 *
	 * @return array The mirror the reader is given.
	 */
	private function filterRelations(array $relations, Schema $schema, array $hidden): array {
		$relations = $this->properties->stripWriteOnlyProperties(schema: $schema, object: $relations);
		foreach (array_keys($relations) as $key) {
			foreach ($hidden as $property) {
				if ((string)$key === $property || str_starts_with((string)$key, $property.'.') === true) {
					unset($relations[$key]);
					break;
				}
			}
		}

		return $relations;
	}//end filterRelations()
}//end class
