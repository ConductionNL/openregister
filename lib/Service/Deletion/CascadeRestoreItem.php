<?php

/**
 * One object a cascade restore looked at, and what it would do with it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

/**
 * One classified entry of a cascade restore plan.
 *
 * The class is one of {@see CascadeRestoreService::CLASSES}. A relink carries
 * the property and the value it would write back; every other class carries
 * only the reason it is in that class.
 *
 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
 */
final class CascadeRestoreItem {
	/**
	 * Constructor.
	 *
	 * @param string      $uuid       The object's uuid.
	 * @param string      $class      Restore, relink, changed, gone, already or forbidden.
	 * @param string      $reason     Why it is in that class, in words.
	 * @param string|null $schema     The schema the object is in, as the caller may name it.
	 * @param bool        $visible    Whether the caller may read the object, so may be told its uuid.
	 * @param string|null $property   The reference property, for a relink or a changed reference.
	 * @param mixed       $relinkTo   The value a relink writes into the property.
	 * @param mixed       $register   The object's register, for the relinking write.
	 * @param mixed       $schemaRef  The object's schema id, for the relinking write.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $class,
		public readonly string $reason,
		public readonly ?string $schema=null,
		public readonly bool $visible=false,
		public readonly ?string $property=null,
		public readonly mixed $relinkTo=null,
		public readonly mixed $register=null,
		public readonly mixed $schemaRef=null,
	) {
	}//end __construct()

	/**
	 * The item as the preview and restore responses show it.
	 *
	 * The uuid is left out for an object the caller may not read, so the
	 * response never confirms the existence of an object they cannot see.
	 *
	 * @return array<string, mixed> The public shape.
	 *
	 * @spec openspec/changes/records-restore-with-cascade/specs/deletion-audit-trail/spec.md
	 */
	public function toArray(): array {
		$item = [
			'reason' => $this->reason,
			'schema' => $this->schema,
		];
		if ($this->visible === true) {
			$item = ['uuid' => $this->uuid] + $item;
		}

		if ($this->property !== null) {
			$item['property'] = $this->property;
		}

		return $item;
	}//end toArray()
}//end class
