<?php

/**
 * OpenRegister NameLookupRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * The state of one CacheHandler::getMultipleObjectNames() call while its names are collected.
 *
 * Every name found, cached or loaded, becomes a candidate; who may see it is decided afterwards.
 * An identifier stays missing until a cache layer or a load has established its name.
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */
final class NameLookupRun {

	/**
	 * Identifier => name of every name found so far.
	 *
	 * @var array<string, string>
	 */
	public array $candidates = [];

	/**
	 * Identifiers whose name is not established yet.
	 *
	 * @var array<int|string, string>
	 */
	public array $missing = [];
}//end class
