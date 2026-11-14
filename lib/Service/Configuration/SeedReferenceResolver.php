<?php

/**
 * Resolves the slugs a seed object names in its reference properties to uuids.
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
 * @spec openspec/changes/aggregation-dialect-repair/tasks.md#6-adjacent-repair-a--seed-ref-resolution
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use Closure;
use Psr\Log\LoggerInterface;

/**
 * Seed objects are written without the object save path, so a reference property that
 * names its target by slug used to land as the raw slug. This walks the schema's
 * reference properties (a property with a `$ref`, or an array whose items have one) and
 * replaces each slug with the uuid the lookup finds. A uuid is kept as it is; a slug the
 * lookup cannot find is kept as written, with a warning naming the property and the slug.
 *
 * @spec openspec/changes/aggregation-dialect-repair/tasks.md#6-adjacent-repair-a--seed-ref-resolution
 */
final class SeedReferenceResolver {

	/**
	 * A uuid, any version, any case.
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Constructor.
	 *
	 * @param Closure         $lookup Finds a uuid: fn (string $targetSchema, string $slug): ?string.
	 * @param LoggerInterface $logger Receives the unresolved references.
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/tasks.md#6-adjacent-repair-a--seed-ref-resolution
	 */
	public function __construct(
		private readonly Closure $lookup,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The seed object with its reference slugs replaced by uuids where they resolve.
	 *
	 * @param array<string, mixed> $data       The seed object.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $schemaSlug The schema the object belongs to, for the warning.
	 *
	 * @return array<string, mixed> The object.
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/tasks.md#6-adjacent-repair-a--seed-ref-resolution
	 */
	public function resolve(array $data, array $properties, string $schemaSlug): array {
		foreach ($properties as $name => $property) {
			if (is_array($property) === false || array_key_exists($name, $data) === false) {
				continue;
			}

			$target = $this->scalarTarget(property: $property);
			if ($target !== null) {
				$data[$name] = $this->resolveValue(value: $data[$name], target: $target, where: [$schemaSlug, (string)$name]);
				continue;
			}

			$target = $this->itemsTarget(property: $property);
			if ($target !== null && is_array($data[$name]) === true) {
				$data[$name] = array_map(
					fn (mixed $value): mixed => $this->resolveValue(value: $value, target: $target, where: [$schemaSlug, (string)$name]),
					$data[$name]
				);
			}
		}

		return $data;
	}//end resolve()

	/**
	 * The schema a single reference property points to, or null when it is not one.
	 *
	 * @param array<string, mixed> $property The property.
	 *
	 * @return string|null The target schema reference.
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/tasks.md#6-adjacent-repair-a--seed-ref-resolution
	 */
	private function scalarTarget(array $property): ?string {
		if ($this->referenceOf(value: $property['$ref'] ?? null) === null || ($property['type'] ?? null) === 'array') {
			return null;
		}

		return $this->referenceOf(value: $property['$ref']);
	}//end scalarTarget()

	/**
	 * The schema the items of an array reference property point to, or null when it is not one.
	 *
	 * @param array<string, mixed> $property The property.
	 *
	 * @return string|null The target schema reference.
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/tasks.md#6-adjacent-repair-a--seed-ref-resolution
	 */
	private function itemsTarget(array $property): ?string {
		if (($property['type'] ?? null) !== 'array' || is_array($property['items'] ?? null) === false) {
			return null;
		}

		return $this->referenceOf(value: $property['items']['$ref'] ?? null);
	}//end itemsTarget()

	/**
	 * The schema slug a `$ref` names: its last path segment, or null for no reference.
	 *
	 * @param mixed $value The `$ref` value.
	 *
	 * @return string|null The slug.
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/tasks.md#6-adjacent-repair-a--seed-ref-resolution
	 */
	private function referenceOf(mixed $value): ?string {
		if (is_string($value) === false) {
			return null;
		}

		$segments = explode('/', rtrim($value, '/#'));
		$slug = trim((string)end($segments), '#');

		if ($slug === '') {
			return null;
		}

		return $slug;
	}//end referenceOf()

	/**
	 * One value resolved: a uuid or a non-string stays, a slug becomes the uuid it names.
	 *
	 * @param mixed                $value  The value.
	 * @param string               $target The target schema slug.
	 * @param array{0: string, 1: string} $where  The schema and the property, for the warning.
	 *
	 * @return mixed The value.
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/tasks.md#6-adjacent-repair-a--seed-ref-resolution
	 */
	private function resolveValue(mixed $value, string $target, array $where): mixed {
		if (is_string($value) === false || trim($value) === '' || preg_match(self::UUID, $value) === 1) {
			return $value;
		}

		$uuid = ($this->lookup)($target, $value);
		if (is_string($uuid) === true && $uuid !== '') {
			return $uuid;
		}

		$this->logger->warning(
			message: sprintf(
				'[ImportHandler] Seed reference "%s" on property "%s" of schema "%s" did not resolve to a "%s" object; it was kept as written',
				$value,
				$where[1],
				$where[0],
				$target
			),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $value;
	}//end resolveValue()
}//end class
