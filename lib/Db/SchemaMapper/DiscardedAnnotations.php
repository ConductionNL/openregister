<?php

/**
 * DiscardedAnnotations: the advisory annotations a schema save ignored.
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
 *
 * @spec openspec/changes/aggregation-dialect-repair/specs/annotation-validation-surfacing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;

/**
 * Collects, per annotation family, the validation errors of an advisory annotation
 * a schema save logged and ignored, so the save can return them as `warnings`.
 *
 * Recording changes nothing about the save: the annotation is still ignored and the
 * log line is still written by the check that found the fault.
 *
 * @spec openspec/changes/aggregation-dialect-repair/specs/annotation-validation-surfacing/spec.md
 */
final class DiscardedAnnotations {

	/**
	 * The prefix of an annotation key, left off the family name.
	 */
	private const PREFIX = 'x-openregister-';

	/**
	 * The entries recorded, in order.
	 *
	 * @var list<array{family: string, schema: string, messages: list<string>}>
	 */
	private array $entries = [];

	/**
	 * Record the errors of one annotation family on a schema; no errors records nothing.
	 *
	 * @param string                                      $family The annotation key or family name.
	 * @param Schema                                      $schema The schema being saved.
	 * @param array<int, array{message: string, code?: string}> $errors The validator's errors.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/specs/annotation-validation-surfacing/spec.md
	 */
	public function record(string $family, Schema $schema, array $errors): void {
		if ($errors === []) {
			return;
		}

		if (str_starts_with($family, self::PREFIX) === true) {
			$family = substr($family, strlen(self::PREFIX));
		}

		$this->entries[] = [
			'family' => $family,
			'schema' => (string)($schema->getSlug() ?? ''),
			'messages' => array_values(array_map(static fn (array $err): string => (string)($err['message'] ?? ''), $errors)),
		];
	}//end record()

	/**
	 * The entries recorded, one per discarded family, in the order they were found.
	 *
	 * @return list<array{family: string, schema: string, messages: list<string>}>
	 *
	 * @spec openspec/changes/aggregation-dialect-repair/specs/annotation-validation-surfacing/spec.md
	 */
	public function all(): array {
		return $this->entries;
	}//end all()
}//end class
