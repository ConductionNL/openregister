<?php

/**
 * SchemaAnnotation: one configuration annotation of a schema being saved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;

/**
 * One configuration key of a schema: whether it is there, and the shapes the validators read.
 *
 * @spec exclude shared save-time plumbing, not a capability of its own
 */
final class SchemaAnnotation {

	/**
	 * Constructor.
	 *
	 * @param Schema $schema The schema being saved.
	 * @param string $key    The configuration key.
	 */
	public function __construct(
		private readonly Schema $schema,
		private readonly string $key,
	) {
	}//end __construct()

	/**
	 * The stored value, or null when the key is absent.
	 *
	 * @return mixed
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function value(): mixed {
		return $this->configured(key: $this->key);
	}//end value()

	/**
	 * The value of a sibling configuration key, or null when it is absent.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return mixed
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function configured(string $key): mixed {
		return (($this->schema->getConfiguration() ?? [])[$key] ?? null);
	}//end configured()

	/**
	 * Whether the annotation is written as an object or list.
	 *
	 * @return bool
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function isBlock(): bool {
		return is_array($this->value());
	}//end isBlock()

	/**
	 * The annotation as an array, or empty when it is written as anything else.
	 *
	 * @return array<mixed>
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function block(): array {
		if ($this->isBlock() === true) {
			return $this->value();
		}

		return [];
	}//end block()

	/**
	 * Whether the annotation holds any value but null.
	 *
	 * @return bool
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function hasValue(): bool {
		return $this->value() !== null;
	}//end hasValue()

	/**
	 * Whether the key is written at all, a null value included.
	 *
	 * @return bool
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function isWritten(): bool {
		return array_key_exists($this->key, ($this->schema->getConfiguration() ?? []));
	}//end isWritten()

	/**
	 * The annotation beside the schema's properties, as most validators read it.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function shape(): array {
		return ['properties' => $this->schema->getProperties(), $this->key => $this->value()];
	}//end shape()

	/**
	 * How a log line names this annotation.
	 *
	 * @return string
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function label(): string {
		return $this->key . ' annotation on schema "' . ((string)($this->schema->getSlug() ?? '')) . '"';
	}//end label()

	/**
	 * How this annotation refuses a save and reports what it ignores.
	 *
	 * @return AnnotationFaults
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function faults(): AnnotationFaults {
		return new AnnotationFaults(schema: $this->schema, key: $this->key);
	}//end faults()
}//end class
