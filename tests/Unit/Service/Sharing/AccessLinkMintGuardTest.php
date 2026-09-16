<?php

/**
 * Unit tests for AccessLinkMintGuard.
 *
 * This guard carries the whole capability. Every read through a link runs with
 * the group rules off, so if a mint could name any uuid, any signed-in user
 * could publish any record and the link would keep serving it correctly for as
 * long as it lived. Nothing downstream would look wrong.
 *
 * So the tests assert two things a passing implementation cannot fake: that the
 * subject is fetched with `_rbac` and `_multitenancy` BOTH true, which is the
 * opposite of every flag the reader uses, and that a refusal from that fetch is
 * a refusal to mint.
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
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Sharing\AccessLinkMintGuard;
use OCA\OpenRegister\Service\Sharing\AccessLinkSubject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AccessLinkMintGuardTest extends TestCase {

	private ObjectService&MockObject $objects;
	private AccessLinkMintGuard $guard;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->guard = new AccessLinkMintGuard(objects: $this->objects, subjects: new AccessLinkSubject());
	}

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('object-uuid');
		$object->setSchema('2');

		return $object;
	}

	public function testTheSubjectIsFetchedAsTheCallingUserWithTheRulesOn(): void {
		$this->objects->expects($this->once())
			->method('find')
			->with('object-uuid', [], false, null, null, true, true, false, false)
			->willReturn($this->object());

		$this->assertTrue($this->guard->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: 'object-uuid'));
	}

	public function testASubjectTheCallerMayNotReadCannotBePublished(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('denied'));

		$this->assertFalse($this->guard->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: 'object-uuid'));
	}

	public function testASubjectThatResolvesToNothingCannotBePublished(): void {
		$this->objects->method('find')->willReturn(null);

		$this->assertFalse($this->guard->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: 'object-uuid'));
	}

	public function testASoftDeletedSubjectCannotBePublished(): void {
		$object = $this->object();
		$object->setDeleted(['deleted' => '2026-01-01T00:00:00+00:00']);
		$this->objects->method('find')->willReturn($object);

		$this->assertFalse($this->guard->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: 'object-uuid'));
	}

	public function testAnEmptySubjectCannotBePublished(): void {
		$this->objects->expects($this->never())->method('find');

		$this->assertFalse($this->guard->mayMint(subjectType: AccessLink::SUBJECT_OBJECT, subjectId: '  '));
	}

	public function testAFileSubjectIsCheckedAgainstTheObjectThatOwnsIt(): void {
		$this->objects->expects($this->once())
			->method('find')
			->with('object-uuid', [], false, null, null, true, true, false, false)
			->willReturn($this->object());

		$this->assertTrue($this->guard->mayMint(subjectType: AccessLink::SUBJECT_FILE, subjectId: 'object-uuid/12'));
	}

	public function testAMalformedFileSubjectCannotBePublished(): void {
		$this->objects->expects($this->never())->method('find');

		$this->assertFalse($this->guard->mayMint(subjectType: AccessLink::SUBJECT_FILE, subjectId: 'no-slash'));
	}

	public function testAViewTheCallerCanListCanBePublished(): void {
		$this->objects->expects($this->once())
			->method('searchObjects')
			->with($this->anything(), true, true, null, null, ['view-uuid'])
			->willReturn([]);

		$this->assertTrue($this->guard->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}

	public function testAViewTheCallerCannotListCannotBePublished(): void {
		$this->objects->method('searchObjects')->willThrowException(new RuntimeException('denied'));

		$this->assertFalse($this->guard->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}
}
