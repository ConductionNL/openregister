<?php

/**
 * OpenRegister SourceLanguageLookup
 *
 * One (object, property) tuple whose source language is asked for, with the
 * schema that declares the property and the register's default language.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * What {@see TranslationProjectionService::resolveSourceLanguage()} reads.
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-2
 */
final class SourceLanguageLookup {

	/**
	 * Constructor.
	 *
	 * @param Schema       $schema          The schema declaring the property.
	 * @param ObjectEntity $object          The object body containing an optional override.
	 * @param string       $property        The translatable property name.
	 * @param string       $registerDefault Register-level default language.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly ObjectEntity $object,
		public readonly string $property,
		public readonly string $registerDefault,
	) {
	}//end __construct()
}//end class
