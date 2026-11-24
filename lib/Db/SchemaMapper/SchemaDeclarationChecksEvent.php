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

use Closure;
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
	 * Constructor.
	 *
	 * @param Closure|null $warnsOnUnlinkable Answers, when a check runs, whether the save only warns about an unlinkable notification action.
	 */
	public function __construct(private readonly ?Closure $warnsOnUnlinkable = null) {
		parent::__construct();
	}//end __construct()

	/**
	 * Whether the save being checked only warns about a notification url action that cannot be linked to.
	 *
	 * A configuration import, and a save that keeps the stored notification block, set
	 * this on the mapper; a check reads it when it runs, not when it is contributed.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function warnsOnUnlinkableNotificationActions(): bool {
		if ($this->warnsOnUnlinkable === null) {
			return false;
		}

		return ($this->warnsOnUnlinkable)() === true;
	}//end warnsOnUnlinkableNotificationActions()

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
