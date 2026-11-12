<?php

/**
 * One pass of CacheHandler deciding which cached names a caller may see.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The candidate names of one visibility decision, the names decided visible, the object names
 * queued per source, and the caller's remembered read answers.
 *
 * Built by CacheHandler::visibleNames() and never handed out of it (ADR-011 Rule 3).
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */
final class NameVisibilityRun {

	/**
	 * Names decided visible so far, by key.
	 *
	 * @var array<string, mixed>
	 */
	public array $visible = [];

	/**
	 * Object keys queued per name source ("<registerId>:<schemaId>").
	 *
	 * @var array<string, list<string>>
	 */
	public array $uuidsBySource = [];

	/**
	 * The caller's remembered read answers, by uuid.
	 *
	 * @var array<string, bool>
	 */
	public array $memo = [];

	/**
	 * Constructor for NameVisibilityRun.
	 *
	 * @param array<int|string, string> $candidates Identifier => name, every one already in the name cache.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $candidates,
	) {
	}//end __construct()
}//end class
