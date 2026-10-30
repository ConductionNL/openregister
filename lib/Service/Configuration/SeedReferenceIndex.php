<?php

/**
 * The `@ref:` seed-reference tokens of one import and the UUIDs they resolve to.
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
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use Psr\Log\LoggerInterface;

/**
 * Collects the slugs seed objects reference, indexes the UUID assigned to
 * each referenced target and replaces the tokens with those UUIDs.
 *
 * A token spans a whole string value, as `@ref:<slug>` (resolved by slug,
 * which must be unambiguous) or `@ref:<schema-slug>:<slug>` (resolved by
 * schema and slug). A token that does not resolve is left as it is and
 * logged, so schema validation reports it.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class SeedReferenceIndex
{

	private const TOKEN_PREFIX = '@ref:';

	/**
	 * Referenced bare slugs, as "objectSlug" => true.
	 *
	 * @var array<string, true>
	 */
	private array $referencedSlugs = [];

	/**
	 * Referenced qualified slugs, as "schemaSlug:objectSlug" => true.
	 *
	 * @var array<string, true>
	 */
	private array $referencedSchemaKeys = [];

	/**
	 * Assigned UUIDs by "schemaSlug:objectSlug".
	 *
	 * @var array<string, mixed>
	 */
	private array $uuidBySchemaSlug = [];

	/**
	 * Assigned UUIDs by object slug.
	 *
	 * @var array<string, mixed>
	 */
	private array $uuidBySlug = [];

	/**
	 * Slugs that resolve to more than one UUID.
	 *
	 * @var array<string, true>
	 */
	private array $ambiguousSlugs = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives the unresolved-token warnings.
	 */
	public function __construct(
		private readonly LoggerInterface $logger
	) {
	}//end __construct()

	/**
	 * Record the slugs referenced by a list of seed objects.
	 *
	 * @param array $objects The seed objects; entries that are not arrays are skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function collectFromObjects(array $objects): void {
		foreach ($objects as $objectData) {
			if (is_array($objectData) === true) {
				$this->collect(value: $objectData);
			}
		}
	}//end collectFromObjects()

	/**
	 * Replace the tokens in every seed object of a list.
	 *
	 * @param array $objects The seed objects; entries that are not arrays stay as they are.
	 *
	 * @return array The list with its tokens replaced.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function replaceInObjects(array $objects): array {
		foreach ($objects as $position => $objectData) {
			if (is_array($objectData) === true) {
				$objects[$position] = $this->replaceOutsideSelf(objectData: $objectData);
			}
		}

		return $objects;
	}//end replaceInObjects()

	/**
	 * Record the slugs referenced anywhere in a value.
	 *
	 * @param mixed $value A seed object or one of its values.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function collect(mixed $value): void {
		if (is_array($value) === true) {
			foreach ($value as $item) {
				$this->collect(value: $item);
			}

			return;
		}

		if (is_string($value) === false || str_starts_with($value, self::TOKEN_PREFIX) === false) {
			return;
		}

		$reference = substr($value, strlen(self::TOKEN_PREFIX));
		if (str_contains($reference, ':') === true) {
			$this->referencedSchemaKeys[$reference] = true;
			return;
		}

		$this->referencedSlugs[$reference] = true;
	}//end collect()

	/**
	 * Whether no collected value references anything.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function isEmpty(): bool {
		return count($this->referencedSlugs) === 0 && count($this->referencedSchemaKeys) === 0;
	}//end isEmpty()

	/**
	 * Whether a seed object, by its `@self`, is the target of a reference.
	 *
	 * @param mixed $self The object's `@self` block.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function isReferenced(mixed $self): bool {
		$objectSlug = $self['slug'] ?? null;
		if (empty($objectSlug) === true) {
			return false;
		}

		$schemaSlugKey = self::schemaSlugKey(self: $self);
		return array_key_exists($objectSlug, $this->referencedSlugs) === true
			|| ($schemaSlugKey !== null && array_key_exists($schemaSlugKey, $this->referencedSchemaKeys) === true);
	}//end isReferenced()

	/**
	 * Index the UUID assigned to a referenced target.
	 *
	 * A slug that already maps to a different UUID becomes ambiguous.
	 *
	 * @param array $self The target's `@self` block, with `slug` and `id` set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function record(array $self): void {
		$objectSlug = $self['slug'];
		$uuid = $self['id'];

		$schemaSlugKey = self::schemaSlugKey(self: $self);
		if ($schemaSlugKey !== null) {
			$this->uuidBySchemaSlug[$schemaSlugKey] = $uuid;
		}

		if (array_key_exists($objectSlug, $this->uuidBySlug) === true && $this->uuidBySlug[$objectSlug] !== $uuid) {
			$this->ambiguousSlugs[$objectSlug] = true;
			return;
		}

		$this->uuidBySlug[$objectSlug] = $uuid;
	}//end record()

	/**
	 * Replace the tokens in a seed object's values, leaving `@self` as it is.
	 *
	 * `@self` moves to the end of the object; a null `@self` is dropped.
	 *
	 * @param array $objectData The seed object.
	 *
	 * @return array The object with its tokens replaced.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function replaceOutsideSelf(array $objectData): array {
		$self = $objectData['@self'] ?? null;
		unset($objectData['@self']);

		$objectData = $this->replace(value: $objectData);

		if ($self !== null) {
			$objectData['@self'] = $self;
		}

		return $objectData;
	}//end replaceOutsideSelf()

	/**
	 * Replace every token in a value with its UUID.
	 *
	 * @param mixed $value A scalar, list or map.
	 *
	 * @return mixed The value with its tokens replaced.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function replace(mixed $value): mixed {
		if (is_array($value) === true) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->replace(value: $item);
			}

			return $value;
		}

		if (is_string($value) === false || str_starts_with($value, self::TOKEN_PREFIX) === false) {
			return $value;
		}

		return $this->resolve(token: $value);
	}//end replace()

	/**
	 * Resolve one token, or log why it stays as it is.
	 *
	 * @param string $token The `@ref:` token.
	 *
	 * @return mixed The UUID, or the token when it does not resolve.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function resolve(string $token): mixed {
		$reference = substr($token, strlen(self::TOKEN_PREFIX));
		$schemaSlug = null;
		$objectSlug = $reference;
		if (str_contains($reference, ':') === true) {
			[$schemaSlug, $objectSlug] = explode(':', $reference, 2);
		}

		if ($schemaSlug !== null && $schemaSlug !== '') {
			return $this->uuidBySchemaSlug[$schemaSlug.':'.$objectSlug]
				?? $this->unresolved(token: $token, reason: 'no imported object for that schema+slug.');
		}

		if (array_key_exists($objectSlug, $this->ambiguousSlugs) === true) {
			$this->logger->warning(
				message: '[ImportHandler] Ambiguous seed reference "'.$token.'"; slug exists in '
					.'multiple schemas — use @ref:<schema>:<slug>. Left unresolved.',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return $token;
		}

		return $this->uuidBySlug[$objectSlug]
			?? $this->unresolved(token: $token, reason: 'no imported object with that slug.');
	}//end resolve()

	/**
	 * Log an unresolved token and return it unchanged.
	 *
	 * @param string $token  The token.
	 * @param string $reason Why it did not resolve.
	 *
	 * @return string The token.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function unresolved(string $token, string $reason): string {
		$this->logger->warning(
			message: '[ImportHandler] Unresolved seed reference "'.$token.'" — '.$reason,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $token;
	}//end unresolved()

	/**
	 * The "schemaSlug:objectSlug" key of a `@self` block, or null without a schema slug.
	 *
	 * @param mixed $self The `@self` block.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private static function schemaSlugKey(mixed $self): ?string {
		$schemaSlug = $self['schema'] ?? null;
		if (is_string($schemaSlug) === true && $schemaSlug !== '') {
			return $schemaSlug.':'.$self['slug'];
		}

		return null;
	}//end schemaSlugKey()
}//end class
