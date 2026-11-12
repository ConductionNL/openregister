<?php

/**
 * Repairs the $ref and allOf use of a schema inside a generated OpenAPI document.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * One repair pass over the schemas of a document.
 *
 * @spec openspec/specs/oas-validation/spec.md
 */
class OasReferenceRepair {

	/**
	 * The prefix of a reference to a component schema.
	 */
	private const COMPONENT_PREFIX = '#/components/schemas/';

	/**
	 * Constructor.
	 *
	 * @param list<string>        $componentNames The component schemas of the document
	 * @param OasValidationReport $report         Receives what was repaired
	 */
	public function __construct(
		private readonly array $componentNames,
		private readonly OasValidationReport $report,
	) {
	}//end __construct()

	/**
	 * Repair the component schemas of a document in place, and check its response schemas.
	 *
	 * The response schemas are checked on a copy: the document keeps them as they are.
	 *
	 * @param array<string, mixed> $oas The document
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-pagination-structure-follows-api-42
	 */
	public function repairDocument(array &$oas): void {
		foreach (array_keys($oas['components']['schemas'] ?? []) as $schemaName) {
			if (is_array($oas['components']['schemas'][$schemaName]) === true) {
				$this->repair(schema: $oas['components']['schemas'][$schemaName], context: 'components.schemas.' . $schemaName);
			}
		}

		foreach (($oas['paths'] ?? []) as $pathName => $pathItem) {
			foreach ($pathItem as $method => $operation) {
				$this->checkResponses(operation: $operation, context: 'paths.' . $pathName . '.' . $method);
			}
		}
	}//end repairDocument()

	/**
	 * Check the JSON response schemas of one operation.
	 *
	 * @param mixed  $operation The operation.
	 * @param string $context   The operation's path, for the report
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-pagination-structure-follows-api-42
	 */
	private function checkResponses(mixed $operation, string $context): void {
		if (is_array($operation) === false || isset($operation['responses']) === false) {
			return;
		}

		foreach (array_keys($operation['responses']) as $statusCode) {
			$respSchema = ($operation['responses'][$statusCode]['content']['application/json']['schema'] ?? null);
			if ($respSchema !== null) {
				$this->repair(schema: $respSchema, context: $context . '.responses.' . $statusCode);
			}
		}
	}//end checkResponses()

