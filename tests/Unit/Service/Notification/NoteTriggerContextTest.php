<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The note's author and excerpt a `noteAdded` firing renders, and the author exclusion.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#requirement-a-rule-can-fire-when-a-note-is-added
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapperInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NoteTriggerContext;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(NoteTriggerContext::class)]
#[UsesClass(AccessLink::class)]
class NoteTriggerContextTest extends TestCase {

	private function comments(string $message): ICommentsManager {
		$comment = $this->createMock(IComment::class);
		$comment->method('getMessage')->willReturn($message);
		$comments = $this->createMock(ICommentsManager::class);
		$comments->expects($this->once())->method('get')->with('7')->willReturn($comment);

		return $comments;
	}

	public function testAUserNoteNamesTheDisplayNameAndAPlainExcerpt(): void {
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->once())->method('getDisplayName')->with('planner')->willReturn('Petra Planner');
		$context = new NoteTriggerContext(logger: new NullLogger(), comments: $this->comments("<p>Call  the\nclient</p>"), users: $users);

		$enriched = $context->enrich(['noteId' => '7', 'actorType' => 'users', 'actorId' => 'planner', 'visibility' => 'public']);

		$this->assertSame('Petra Planner', $enriched['note.author']);
		$this->assertSame('Call the client', $enriched['note.excerpt']);
		$this->assertSame('public', $enriched['visibility']);
	}

	public function testALinkNoteNamesTheLinkLabel(): void {
		$link = new AccessLink();
		$link->setLabel('Supplier portal');
		$links = $this->createMock(AccessLinkMapperInterface::class);
		$links->expects($this->once())->method('findByUuid')->with('abc-123')->willReturn($link);
		$context = new NoteTriggerContext(logger: new NullLogger(), comments: $this->comments('ok'), links: $links);

		$enriched = $context->enrich(['noteId' => '7', 'actorType' => 'openregister_links', 'actorId' => 'link:abc-123']);

		$this->assertSame('Supplier portal', $enriched['note.author']);
	}

	public function testAnUnknownLinkOrUserFallsBackToTheActorId(): void {
		$links = $this->createMock(AccessLinkMapperInterface::class);
		$links->method('findByUuid')->willReturn(null);
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturn(null);
		$context = new NoteTriggerContext(logger: new NullLogger(), users: $users, links: $links);

		$this->assertSame('link:gone', $context->enrich(['actorType' => 'openregister_links', 'actorId' => 'link:gone'])['note.author']);
		$this->assertSame('ghost', $context->enrich(['actorType' => 'users', 'actorId' => 'ghost'])['note.author']);
	}

	public function testANoteThatCannotBeReadRendersAnEmptyExcerpt(): void {
		$comments = $this->createMock(ICommentsManager::class);
		$comments->method('get')->willThrowException(new NotFoundException('gone'));
		$context = new NoteTriggerContext(logger: new NullLogger(), comments: $comments);

		$this->assertSame('', $context->enrich(['noteId' => '9', 'actorType' => 'users', 'actorId' => 'x'])['note.excerpt']);
	}

	public function testTheExcerptStopsAt140Characters(): void {
		$excerpt = NoteTriggerContext::excerpt(str_repeat('é', 200));

		$this->assertSame(140, mb_strlen($excerpt));
		$this->assertStringEndsWith('…', $excerpt);
		$this->assertSame(str_repeat('a', 140), NoteTriggerContext::excerpt(str_repeat('a', 140)));
	}

	public function testOnlyAUserAuthorIsDroppedFromTheRecipients(): void {
		$context = new NoteTriggerContext(logger: new NullLogger());

		$this->assertSame(['colleague'], $context->withoutAuthor(['planner', 'colleague'], ['actorType' => 'users', 'actorId' => 'planner']));
		$linkNote = ['actorType' => 'openregister_links', 'actorId' => 'planner'];
		$this->assertSame(['planner', 'colleague'], $context->withoutAuthor(['planner', 'colleague'], $linkNote));
	}
}//end class
