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
class ListPresentationResolver {

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
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function resolve(Schema $schema): array {
		$declaration = $schema->getListPresentation();
		$properties = ($schema->getProperties() ?? []);

		if ($declaration['columns'] === [] && $declaration['searchFields'] === []) {
			return [
				'declared' => false,
				'columns' => self::DEFAULT_COLUMNS,
				'searchFields' => [],
			];
		}

		$columns = [];
		foreach ($declaration['columns'] as $column) {
			$columns[] = $this->describeColumn(column: $column, properties: $properties);
		}

		if ($columns === []) {
			$columns = self::DEFAULT_COLUMNS;
		}

		return [
			'declared' => true,
			'columns' => $columns,
			'searchFields' => $declaration['searchFields'],
		];
	}//end resolve()

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

		$label = ($column['label'] ?? null);
		if (is_string($label) === false || $label === '') {
			$label = ($definition['title'] ?? null);
		}

		if (is_string($label) === false || $label === '') {
			$label = $property;
		}

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
}//end class
