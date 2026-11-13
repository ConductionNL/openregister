<?php

/**
 * ObjectSaveCheck: a save rule that decides acceptance, callable without a write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * A rule that decides whether a save is accepted, runnable on a sample.
 *
 * The listener that refuses a save calls its own check() from handle(), and
 * a validate-only call runs the same check(), so the two cannot disagree.
 *
 * A check is PURE: it reads, it never writes, fills a field or dispatches an
 * event. A listener that does more than decide acceptance does not implement
 * this interface. A check whose own machinery fails answers no violations,
 * exactly as its listener lets the save through in that case.
 *
 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
 */
interface ObjectSaveCheck {

	/**
	 * The rule's refusals of an object as it would be saved.
	 *
	 * @param array<string, mixed> $object   The object data as it would be saved.
	 * @param Schema               $schema   Its schema.
	 * @param ObjectEntity|null    $existing The stored object on an update, null on a create.
	 *
	 * @return array<int, array{property: string, message: string, rule: string}> One entry per refused property.
	 *
	 * @spec openspec/changes/records-validate-without-saving/specs/objects-crud/spec.md#requirement-a-record-can-be-validated-without-saving-it
	 */
	public function check(array $object, Schema $schema, ?ObjectEntity $existing): array;
}//end interface
