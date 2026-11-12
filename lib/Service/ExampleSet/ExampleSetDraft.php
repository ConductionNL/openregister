<?php

/**
 * OpenRegister Example Set Draft
 *
 * An example-set object that is a draft of a portal subject: its `data`
 * declares `@self: {status: "draft", owner: "<scope>:<reference>"}`, and it is
 * written in status draft for that scoped owner, the way a saved portal form
 * is (decisions 180 and 195).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-set-draft-objects/specs/example-sets/spec.md#requirement-an-object-may-be-a-draft-of-a-portal-subject
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\DraftOwner;
use Throwable;

/**
 * Reads and checks an object's draft declaration.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- DraftOwner::scoped() is the one rule for a scoped draft owner.
 *
 * @spec openspec/changes/example-set-draft-objects/specs/example-sets/spec.md#requirement-an-object-may-be-a-draft-of-a-portal-subject
 */
final class ExampleSetDraft {
	/**
	 * The object's data without its `@self`, the draft owner it names (or
	 * null), and why the declaration cannot be written ('' when it can).
	 *
	 * @param array<string, mixed> $data The filled object data.
	 *
	 * @return array{data: array<string, mixed>, owner: DraftOwner|null, refusal: string}
	 *
	 * @spec openspec/changes/example-set-draft-objects/specs/example-sets/spec.md#requirement-an-object-may-be-a-draft-of-a-portal-subject
	 */
	public function split(array $data): array {
		if (array_key_exists('@self', $data) === false) {
			return ['data' => $data, 'owner' => null, 'refusal' => ''];
		}

		$self = $data['@self'];
		unset($data['@self']);
		if (is_array($self) === false || array_diff(array_keys($self), ['status', 'owner']) !== []) {
			return ['data' => $data, 'owner' => null, 'refusal' => '@self may declare status and owner only'];
		}

		if (($self['status'] ?? null) !== ObjectEntity::STATUS_DRAFT) {
			return ['data' => $data, 'owner' => null, 'refusal' => '@self status may only be "'.ObjectEntity::STATUS_DRAFT.'"'];
		}

		$owner = (string) ($self['owner'] ?? '');
		$colon = strpos($owner, ':');
		if ($colon === false) {
			return ['data' => $data, 'owner' => null, 'refusal' => 'a draft owner is "<scope>:<reference>", not "'.$owner.'"'];
		}

		try {
			$scoped = DraftOwner::scoped(scope: substr($owner, 0, $colon), reference: substr($owner, ($colon + 1)));
		} catch (Throwable $e) {
			return ['data' => $data, 'owner' => null, 'refusal' => 'draft owner "'.$owner.'": '.$e->getMessage()];
		}

		return ['data' => $data, 'owner' => $scoped, 'refusal' => ''];
	}//end split()
}//end class
