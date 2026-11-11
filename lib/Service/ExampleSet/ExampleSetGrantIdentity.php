<?php

/**
 * OpenRegister Example Set Grant Identity
 *
 * What makes a share the one an example set's grant made: the folder, the
 * sharer, the principal and the share type, as the record keeps them and as
 * a share carries them, so a later run never takes another share under the
 * same id for the set's own.
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
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use OCP\Share\IShare;

/**
 * The identity of a share a grant made, on the share and in the record.
 *
 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
 */
final class ExampleSetGrantIdentity {
	/**
	 * The record entry of a share the sharing service made: its id and object, and its identity.
	 *
	 * @param string               $uuid  The object's uuid.
	 * @param array<string, mixed> $grant The share the sharing service made.
	 *
	 * @return array{id: string, object: string, node: int, sharedBy: string, sharedWith: string, shareType: int}
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function entry(string $uuid, array $grant): array {
		return [
			'id'         => (string) $grant['id'],
			'object'     => $uuid,
			'node'       => (int) $grant['nodeId'],
			'sharedBy'   => (string) $grant['sharedBy'],
			'sharedWith' => (string) $grant['sharedWith'],
			'shareType'  => (int) $grant['shareType'],
		];
	}//end entry()

	/**
	 * The fields that make a share the one the set made.
	 *
	 * @param IShare $share The share.
	 *
	 * @return array{node: int, sharedBy: string, sharedWith: string, shareType: int}
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function ofShare(IShare $share): array {
		return [
			'node'       => (int) $share->getNodeId(),
			'sharedBy'   => (string) $share->getSharedBy(),
			'sharedWith' => (string) $share->getSharedWith(),
			'shareType'  => (int) $share->getShareType(),
		];
	}//end ofShare()

	/**
	 * The identity of the share a record entry names, as ofShare() gives it.
	 *
	 * @param array<string, mixed> $entry A record entry from entry(), with a node.
	 *
	 * @return array{node: int, sharedBy: string, sharedWith: string, shareType: int}
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function recorded(array $entry): array {
		return [
			'node'       => (int) $entry['node'],
			'sharedBy'   => (string) ($entry['sharedBy'] ?? ''),
			'sharedWith' => (string) ($entry['sharedWith'] ?? ''),
			'shareType'  => (int) ($entry['shareType'] ?? -1),
		];
	}//end recorded()
}//end class
