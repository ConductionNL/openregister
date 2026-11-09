<?php

/**
 * OpenRegister DeletionCandidate
 *
 * The record an erasure or a cascade delete has reached, as the archival
 * retention guard judges it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * One record a delete reached: its uuid, the schema and register it carries, and the operation.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class DeletionCandidate {

	/**
	 * Constructor.
	 *
	 * @param string          $uuid      Record UUID.
	 * @param string|int|null $schema    Schema identifier carried on the record.
	 * @param string|int|null $register  Register identifier carried on the record, when known.
	 * @param string          $operation One of the ArchivalRetentionGuard::CONTEXT_* constants.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string|int|null $schema,
		public readonly string|int|null $register,
		public readonly string $operation,
	) {
	}//end __construct()

	/**
	 * The schema identifier as the record carries it, or `unknown` when it carries none.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function schemaAsCarried(): string {
		return (string)($this->schema ?? 'unknown');
	}//end schemaAsCarried()
}//end class
