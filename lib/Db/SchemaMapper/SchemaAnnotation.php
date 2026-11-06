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

use Exception;
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
		return ['properties' => ($this->schema->getProperties() ?? []), $this->key => $this->value()];
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
	 * Refuse the save with the messages of the given errors, if there are any.
	 *
	 * @param array<int, array{message: string}> $errors The validator's errors.
	 *
	 * @return void
	 *
	 * @throws Exception When there is an error.
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function refuse(array $errors): void {
		$this->refuseWith(messages: array_map(static fn (array $err): string => $err['message'], $errors));
	}//end refuse()

	/**
	 * Refuse the save with the codes and messages of the given errors, if there are any.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The validator's errors.
	 *
	 * @return void
	 *
	 * @throws Exception When there is an error.
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function refuseCoded(array $errors): void {
		$this->refuseWith(
			messages: array_map(static fn (array $err): string => $err['code'] . ': ' . $err['message'], $errors)
		);
	}//end refuseCoded()

	/**
	 * Refuse the save with the given messages, if there are any.
	 *
	 * @param array<int, string> $messages The reasons.
	 *
	 * @return void
	 *
	 * @throws Exception When there is a message.
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function refuseWith(array $messages): void {
		if ($messages !== []) {
			throw new Exception($this->key . ': ' . implode(' ', $messages));
		}
	}//end refuseWith()
}//end class
