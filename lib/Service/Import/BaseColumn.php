<?php

/**
 * BaseColumn: one column of a table in a base read from another product.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

/**
 * A column of a base table, in the neutral shape every base reader produces.
 *
 * The type is one of the TYPE_* constants. A link column names the table it
 * links to and whether a row may link to more than one row there.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */
final class BaseColumn {

	public const TYPE_STRING = 'string';
	public const TYPE_TEXT = 'text';
	public const TYPE_INTEGER = 'integer';
	public const TYPE_NUMBER = 'number';
	public const TYPE_BOOLEAN = 'boolean';
	public const TYPE_DATE = 'date';
	public const TYPE_DATETIME = 'datetime';
	public const TYPE_EMAIL = 'email';
	public const TYPE_URL = 'url';
	public const TYPE_LIST = 'list';
	public const TYPE_LINK = 'link';

	/**
	 * Constructor.
	 *
	 * @param string      $name      The column's name in the source product.
	 * @param string      $type      One of the TYPE_* constants.
	 * @param string|null $linkTable For a link column: the name of the linked table.
	 * @param bool        $multiple  For a link column: whether a row may link to several rows.
	 * @param bool        $primary   Whether this is the table's primary (display) column.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A value object's facts, not behaviour switches.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $type,
		public readonly ?string $linkTable=null,
		public readonly bool $multiple=true,
		public readonly bool $primary=false,
	) {
	}//end __construct()

	/**
	 * Whether this column links to another table.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
	 */
	public function isLink(): bool {
		return $this->type === self::TYPE_LINK && $this->linkTable !== null;
	}//end isLink()
}//end class
