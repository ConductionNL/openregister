<?php

/**
 * ListPresentationResolver — the columns and search fields a list surface renders.
 *
 * A list page written per object type is a list page that drifts per object
 * type (D-4). The schema declares what to show and what to search on; the
 * generic surface reads it here and renders any schema without a page of its
 * own. A schema that declares nothing gets the columns every OpenRegister list
 * already shows, so nothing changes for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Hinge
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\Schema;

/**
 * Resolves a schema's declared list surface, or the defaults it keeps without one.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
 */
class ListPresentationResolver implements ListPresentationResolverInterface {

	/**
	 * The columns every OpenRegister list already shows.
	 *
	 * These are metadata, not schema properties: the object's own name, the
	 * value of whatever field its lifecycle annotation calls the status, and
	 * when it last changed. A schema declaring no columns keeps exactly these.
	 *
	 * @var array<int, array{property: string, label: string, source: string}>
	 */
	public const DEFAULT_COLUMNS = [
		['property' => 'title', 'label' => 'Title', 'source' => 'metadata'],
		['property' => 'status', 'label' => 'Status', 'source' => 'metadata'],
		['property' => 'updated', 'label' => 'Last change', 'source' => 'metadata'],
	];

	/**
	 * The presentation of a schema that declares no list columns and no search fields.
	 *
	 * @var array{declared: bool, columns: array<int, array<string, mixed>>, searchFields: array<int, string>}
	 */
	private const UNDECLARED = [
		'declared' => false,
		'columns' => self::DEFAULT_COLUMNS,
		'searchFields' => [],
	];

	/**
	 * Resolve what a list surface should render for one schema.
	 *
	 * `declared` says whether the schema asked for this or whether it is the
	 * default: a surface that already has its own idea of the default columns
	 * can keep it, and only defer to this when the schema actually spoke.
	 *
	 * @param Schema $schema The schema whose list surface is being rendered.
	 *
	 * @return array{declared: bool, columns: array<int, array>, searchFields: array<int, string>}
	 *                                                                                            The list surface.
	 *
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function resolve(Schema $schema): array {
		$declaration = $schema->getListPresentation();
		$properties = ($schema->getProperties() ?? []);

		if ($declaration['columns'] === [] && $declaration['searchFields'] === []) {
			return self::UNDECLARED;
		}

		return [
			'declared' => true,
			'columns' => $this->declaredColumns(columns: $declaration['columns'], properties: $properties),
			'searchFields' => $declaration['searchFields'],
		];
	}//end resolve()

	/**
	 * The declared columns described against the schema's properties, or the defaults when none are declared.
	 *
	 * @param array<int, mixed>    $columns    The declared columns.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<int, array<string, mixed>> The columns.
	 */
	private function declaredColumns(array $columns, array $properties): array {
		$described = [];
		foreach ($columns as $column) {
			$described[] = $this->describeColumn(column: $column, properties: $properties);
		}

		if ($described === []) {
			return self::DEFAULT_COLUMNS;
		}

		return $described;
	}//end declaredColumns()

	/**
	 * Fill a declared column out with what the schema knows about the property.
	 *
	 * The label falls back to the property's own title, then to the property
	 * name itself, so a column is never rendered without a heading.
	 *
	 * @param array $column     The declared column.
	 * @param array $properties The schema's properties.
	 *
	 * @return array{property: string, label: string, type: string, source: string} The column.
	 */
	private function describeColumn(array $column, array $properties): array {
		$property = $column['property'];
		$definition = ($properties[explode('.', $property)[0]] ?? []);
		if (is_array($definition) === false) {
			$definition = [];
		}

		$label = $this->columnLabel(column: $column, definition: $definition);

		$type = ($definition['type'] ?? 'string');
		if (is_string($type) === false) {
			$type = 'string';
		}

		return [
			'property' => $property,
			'label' => $label,
			'type' => $type,
			'source' => 'property',
		];
	}//end describeColumn()

	/**
	 * The header of a declared column: its own label, the property's title, or the property name.
	 *
	 * @param array $column     The declared column, with its property path.
	 * @param array $definition The property's schema definition.
	 *
	 * @return string The label, never empty.
	 */
	private function columnLabel(array $column, array $definition): string {
		$label = ($column['label'] ?? null);
		if (is_string($label) === false || $label === '') {
			$label = ($definition['title'] ?? null);
		}

		if (is_string($label) === false || $label === '') {
			$label = $column['property'];
		}

		return $label;
	}//end columnLabel()
}//end class
