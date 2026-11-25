<?php

declare(strict_types=1);

/**
 * Object access links through the sharing service: a read link over one object, admitted and audited as any link.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sharing
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Sharing;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkServiceInterface;
use OCA\OpenRegister\Service\Sharing\AccessLinkTerms;
use OCA\OpenRegister\Service\Sharing\AccessLinkUse;
use OCA\OpenRegister\Service\Sharing\LinkMintRequest;
use OCA\OpenRegister\Service\Sharing\ObjectAccessLinks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ObjectAccessLinks over a mocked sharing service.
 */
#[CoversClass(ObjectAccessLinks::class)]
#[UsesClass(LinkMintRequest::class)]
#[UsesClass(AccessLinkTerms::class)]
#[UsesClass(AccessLinkUse::class)]
#[UsesClass(AccessLink::class)]
#[UsesClass(ObjectEntity::class)]
class ObjectAccessLinksTest extends TestCase {

	/**
	 * The sharing service.
	 *
	 * @var AccessLinkServiceInterface&MockObject
	 */
	private AccessLinkServiceInterface&MockObject $service;

	/**
	 * The class under test.
	 */
	private ObjectAccessLinks $links;

	/**
	 * Wire it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(AccessLinkServiceInterface::class);
		$this->links = new ObjectAccessLinks(links: $this->service);
	}//end setUp()

	/**
	 * A link of the given subject type.
	 *
	 * @param string $subjectType The subject type.
	 *
	 * @return AccessLink The link.
	 */
	private static function link(string $subjectType): AccessLink {
		$link = new AccessLink();
		$link->setSubjectType($subjectType);
		$link->setSubjectId('inv-1');

		return $link;
	}//end link()

	/**
	 * The link is a read link (no capability beyond read) over the object, with the expiry and label asked for.
	 *
	 * @return void
	 */
	public function testAMintedLinkIsAReadLinkOverTheObject(): void {
		$this->service->expects($this->once())->method('mint')
			->with($this->callback(
				static fn (LinkMintRequest $request): bool => $request->minter === 'owner'
					&& $request->subjectType === AccessLink::SUBJECT_OBJECT
					&& $request->subjectId === 'inv-1'
					&& $request->terms->capabilities === []
					&& $request->terms->expiresAt === '2027-01-24T10:00:00+00:00'
					&& $request->terms->label === 'Survey invitation'
					&& $request->terms->password === null
			))
			->willReturn(['uuid' => 'link-uuid', 'anchor' => 'Anchor', 'url' => 'https://x/a']);

		$this->assertSame(
			['uuid' => 'link-uuid', 'anchor' => 'Anchor'],
			$this->links->mintObjectLink(minter: 'owner', objectUuid: 'inv-1', expiresAt: '2027-01-24T10:00:00+00:00', label: 'Survey invitation')
		);
	}//end testAMintedLinkIsAReadLinkOverTheObject()

	/**
	 * Only a live link over an object is handed out; a view link or an unknown anchor is null.
	 *
	 * @return void
	 */
	public function testOnlyALiveObjectLinkResolves(): void {
		$object = self::link(subjectType: AccessLink::SUBJECT_OBJECT);
		$this->service->method('resolve')->willReturnMap([
			['object', $object],
			['view', self::link(subjectType: AccessLink::SUBJECT_VIEW)],
			['unknown', null],
		]);

		$this->assertSame($object, $this->links->liveObjectLink(anchor: 'object'));
		$this->assertNull($this->links->liveObjectLink(anchor: 'view'));
		$this->assertNull($this->links->liveObjectLink(anchor: 'unknown'));
	}//end testOnlyALiveObjectLinkResolves()

	/**
	 * A use is recorded through the sharing service with its act, object, address and context.
	 *
	 * @return void
	 */
	public function testAUseIsRecordedThroughTheSharingService(): void {
		$link = self::link(subjectType: AccessLink::SUBJECT_OBJECT);
		$object = new ObjectEntity();
		$this->service->expects($this->once())->method('recordUse')
			->with($link, $this->callback(
				static fn (AccessLinkUse $use): bool => $use->act === 'survey.answered'
					&& $use->object === $object
					&& $use->ipAddress === '192.0.2.1'
					&& $use->context === ['survey' => 's-1']
			));

		$this->links->recordObjectUse(link: $link, object: $object, act: 'survey.answered', ipAddress: '192.0.2.1', context: ['survey' => 's-1']);
	}//end testAUseIsRecordedThroughTheSharingService()

	/**
	 * A link or object the sharing service did not hand out is not recorded.
	 *
	 * @return void
	 */
	public function testAForeignLinkOrObjectIsNotRecorded(): void {
		$this->service->expects($this->never())->method('recordUse');

		$this->links->recordObjectUse(
			link: $this->createMock(AccessLinkInterface::class),
			object: new ObjectEntity(),
			act: 'x',
			ipAddress: null
		);
		$this->links->recordObjectUse(
			link: self::link(subjectType: AccessLink::SUBJECT_OBJECT),
			object: $this->createMock(ObjectEntityInterface::class),
			act: 'x',
			ipAddress: null
		);
	}//end testAForeignLinkOrObjectIsNotRecorded()
}//end class
