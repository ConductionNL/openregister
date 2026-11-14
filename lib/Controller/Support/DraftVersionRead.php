<?php

/**
 * OpenRegister DraftVersionRead
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\DraftService;

/**
 * One object read at a draft version: the live object with the draft's delta merged in.
 *
 * The merged entity is a copy and is never saved; it goes through the same
 * render path as the live object, so relations in the delta resolve the same
 * way. `main`, or no version at all, is the live object.
 *
 * @spec openspec/changes/records-draft-versions/design.md
 */
final class DraftVersionRead {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $entity  The merged copy to render.
	 * @param array<string, mixed> $version The `_version` stamp: key, name and base version.
	 */
	private function __construct(
		public readonly ObjectEntity $entity,
		public readonly array $version,
	) {
	}//end __construct()

	/**
	 * Whether a requested version names a draft rather than the live object.
	 *
	 * @param string $version The requested version, '' when none.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/records-draft-versions/design.md
	 */
	public static function namesADraft(string $version): bool {
		return $version !== '' && $version !== DraftService::MAIN;
	}//end namesADraft()

	/**
	 * The object at this draft version, or null when the caller may not see such a draft.
	 *
	 * A draft that does not exist and one the caller may not see answer alike,
	 * so the answer does not tell which drafts exist.
	 *
	 * @param DraftService $drafts The drafts.
	 * @param ObjectEntity $object The live object.
	 * @param Schema       $schema Its schema.
	 * @param string       $key    The draft key.
	 * @param string|null  $caller The caller's uid.
	 *
	 * @return self|null
	 *
	 * @spec openspec/changes/records-draft-versions/design.md
	 */
	public static function of(DraftService $drafts, ObjectEntity $object, Schema $schema, string $key, ?string $caller): ?self {
		$draft = $drafts->find(object: $object, schema: $schema, key: $key, caller: $caller);
		if ($draft === null) {
			return null;
		}

		$merged = clone $object;
		$merged->setObject($drafts->merged(object: $object, draft: $draft));

		return new self(
			entity: $merged,
			version: ['key' => $key, 'name' => (string)$draft->getName(), 'base' => $draft->getBaseVersion()]
		);
	}//end of()

	/**
	 * The rendered object with `@self._version` added.
	 *
	 * @param array<string, mixed> $rendered The rendered object.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/records-draft-versions/design.md
	 */
	public function stamped(array $rendered): array {
		$self = $rendered['@self'] ?? [];
		if (is_array($self) === false) {
			$self = [];
		}

		$self['_version'] = $this->version;
		$rendered['@self'] = $self;

		return $rendered;
	}//end stamped()
}//end class
