<?php

/**
 * A cached object or organisation name with what decides who may see it.
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
 * A name in CacheHandler's name cache, its owning organisation and its source.
 *
 * The source is NAME_SOURCE_ORGANISATION for an organisation's name, "<registerId>:<schemaId>"
 * (the table whose read rule decides visibility) for an object's, or null when unknown.
 *
 * @spec openspec/specs/schema-driven-read-coercion/spec.md
 */
final class CachedName {

	/**
	 * Constructor for CachedName.
	 *
	 * @param string      $name         The name.
	 * @param string|null $organisation The owning organisation UUID, or null when unknown.
	 * @param string|null $source       The name source, or null when unknown.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $organisation = null,
		public readonly ?string $source = null,
	) {
	}//end __construct()

	/**
	 * The envelope the distributed name cache stores: the name with what decides its visibility.
	 *
	 * @return array{n: string, o: string|null, s: string|null}
	 *
	 * @spec openspec/specs/schema-driven-read-coercion/spec.md
	 */
	public function envelope(): array {
		return ['n' => $this->name, 'o' => $this->organisation, 's' => $this->source];
	}//end envelope()
}//end class
