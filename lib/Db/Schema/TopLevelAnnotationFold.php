<?php

/**
 * TopLevelAnnotationFold: the top-level annotations hydrate() folds into the configuration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;


/**
 * Folds top-level `x-openregister-*` blocks and a top-level `x-schema-org` into `configuration`.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class TopLevelAnnotationFold {

	/**
	 * Fold top-level annotation blocks into the `configuration` array.
	 *
	 * Fold top-level `x-openregister-*` annotation blocks (e.g. the
	 * OpenAPI seed's sibling-of-properties `x-openregister-lifecycle`
	 * declaration) into the `configuration` array BEFORE the per-key
	 * setter loop runs. Without this the keys fall through to a
	 * non-existent `setXOpenregisterLifecycle()` setter, the silent-
	 * catch in hydrate() swallows the call, and the annotation is dropped on
	 * the floor — so OR's TransitionEngine never sees a state machine
	 * and every transition 422s ("schema does not declare
	 * x-openregister-lifecycle"; openregister#1520).
	 *
	 * Fold a schema-LEVEL top-level `x-schema-org` marker into
	 * `configuration['x-schema-org']` the same way (ADR-048). Fleet
	 * schemas declare the canonical schema.org CURIE (e.g.
	 * `"x-schema-org": "schema:Organization"`) as a sibling of
	 * `properties`, NOT inside `configuration`; without this fold the
	 * marker falls through to a non-existent `setXSchemaOrg()` setter,
	 * is swallowed by the silent-catch in hydrate(), and the live schema loses
	 * its semantic type — so SemanticTypeResolver can never discover the
	 * provider. Folding it into the configuration column (which is in the
	 * passThrough allowlist and read by
	 * JsonLdContextService::getImplementedTypes) makes the marker survive
	 * save/import. An explicit `configuration['x-schema-org']` already
	 * supplied by the caller wins over the top-level convenience form.
	 *
	 * @param array<string, mixed> $object The hydrate input.
	 *
	 * @return array<string, mixed> The input with its annotation blocks folded.
	 */
	public function folded(array $object): array {
		$annotations = $this->annotations(object: $object);
		$object = array_diff_key($object, $annotations);
		$existingConfig = array_replace($this->existingConfiguration(object: $object), $annotations);
		$annotationsFolded = ($annotations !== []);

		if ($this->foldsMarker(object: $object, existingConfig: $existingConfig) === true) {
			$existingConfig['x-schema-org'] = $object['x-schema-org'];
			$annotationsFolded = true;
		}

		unset($object['x-schema-org']);

		if ($annotationsFolded === true) {
			$object['configuration'] = $existingConfig;
		}

		return $object;
	}//end folded()

	/**
	 * The top-level `x-openregister-*` blocks of the input, in input order.
	 *
	 * @param array<int|string, mixed> $object The hydrate input.
	 *
	 * @return array<string, mixed>
	 */
	private function annotations(array $object): array {
		return array_filter(
			$object,
			static fn (int|string $key): bool => is_string($key) === true && str_starts_with($key, 'x-openregister-') === true,
			ARRAY_FILTER_USE_KEY
		);
	}//end annotations()

	/**
	 * The configuration the input carries; [] when it carries none or no array.
	 *
	 * @param array<int|string, mixed> $object The hydrate input.
	 *
	 * @return array<int|string, mixed>
	 */
	private function existingConfiguration(array $object): array {
		$existingConfig = ($object['configuration'] ?? []);
		if (is_array($existingConfig) === false) {
			return [];
		}

		return $existingConfig;
	}//end existingConfiguration()

	/**
	 * Whether a top-level `x-schema-org` folds in: it does unless the configuration names one itself.
	 *
	 * @param array<int|string, mixed> $object         The hydrate input.
	 * @param array<int|string, mixed> $existingConfig The configuration it folds into.
	 *
	 * @return bool
	 */
	private function foldsMarker(array $object, array $existingConfig): bool {
		return array_key_exists('x-schema-org', $object) === true
			&& array_key_exists('x-schema-org', $existingConfig) === false;
	}//end foldsMarker()
}//end class
