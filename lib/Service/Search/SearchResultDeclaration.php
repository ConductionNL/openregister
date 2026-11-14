<?php
/**
 * OpenRegister search result declaration.
 *
 * What a schema says a unified-search result shows: the property holding the
 * title, the property holding the subline and the date property that orders
 * results. A schema that declares none keeps the provider's own derivation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use DateTimeImmutable;
use OCA\OpenRegister\Db\Schema;
use Throwable;

/**
 * The search result a schema declares, read from `x-openregister-search-result`.
 *
 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
 */
final class SearchResultDeclaration {

	/**
	 * The annotation key a schema declares its search result under.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-search-result';

	/**
	 * The keys the annotation takes, each naming a property of the schema.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = ['titleProperty', 'sublineProperty', 'orderDateProperty'];

	/**
	 * Constructor.
	 *
	 * @param string|null $titleProperty     The property holding the title.
	 * @param string|null $sublineProperty   The property holding the subline.
	 * @param string|null $orderDateProperty The date property results are ordered on.
	 */
	private function __construct(
		public readonly ?string $titleProperty,
		public readonly ?string $sublineProperty,
		public readonly ?string $orderDateProperty,
	) {
	}//end __construct()

	/**
	 * The declaration of a schema, or null when it declares nothing.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return self|null The declaration.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
	 */
	public static function of(Schema $schema): ?self {
		$block = (($schema->getConfiguration() ?? [])[self::ANNOTATION] ?? null);
		if (is_array($block) === false) {
			return null;
		}

		$declaration = new self(
			titleProperty: self::property(value: ($block['titleProperty'] ?? null)),
			sublineProperty: self::property(value: ($block['sublineProperty'] ?? null)),
			orderDateProperty: self::property(value: ($block['orderDateProperty'] ?? null))
		);
		if ($declaration->titleProperty === null && $declaration->sublineProperty === null && $declaration->orderDateProperty === null) {
			return null;
		}

		return $declaration;
	}//end of()

	/**
	 * Why a declared block cannot be saved, one message per fault.
	 *
	 * @param mixed                $block      The annotation's value.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return array<int, string> The faults, empty when it can be saved.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
	 */
	public static function faults(mixed $block, array $properties): array {
		if ($block === null) {
			return [];
		}

		if (is_array($block) === false) {
			return [self::ANNOTATION . ' must be an object.'];
		}

		$faults = [];
		foreach (self::KEYS as $key) {
			$named = ($block[$key] ?? null);
			if ($named !== null && (is_string($named) === false || array_key_exists($named, $properties) === false)) {
				$faults[] = self::ANNOTATION . '.' . $key . ' "' . (string)json_encode($named) . '" is not a property of this schema.';
			}
		}

		return $faults;
	}//end faults()

	/**
	 * The declared title of an object, or null when the object leaves it empty.
	 *
	 * @param array<string, mixed> $object The rendered object.
	 *
	 * @return string|null The title.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
	 */
	public function title(array $object): ?string {
		return self::text(object: $object, property: $this->titleProperty);
	}//end title()

	/**
	 * The declared subline of an object, or null when the object leaves it empty.
	 *
	 * @param array<string, mixed> $object The rendered object.
	 *
	 * @return string|null The subline.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
	 */
	public function subline(array $object): ?string {
		return self::text(object: $object, property: $this->sublineProperty);
	}//end subline()

	/**
	 * The declared ordering date of an object as a timestamp, or null when absent or unreadable.
	 *
	 * @param array<string, mixed> $object The rendered object.
	 *
	 * @return int|null The timestamp.
	 *
	 * @spec openspec/changes/platform-search-provider/specs/unified-search-provider/spec.md#requirement-a-schema-declares-its-result-title-subline-and-ordering-date-req-psp-002
	 */
	public function orderDate(array $object): ?int {
		$value = self::text(object: $object, property: $this->orderDateProperty);
		if ($value === null) {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->getTimestamp();
		} catch (Throwable) {
			return null;
		}
	}//end orderDate()

	/**
	 * A trimmed property name, or null.
	 *
	 * @param mixed $value The configured value.
	 *
	 * @return string|null The name.
	 */
	private static function property(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end property()

	/**
	 * A scalar property value as non-empty text, or null.
	 *
	 * @param array<string, mixed> $object   The rendered object.
	 * @param string|null          $property The property.
	 *
	 * @return string|null The text.
	 */
	private static function text(array $object, ?string $property): ?string {
		if ($property === null) {
			return null;
		}

		$value = ($object[$property] ?? null);
		if (is_scalar($value) === false || is_bool($value) === true) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end text()
}//end class
