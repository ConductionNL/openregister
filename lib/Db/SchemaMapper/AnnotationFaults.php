<?php

/**
 * AnnotationFaults: how one schema annotation refuses a save.
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
 * The wording of a refusal, and of the keys an annotation ignores.
 *
 * @spec exclude shared save-time plumbing, not a capability of its own
 */
final class AnnotationFaults {

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
	 * Refuse the save as an invalid declaration, if there are errors.
	 *
	 * "Invalid" is load-bearing: SchemasController maps the exception to a 400
	 * by matching that word, and the codes ride along so a client can tell which
	 * rule refused.
	 *
	 * @param array<int, array{code: string, message: string}> $errors The validator's errors.
	 *
	 * @return void
	 *
	 * @throws Exception When there is an error.
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function refuseInvalid(array $errors): void {
		if ($errors !== []) {
			$details = array_map(static fn (array $err): string => '[' . $err['code'] . '] ' . $err['message'], $errors);
			throw new Exception('Invalid ' . $this->key . ' declaration: ' . implode(' ', $details));
		}
	}//end refuseInvalid()

	/**
	 * The log line about keys the block does not know, which are ignored rather than refused.
	 *
	 * @param array<int, array{message: string}> $warnings The findings about unknown keys.
	 *
	 * @return string
	 *
	 * @spec exclude shared save-time plumbing, not a capability of its own
	 */
	public function unknownKeysNotice(array $warnings): string {
		return sprintf(
			'[OpenRegister.SchemaMapper] Ignored %d unknown %s key(s) on schema "%s": %s',
			count($warnings),
			$this->key,
			(string)($this->schema->getSlug() ?? ''),
			implode(' ', array_map(static fn (array $finding) => $finding['message'], $warnings))
		);
	}//end unknownKeysNotice()

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
