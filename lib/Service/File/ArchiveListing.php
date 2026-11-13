<?php

/**
 * ArchiveListing
 *
 * What enumerating an archive found, and the bound it stopped at (REQ-AMS-001).
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
 *
 * @spec openspec/changes/archive-members-are-searchable/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

/**
 * The members of an archive and why the listing stopped, if it did.
 *
 * @spec openspec/changes/archive-members-are-searchable/specs
 */
final class ArchiveListing {

	/**
	 * A member that was listed.
	 *
	 * @var string
	 */
	public const LISTED = 'listed';

	/**
	 * A member whose path leaves the archive; it is never resolved.
	 *
	 * @var string
	 */
	public const REFUSED = 'refused';

	/**
	 * A member that is encrypted.
	 *
	 * @var string
	 */
	public const ENCRYPTED = 'encrypted';

	/**
	 * The members, in archive order; a nested member's path is `outer.zip/inner`.
	 *
	 * @var list<array{path: string, size: int, compressedSize: int, mimeType: string, status: string, depth: int}>
	 */
	private array $members = [];

	/**
	 * The bound the listing stopped at, or null.
	 *
	 * @var string|null
	 */
	private ?string $stoppedAt = null;

	/**
	 * The member the listing stopped at, or ''.
	 *
	 * @var string
	 */
	private string $stoppedMember = '';

	/**
	 * Add a member.
	 *
	 * @param array{path: string, size: int, compressedSize: int, mimeType: string, status: string, depth: int} $member The member.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function add(array $member): void {
		$this->members[] = $member;
	}//end add()

	/**
	 * Record that a bound stopped the listing at a member.
	 *
	 * @param string $bound  The bound's name, as in ArchiveBounds::DEFAULTS.
	 * @param string $member The member's path.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function stop(string $bound, string $member): void {
		$this->stoppedAt     = $bound;
		$this->stoppedMember = $member;
	}//end stop()

	/**
	 * The members.
	 *
	 * @return list<array{path: string, size: int, compressedSize: int, mimeType: string, status: string, depth: int}>
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function members(): array {
		return $this->members;
	}//end members()

	/**
	 * The bound the listing stopped at, or null when it is complete.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function stoppedAt(): ?string {
		return $this->stoppedAt;
	}//end stoppedAt()

	/**
	 * The member the listing stopped at, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function stoppedMember(): string {
		return $this->stoppedMember;
	}//end stoppedMember()

	/**
	 * Whether the listing stopped at a bound.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive-members-are-searchable/specs
	 */
	public function isComplete(): bool {
		return $this->stoppedAt === null;
	}//end isComplete()
}//end class