	/**
	 * Repair a schema and the schemas nested in it, in place.
	 *
	 * @param array<string, mixed> $schema  The schema
	 * @param string               $context The schema's path, for the report
	 *
	 * @return void
	 *
	 * @spec openspec/specs/oas-validation/spec.md#scenario-pagination-structure-follows-api-42
	 */
	public function repair(array &$schema, string $context): void {
		$this->repairAllOf(schema: $schema, context: $context);
		$this->repairRef(schema: $schema, context: $context);
		$this->repairProperties(schema: $schema, context: $context);
		if (is_array($schema['items'] ?? null) === true) {
			$this->repair(schema: $schema['items'], context: "{$context}.items");
		}

		foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
			if (is_array($schema[$keyword] ?? null) === true) {
				$this->repairComposition(items: $schema[$keyword], context: "{$context}.{$keyword}");
			}
		}
	}//end repair()

	/**
	 * Keep only the allOf items that carry a $ref, a type or properties; drop an allOf left empty.
	 *
	 * @param array<string, mixed> $schema  The schema
	 * @param string               $context The schema's path, for the report
	 *
	 * @return void
	 */
	private function repairAllOf(array &$schema, string $context): void {
		if (isset($schema['allOf']) === false) {
			return;
		}

		$usable = $this->usableAllOfItems(allOf: $schema['allOf']);
		if ($usable !== []) {
			$schema['allOf'] = $usable;
			return;
		}

		if (is_array($schema['allOf']) === true && empty($schema['allOf']) === false) {
			$this->reportRemovedAllOf(context: $context);
		}

		unset($schema['allOf']);
	}//end repairAllOf()

	/**
	 * Report an allOf removed for having no usable item.
	 *
	 * @param string $context The schema's path
	 *
	 * @return void
	 */
	private function reportRemovedAllOf(string $context): void {
		$this->report->addAutoCorrection(
			issue: new OasIssue(
				path: $context . '.allOf',
				message: 'allOf with no valid items was removed.',
				code: OasValidationReport::CODE_INVALID_ALLOF
			)
		);
	}//end reportRemovedAllOf()

	/**
	 * The usable items of an allOf value.
	 *
	 * @param mixed $allOf The allOf value
	 *
	 * @return list<array<int|string, mixed>> The items
	 */
	private function usableAllOfItems(mixed $allOf): array {
		if (is_array($allOf) === false) {
			return [];
		}

		return array_values(
			array_filter($allOf, fn ($item): bool => is_array($item) === true && $this->isUsableAllOfItem(item: $item))
		);
	}//end usableAllOfItems()

	/**
	 * Whether an allOf item carries a non-empty string $ref, a type or properties.
	 *
	 * @param array<int|string, mixed> $item The item
	 *
	 * @return bool
	 */
	private function isUsableAllOfItem(array $item): bool {
		if (empty($item['$ref']) === false && is_string($item['$ref']) === true) {
			return true;
		}

		return ($item['type'] ?? null) !== null || ($item['properties'] ?? null) !== null;
	}//end isUsableAllOfItem()

	/**
	 * Repair a $ref: drop an empty or non-string one, then resolve one that names a component.
	 *
	 * @param array<string, mixed> $schema  The schema
	 * @param string               $context The schema's path, for the report
	 *
	 * @return void
	 */
	private function repairRef(array &$schema, string $context): void {
		if (($schema['$ref'] ?? null) === null) {
			return;
		}

		if (empty($schema['$ref']) === true || is_string($schema['$ref']) === false) {
			unset($schema['$ref']);
			return;
		}

		if (str_starts_with($schema['$ref'], self::COMPONENT_PREFIX) === true) {
			$this->resolveComponentRef(schema: $schema, context: $context);
		}
	}//end repairRef()

	/**
	 * Match a component reference ignoring case, or replace it when nothing matches.
	 *
	 * @param array<string, mixed> $schema  The schema
	 * @param string               $context The schema's path, for the report
	 *
	 * @return void
	 */
	private function resolveComponentRef(array &$schema, string $context): void {
		$refPath = str_replace(self::COMPONENT_PREFIX, '', $schema['$ref']);
		if (in_array($refPath, $this->componentNames, true) === true) {
			return;
		}

		$existingName = $this->componentNamedLike(refPath: $refPath);
		if ($existingName !== null) {
			$schema['$ref'] = self::COMPONENT_PREFIX . $existingName;
			return;
		}

		$this->replaceDanglingRef(schema: $schema, context: $context);
	}//end resolveComponentRef()

	/**
	 * Replace a $ref to a missing component with type=string and report it.
	 *
	 * @param array<string, mixed> $schema  The schema
	 * @param string               $context The schema's path, for the report
	 *
	 * @return void
	 */
	private function replaceDanglingRef(array &$schema, string $context): void {
		$refPath = str_replace(self::COMPONENT_PREFIX, '', $schema['$ref']);
		unset($schema['$ref']);
		if (isset($schema['type']) === false) {
			$schema['type'] = 'string';
			$schema['description'] = $schema['description'] ?? 'Reference to ' . $refPath;
		}

		$this->report->addError(
			issue: new OasIssue(
				path: $context . '.$ref',
				message: 'Dangling $ref to "' . self::COMPONENT_PREFIX . $refPath . '"; substituted with type=string.',
				code: OasValidationReport::CODE_DANGLING_REF
			)
		);
	}//end replaceDanglingRef()

	/**
	 * The component whose name matches ignoring case, if any.
	 *
	 * @param string $refPath The referenced component name
	 *
	 * @return string|null The existing name
	 */
	private function componentNamedLike(string $refPath): ?string {
		foreach ($this->componentNames as $existingName) {
			if (strtolower($existingName) === strtolower($refPath)) {
				return $existingName;
			}
		}

		return null;
	}//end componentNamedLike()

	/**
	 * Repair the schemas under `properties`.
	 *
	 * @param array<string, mixed> $schema  The schema
	 * @param string               $context The schema's path, for the report
	 *
	 * @return void
	 */
	private function repairProperties(array &$schema, string $context): void {
		if (($schema['properties'] ?? null) === null) {
			return;
		}

		foreach ($schema['properties'] as $propName => &$property) {
			if (is_array($property) === true) {
				$this->repair(schema: $property, context: "{$context}.properties.{$propName}");
			}
		}

		unset($property);
	}//end repairProperties()

	/**
	 * Repair each schema of a oneOf, anyOf or allOf list.
	 *
	 * @param array<int|string, mixed> $items   The list
	 * @param string                   $context The list's path, for the report
	 *
	 * @return void
	 */
	private function repairComposition(array &$items, string $context): void {
		foreach ($items as $idx => &$item) {
			if (is_array($item) === true) {
				$this->repair(schema: $item, context: "{$context}[{$idx}]");
			}
		}

		unset($item);
	}//end repairComposition()
}//end class
