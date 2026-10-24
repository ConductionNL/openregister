<?php

/**
 * A property that is shown masked until the reader asks to see it.
 *
 * A property whose authorization block says `"reveal": "explicit"` (a BSN) is
 * answered on the objects API as its mask, "*****7609", and only a read that
 * names it in `_reveal` gets the value. That read is the reveal: it passes the
 * property's read rule like any other read, and an audited property records it
 * through the RevealCollector. A page that shows the mask has not shown the
 * number, so a masked read records nothing.
 *
 * The masking is scoped to the objects API ({@see open()}, called by
 * ExplicitRevealMiddleware for ObjectsController). An app reading through
 * ObjectService on the server keeps the value it needs to work with.
 *
 * Writing back what a reader was shown must not overwrite the value with its
 * mask: {@see restoreMasked()} puts the stored value back where an update
 * sends the mask of it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;

/**
 * Masks explicit-reveal properties on the objects API and restores them on write.
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */
class ExplicitReveal {

	/**
	 * The authorization key and value that make a property explicit-reveal.
	 */
	public const KEY = 'reveal';

	public const EXPLICIT = 'explicit';

	/**
	 * How many characters of the value the mask keeps.
	 */
	private const VISIBLE = 4;

	/**
	 * Whether this request reads through the objects API.
	 *
	 * @var bool
	 */
	private bool $active = false;

	/**
	 * The properties this request asks to see.
	 *
	 * @var array<string, true>
	 */
	private array $requested = [];

	/**
	 * Start masking for this request, revealing the named properties.
	 *
	 * @param string $reveal The `_reveal` parameter: property names, comma separated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function open(string $reveal = ''): void {
		$this->active = true;
		$this->requested = [];
		foreach (explode(',', $reveal) as $name) {
			$name = trim($name);
			if ($name !== '') {
				$this->requested[$name] = true;
			}
		}
	}//end open()

	/**
	 * Whether masking applies to this request.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function isActive(): bool {
		return $this->active;
	}//end isActive()

	/**
	 * Whether this request asks to see the property.
	 *
	 * @param string $property The property name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function isRequested(string $property): bool {
		return isset($this->requested[$property]);
	}//end isRequested()

	/**
	 * Whether a property's authorization block makes it explicit-reveal.
	 *
	 * @param mixed $rules The property's authorization block.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function isExplicit(mixed $rules): bool {
		return is_array($rules) === true && ($rules[self::KEY] ?? null) === self::EXPLICIT;
	}//end isExplicit()

	/**
	 * The explicit-reveal properties of a schema.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array<int, string> The property names.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function explicitProperties(Schema $schema): array {
		if ($schema->hasPropertyAuthorization() === false) {
			return [];
		}

		$names = [];
		foreach ($schema->getPropertiesWithAuthorization() as $name => $rules) {
			if ($this->isExplicit(rules: $rules) === true) {
				$names[] = (string)$name;
			}
		}

		return $names;
	}//end explicitProperties()

	/**
	 * The mask of a value: its last four characters behind five stars.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The mask, '' for an empty value.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function mask(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		$text = (string)$value;
		if ($text === '') {
			return '';
		}

		return str_repeat('*', 5) . substr($text, -self::VISIBLE);
	}//end mask()

	/**
	 * Put a stored value back where an update sends its mask.
	 *
	 * @param Schema               $schema   The schema.
	 * @param array<string, mixed> $incoming The update payload.
	 * @param array<string, mixed> $stored   The stored object, unrendered.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function restoreMasked(Schema $schema, array $incoming, array $stored): array {
		foreach ($this->explicitProperties(schema: $schema) as $name) {
			if (array_key_exists($name, $incoming) === false || array_key_exists($name, $stored) === false) {
				continue;
			}

			$mask = $this->mask(value: $stored[$name]);
			if ($mask !== '' && $incoming[$name] === $mask) {
				$incoming[$name] = $stored[$name];
			}
		}

		return $incoming;
	}//end restoreMasked()
}//end class
