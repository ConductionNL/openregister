<?php

/**
 * OpenRegister GeneratedIdentifierIssueRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTimeImmutable;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationInterface;

/**
 * One write being given the numbers its schema declares.
 *
 * Built by {@see GeneratedIdentifierListener} once the declarations resolved;
 * it never leaves that class (ADR-011 Rule 3, modelled on CascadeRun).
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
 */
final class GeneratedIdentifierIssueRun {

	/**
	 * The write being prepared; it carries a refusal.
	 *
	 * @var ObjectCreatingEvent|ObjectUpdatingEvent|null
	 */
	public ObjectCreatingEvent|ObjectUpdatingEvent|null $event = null;

	/**
	 * Property name to its declaration.
	 *
	 * @var array<string, GeneratedIdentifierDeclarationInterface>
	 */
	public array $declarations = [];

	/**
	 * The object's data.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * The moment every number is issued at.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $now = null;
}//end class
