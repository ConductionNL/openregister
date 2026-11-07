<?php

/**
 * PermissionAsk
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * One hasPermission() question exactly as the caller put it.
 *
 * The user stays the argument here, null for "the session user": the cache key
 * resolves it and the telemetry event reports it as given.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class PermissionAsk {
	/**
	 * The user id as given.
	 *
	 * @var string|null
	 */
	public ?string $userId = null;

	/**
	 * The object owner, for the owner bypass.
	 *
	 * @var string|null
	 */
	public ?string $objectOwner = null;

	/**
	 * The object the question is about.
	 *
	 * @var ObjectEntity|null
	 */
	public ?ObjectEntity $object = null;

	/**
	 * The register written to: the object's, else the one the caller named.
	 *
	 * @var string|null
	 */
	public ?string $register = null;

	/**
	 * Start a question.
	 *
	 * @param Schema $schema The schema asked about
	 * @param string $action The action asked for
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $action,
	) {
	}//end __construct()

	/**
	 * Name who asks and who owns the object.
	 *
	 * @param string|null $userId      The user id as given
	 * @param string|null $objectOwner The object owner
	 *
	 * @return self
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function askedBy(?string $userId, ?string $objectOwner): self {
		$this->userId      = $userId;
		$this->objectOwner = $objectOwner;

		return $this;
	}//end askedBy()

	/**
	 * Name the object and the register.
	 *
	 * @param ObjectEntity|null $object   The object, or null for a schema-level question
	 * @param string|null       $register The register a question without one on its object names
	 *
	 * @return self
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function about(?ObjectEntity $object, ?string $register): self {
		$this->object   = $object;
		$this->register = ($object?->getRegister() ?? $register);

		return $this;
	}//end about()

	/**
	 * The check that evaluates this question.
	 *
	 * @return PermissionCheck
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function check(): PermissionCheck {
		$check              = new PermissionCheck(schema: $this->schema, action: $this->action);
		$check->userId      = $this->userId;
		$check->objectOwner = $this->objectOwner;
		$check->setObject(object: $this->object);

		return $check;
	}//end check()
}//end class
