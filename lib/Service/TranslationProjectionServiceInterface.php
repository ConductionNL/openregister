<?php

/**
 * TranslationProjectionServiceInterface: the part of TranslationProjectionService that the projection listener and background job use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The methods of TranslationProjectionService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface TranslationProjectionServiceInterface {

	/**
	 * Project the given object's translatable property data into the sidecar.
	 *
	 * Behaviour:
	 *  - For each translatable property declared on the schema, walk
	 *    the object's data shape `{lang: value, ...}` (or fall back to
	 *    a single-language string treated as the register's default).
	 *  - Upsert one row per (uuid, property, language) that has a
	 *    non-empty value.
	 *  - Delete any pre-existing row whose (property, language) no
	 *    longer has a value on the object.
	 *
	 * Status defaults to `draft` on first projection; subsequent saves
	 * preserve whatever status the slot already had (the projection
	 * doesn't second-guess workflow state — that's `TranslationStatusService`'s job).
	 *
	 * @param ObjectEntity $object The object to project.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function project(ObjectEntity $object): void;

	/**
	 * Drop every translation row for the given object.
	 *
	 * @param ObjectEntity $object The object to purge translations for.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function purge(ObjectEntity $object): void;
}//end interface
