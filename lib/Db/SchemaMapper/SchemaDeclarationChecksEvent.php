<?php

/**
 * SchemaDeclarationChecksEvent: collects the schema declaration checks other areas contribute.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Kernel\Contracts\Core\SchemaDeclarationCheckInterface;
use OCP\EventDispatcher\Event;

/**
 * Dispatched once by the schema mapper; each listener adds the checks of its area.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaDeclarationChecksEvent extends Event {

	/**
	 * The contributed checks, in the order they were added.
	 *
	 * @var array<int, SchemaDeclarationCheckInterface>
	 */
	private array $checks = [];

	/**
	 * Contribute one check.
	 *
	 * @param SchemaDeclarationCheckInterface $check The check.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function add(SchemaDeclarationCheckInterface $check): void {
		$this->checks[] = $check;
	}//end add()

	/**
	 * The contributed checks.
	 *
	 * @return array<int, SchemaDeclarationCheckInterface>
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function checks(): array {
		return $this->checks;
	}//end checks()
}//end class
