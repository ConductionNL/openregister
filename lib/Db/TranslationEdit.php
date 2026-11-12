<?php

/**
 * OpenRegister TranslationEdit
 *
 * What one write puts into a translation slot.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

/**
 * The value, workflow status, translator and source language written into one slot.
 *
 * A null status keeps the slot's existing status, and drafts a slot that has none.
 * A null translator and an empty or null source language leave those columns as they are.
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
final class TranslationEdit {
	/**
	 * Construct the edit.
	 *
	 * @param string|null $value          Translated value (null clears).
	 * @param string|null $status         Optional workflow status override.
	 * @param string|null $translator     Optional UID of the translator.
	 * @param string|null $sourceLanguage Optional canonical source language for the property.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?string $value,
		public readonly ?string $status = null,
		public readonly ?string $translator = null,
		public readonly ?string $sourceLanguage = null,
	) {
	}//end __construct()

	/**
	 * Write this edit into a slot's entity.
	 *
	 * @param Translation $entity The slot being written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/register-i18n/spec.md
	 */
	public function applyTo(Translation $entity): void {
		$entity->setValue($this->value);
		$this->applyStatus(entity: $entity);
		if ($this->translator !== null) {
			$entity->setTranslator($this->translator);
		}

		if (in_array($this->sourceLanguage, ['', null], true) === false) {
			$entity->setSourceLanguage($this->sourceLanguage);
		}
	}//end applyTo()

	/**
	 * Set the workflow status: the override when given, else draft for a slot without one.
	 *
	 * @param Translation $entity The slot being written.
	 *
	 * @return void
	 */
	private function applyStatus(Translation $entity): void {
		if ($this->status !== null) {
			$entity->setStatus($this->status);
			return;
		}

		if ($entity->getStatus() === null) {
			$entity->setStatus(Translation::STATUS_DRAFT);
		}
	}//end applyStatus()
}//end class
