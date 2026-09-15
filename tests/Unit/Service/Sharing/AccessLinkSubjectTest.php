<?php

/**
 * Unit tests for AccessLinkSubject.
 *
 * The reader and the mint guard both ask this helper which object a subject
 * means, with the access rules pointing in opposite directions. The one thing
 * that must never differ between them is the answer, so it is tested once here
 * rather than twice by implication.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubject;
use PHPUnit\Framework\TestCase;

class AccessLinkSubjectTest extends TestCase {

	private AccessLinkSubject $subjects;

	protected function setUp(): void {
		parent::setUp();
		$this->subjects = new AccessLinkSubject();
	}

	public function testAnObjectSubjectIsItsOwnUuid(): void {
		$this->assertSame(
			'object-uuid',
			$this->subjects->objectUuid(AccessLink::SUBJECT_OBJECT, ' object-uuid ')
		);
	}

	public function testAViewSubjectResolvesToNoObject(): void {
		$this->assertNull($this->subjects->objectUuid(AccessLink::SUBJECT_VIEW, 'view-uuid'));
	}

	public function testAFileSubjectNamesItsOwningObject(): void {
		$this->assertSame(
			'object-uuid',
			$this->subjects->objectUuid(AccessLink::SUBJECT_FILE, 'object-uuid/12')
		);
		$this->assertSame('12', $this->subjects->fileId('object-uuid/12'));
	}

	public function testAFileIdWithNoObjectHalfIsRefused(): void {
		$this->assertNull($this->subjects->objectUuid(AccessLink::SUBJECT_FILE, '12'));
		$this->assertNull($this->subjects->fileId('12'));
	}

	public function testAnEmptyHalfIsRefused(): void {
		$this->assertNull($this->subjects->objectUuid(AccessLink::SUBJECT_FILE, ' /12'));
		$this->assertNull($this->subjects->fileId('object-uuid/ '));
	}

	public function testAFilePathWithMoreSlashesKeepsTheRestAsTheFileId(): void {
		$this->assertSame('a/b', $this->subjects->fileId('object-uuid/a/b'));
	}

	public function testAnEmptySubjectResolvesToNothing(): void {
		$this->assertNull($this->subjects->objectUuid(AccessLink::SUBJECT_OBJECT, '   '));
	}
}
