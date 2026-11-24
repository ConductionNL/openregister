<?php

/**
 * OpenRegister PropertyWriteNotAuthorizedException
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-field-access-editor/specs/row-field-level-security/spec.md
 */

namespace OCA\OpenRegister\Exception;

use Exception;
use Throwable;

/**
 * A write touches properties the caller may not change.
 *
 * Carries the refused property names so the controller can answer 403 naming
 * them, and a client can tell a forbidden field from a server fault. The
 * server stays the gate: this is thrown by the save path's property-RBAC
 * guard, not by the UI.
 */
class PropertyWriteNotAuthorizedException extends Exception {

	/**
	 * The HTTP status a controller answers a forbidden property write with.
	 *
	 * @var integer
	 */
	public const HTTP_STATUS = 403;

	/**
	 * Constructor.
	 *
	 * @param array<int, int|string> $properties The refused property names.
	 * @param Throwable|null          $previous   The underlying error, if any.
	 *
	 * @spec openspec/changes/modelling-field-access-editor/specs/row-field-level-security/spec.md
	 */
	public function __construct(
		private readonly array $properties,
		?Throwable $previous = null,
	) {
		parent::__construct(
			'You are not authorized to modify the following properties: ' . implode(', ', $properties),
			0,
			$previous
		);
	}//end __construct()

	/**
	 * The refused property names, in the order the guard reported them.
	 *
	 * @return array<int, int|string> The property names.
	 *
	 * @spec openspec/changes/modelling-field-access-editor/specs/row-field-level-security/spec.md
	 */
	public function getProperties(): array {
		return $this->properties;
	}//end getProperties()

	/**
	 * The HTTP status a controller answers with.
	 *
	 * @return integer Always 403.
	 *
	 * @spec openspec/changes/modelling-field-access-editor/specs/row-field-level-security/spec.md
	 */
	public function getHttpStatusCode(): int {
		return self::HTTP_STATUS;
	}//end getHttpStatusCode()
}//end class
