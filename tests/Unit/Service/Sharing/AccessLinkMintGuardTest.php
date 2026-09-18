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
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\View;

/**
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class AccessLinkMintGuardTest extends TestCase {

	private ObjectService&MockObject $objects;

	private ViewMapper|\PHPUnit\Framework\MockObject\MockObject $views;

	private RegisterMapper&MockObject $registers;

	private SchemaMapper&MockObject $schemas;
	private AccessLinkMintGuard $guard;

	protected function setUp(): void {
		parent::setUp();

		$this->objects = $this->createMock(ObjectService::class);
		$this->views = $this->createMock(ViewMapper::class);
		$this->registers = $this->createMock(RegisterMapper::class);
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->guard = new AccessLinkMintGuard(
			objects: $this->objects,
			subjects: new AccessLinkSubject(),
			views: $this->views,
			registers: $this->registers,
			schemas: $this->schemas,
		);
	}

	/**
	 * A real View: getQuery() is an Entity magic accessor PHPUnit cannot stub.
	 *
	 * @param array<string, mixed> $query The view's stored query.
	 */
	private function view(array $query = []): View {
		$view = new View();
		$view->setQuery($query);

		return $view;
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

	public function testAViewTheCallerCanResolveCanBePublished(): void {
		// Resolved under the caller's OWN rules: no _rbac/_multitenancy
		// overrides, so a view in another organisation throws below.
		$this->views->expects($this->once())->method('find')->with('view-uuid')
			->willReturn($this->view(['registers' => [7], 'schemas' => [4]]));
		$this->objects->expects($this->never())->method('searchObjects');

		$this->assertTrue($this->guard->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}

	public function testAViewTheCallerCannotResolveCannotBePublished(): void {
		// The regression this guard exists for: a search that merely MENTIONS an
		// unresolvable view still answers an array, because applyViewsToQuery()
		// skips it. Resolving the view itself is what makes a refusal a refusal.
		$this->views->method('find')->willThrowException(new RuntimeException('denied'));

		$this->assertFalse($this->guard->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}

	public function testAViewNamingARegisterTheCallerCannotReadCannotBePublished(): void {
		// Owning the view row is not owning what it points at. `POST /api/views`
		// is #[NoAdminRequired] and copies configuration.registers into the stored
		// query verbatim, so a view of your own may name another organisation's
		// register. The link that view mints reads with RBAC and multitenancy off.
		$this->views->method('find')->willReturn($this->view(['registers' => [7]]));
		$this->registers->method('find')->willThrowException(new RuntimeException('denied'));

		$this->assertFalse($this->guard->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}

	public function testAViewNamingASchemaTheCallerCannotReadCannotBePublished(): void {
		$this->views->method('find')->willReturn($this->view(['schemas' => [4]]));
		$this->schemas->method('find')->willThrowException(new RuntimeException('denied'));

		$this->assertFalse($this->guard->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}

	public function testEveryRegisterAndSchemaTheViewNamesIsChecked(): void {
		// Not just the first: one unreadable id anywhere in the list is a refusal.
		$this->views->method('find')->willReturn($this->view(['registers' => [7, 8, 9]]));
		$this->registers->expects($this->exactly(3))->method('find');

		$this->assertTrue($this->guard->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}

	public function testAViewWithNoRegisterOrSchemaFilterIsStillCheckedAgainstNothing(): void {
		// An empty query names nothing to leak. The view-scope rule downstream
		// refuses such a view separately, on the grounds that it bounds nothing.
		$this->views->method('find')->willReturn($this->view([]));
		$this->registers->expects($this->never())->method('find');
		$this->schemas->expects($this->never())->method('find');

		$this->assertTrue($this->guard->mayMint(subjectType: AccessLink::SUBJECT_VIEW, subjectId: 'view-uuid'));
	}
}
