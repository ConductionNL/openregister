<?php

/**
 * ObjectSourceDeclaration: the object source a schema declares.
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
 * Reads which ObjectSourceProvider serves a schema's objects instead of the magic table.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class ObjectSourceDeclaration {

	/**
	 * Get the object-source declaration from the schema configuration.
	 *
	 * Reads the `x-openregister-object-source` annotation block (folded into the
	 * configuration column at save time). When present and valid, the schema's
	 * objects are served by the named ObjectSourceProvider instead of the magic
	 * table (see GetObject). Returns null when absent or when `provider` is not a
	 * non-empty string.
	 *
	 * A schema declaring `x-openregister-view: {view: <id or uuid>}` and no object
	 * source is served by the read-only `view` provider.
	 *
	 * @param array|null $configuration The schema's configuration.
	 *
	 * @return array{provider: string, readOnly?: bool, config?: array}|null
	 *                                                                       The parsed object-source declaration, or null when absent/invalid.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-2.2
	 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
	 */
	public function declared(?array $configuration): ?array {
		$source = ($configuration['x-openregister-object-source'] ?? null);

		// A type backed by a saved view (x-openregister-view) reads through the
		// built-in `view` provider and is never writable
		// (modelling-query-backed-type). An explicit object source wins.
		if (is_array($source) === false) {
			return $this->viewSource(configuration: $configuration);
		}

		if ($this->namesProvider(source: $source) === false) {
			return null;
		}

		return $source;
	}//end declared()

	/**
	 * Whether a declared object source names its provider as a non-empty string.
	 *
	 * @param array $source The declared object source.
	 *
	 * @return bool
	 */
	private function namesProvider(array $source): bool {
		$provider = ($source['provider'] ?? null);

		return is_string($provider) === true && $provider !== '';
	}//end namesProvider()

	/**
	 * The read-only `view` source of a type backed by a saved view; null without one.
	 *
	 * @param array|null $configuration The schema's configuration.
	 *
	 * @return array{provider: string, config: array, readOnly: bool}|null
	 */
	private function viewSource(?array $configuration): ?array {
		$view = ($configuration['x-openregister-view']['view'] ?? null);
		if ($this->isViewReference(view: $view) === true) {
			return ['provider' => 'view', 'config' => ['view' => $view], 'readOnly' => true];
		}

		return null;
	}//end viewSource()

	/**
	 * Whether a value references a saved view: a non-empty uuid string or an id.
	 *
	 * @param mixed $view The declared view.
	 *
	 * @return bool
	 */
	private function isViewReference(mixed $view): bool {
		return (is_string($view) === true && $view !== '') || is_int($view) === true;
	}//end isViewReference()
}//end class
