<?php

/**
 * OpenRegister Object Translation Request
 *
 * One object to translate from one language into another.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author	Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link	  https://www.OpenRegister.app
 *
 * @spec openspec/specs/register-i18n/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * What BulkTranslationService::translateObject() passes to its per-property steps.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class ObjectTranslationRequest {

	/**
	 * The object's data, read once.
	 *
	 * @var array
	 */
	public readonly array $data;

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object   The object to translate.
	 * @param string       $fromLong Source language code.
	 * @param string       $toLong   Target language code.
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $fromLong,
		public readonly string $toLong,
	) {
		$this->data = (array)($object->getObject() ?? []);
	}//end __construct()
}//end class
