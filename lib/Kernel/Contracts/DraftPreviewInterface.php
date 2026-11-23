<?php

/**
 * DraftPreviewInterface: what a draft preview link shows, for the reader that serves the link.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * The draft a preview link holds, read without a caller check.
 *
 * The access-link reader (Files) serves preview links; drafts (Core) keep the
 * draft versions and merge them over the live object. The link itself is the
 * principal: it was minted by someone who could see the draft.
 *
 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-a-draft-can-be-previewed-on-an-outside-site-through-an-expiring-link
 */
interface DraftPreviewInterface {

	/**
	 * The draft a preview link holds, as the link needs to mark it.
	 *
	 * @param string $draftUuid The draft's own uuid.
	 *
	 * @return array{objectUuid: string, key: string, name: string}|null The draft, or null when it is gone.
	 *
	 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-a-draft-can-be-previewed-on-an-outside-site-through-an-expiring-link
	 */
	public function previewedDraft(string $draftUuid): ?array;

	/**
	 * A copy of the live object carrying the draft's merged data; never saved.
	 *
	 * @param ObjectEntityInterface $object    The live object.
	 * @param string                $draftUuid The draft's own uuid.
	 *
	 * @return ObjectEntityInterface|null The copy, or null when the draft is gone.
	 *
	 * @spec openspec/changes/records-draft-versions/specs/content-versioning/spec.md#requirement-a-draft-can-be-previewed-on-an-outside-site-through-an-expiring-link
	 */
	public function previewCopy(ObjectEntityInterface $object, string $draftUuid): ?ObjectEntityInterface;
}//end interface
