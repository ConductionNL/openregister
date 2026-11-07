<?php

/**
 * OpenRegister ShownEntity
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;


use OCA\OpenRegister\Db\ObjectEntity;

/**
 * An object show() found, with the extensions its reader asked for.
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */
final class ShownEntity {
	/**
	 * The extensions asked for.
	 *
	 * @var array<int, string>
	 */
	public readonly array $extend;

	/**
	 * Hold the found object.
	 *
	 * @param ObjectShow   $show   The read and its resolved scope.
	 * @param ObjectEntity $entity The object.
	 * @param mixed        $extend The normalised extensions; anything but a list means none.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function __construct(
		public readonly ObjectShow $show,
		public readonly ObjectEntity $entity,
		mixed $extend,
	) {
		$list = [];
		if (is_array($extend) === true) {
			$list = $extend;
		}

		$this->extend = $list;
	}//end __construct()

	/**
	 * What `_extend` adds to `@self`: the `registers` and `schemas` maps and the extended objects.
	 *
	 * @return array<string, array<int|string, mixed>> The extras asked for.
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function resolvedExtras(): array {
		$extras = [];
		if ($this->asks(extension: '_register') === true) {
			$extras['registers'] = $this->entityMap(id: 'register', entity: 'registerEntity');
		}

		if ($this->asks(extension: '_schema') === true) {
			$extras['schemas'] = $this->entityMap(id: 'schema', entity: 'schemaEntity');
		}

		if ($this->extend !== []) {
			$extras['objects'] = $this->show->services->objects->getExtendedObjects();
		}

		return $extras;
	}//end resolvedExtras()

	/**
	 * Whether `_extend` names this extension, in the singular or the plural.
	 *
	 * @param string $extension The singular name.
	 *
	 * @return bool True when asked for.
	 */
	private function asks(string $extension): bool {
		return in_array($extension, $this->extend, true) === true
			|| in_array($extension . 's', $this->extend, true) === true;
	}//end asks()

	/**
	 * One resolved entity, keyed by its id.
	 *
	 * @param string $id     The key of the id in the resolved set.
	 * @param string $entity The key of the entity in the resolved set.
	 *
	 * @return array<int|string, mixed> The map, empty without an entity.
	 */
	private function entityMap(string $id, string $entity): array {
		if ($this->show->resolved[$entity] === null) {
			return [];
		}

		return [$this->show->resolved[$id] => $this->show->resolved[$entity]->jsonSerialize()];
	}//end entityMap()
}//end class
