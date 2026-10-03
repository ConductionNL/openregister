<?php

/**
 * Threading a reply by its headers, and refusing to guess when they do not say.
 *
 * 🔴 THE FAILURE THIS FILE EXISTS FOR IS ONE CITIZEN'S REPLY ON ANOTHER
 * CITIZEN'S CASE. It is not a crash and not an error: the reply is filed, a
 * handler reads it, and the only sign is that the letter makes no sense on
 * that case. So there is no fuzzy match, no prefix match and no subject
 * fallback, and a chain pointing at two objects resolves NEITHER — asserted
 * with two objects belonging to different people.
 *
 * 🔴 `unthreaded` IS A NAMED ANSWER, NOT AN ABSENCE. A reply that matched
 * nothing is real, arrived, and needs a person; folding it into an empty
 * result leaves it in a queue nobody reads while the system looks healthy.
 * The control is the same shape as the notification refusal: the named state
 * and the empty object id must not be able to converge.
 *
 * 🔴 HEADER NAMES ARE CASE-INSENSITIVE per RFC 5322. Matching them case
 * sensitively drops the thread for one mail client only, which is the kind of
 * bug nobody reproduces.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Notification\ReplyThreadResolver;
use PHPUnit\Framework\TestCase;

/**
 * The header-driven thread resolution.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */
class ReplyThreadResolverTest extends TestCase {

	private ReplyThreadResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ReplyThreadResolver();
	}//end setUp()

	/**
	 * A lookup over recorded links.
	 *
	 * @param array<string,string> $links Message id to object uuid.
	 *
	 * @return callable The lookup.
	 */
	private function lookup(array $links): callable {
		return static function (string $messageId) use ($links): ?array {
			return (isset($links[$messageId]) === true ? ['objectUuid' => $links[$messageId]] : null);
		};
	}//end lookup()

	public function testAReplyThreadsOnItsDirectParent(): void {
		$result = $this->resolver->resolve(
			['In-Reply-To' => '<sent-1@gemeente.nl>'],
			$this->lookup(['<sent-1@gemeente.nl>' => 'zaak-a'])
		);

		$this->assertSame(ReplyThreadResolver::THREADED, $result['state']);
		$this->assertSame('zaak-a', $result['objectUuid']);
		$this->assertSame('In-Reply-To', $result['matchedOn']);
		$this->assertTrue($this->resolver->mayFileAutomatically($result['state']));
	}//end testAReplyThreadsOnItsDirectParent()

	public function testAnEditedSubjectDoesNotMatterBecauseTheSubjectIsNeverRead(): void {
		$result = $this->resolver->resolve(
			['Subject' => 'Re: iets heel anders', 'In-Reply-To' => '<sent-1@gemeente.nl>'],
			$this->lookup(['<sent-1@gemeente.nl>' => 'zaak-a'])
		);

		$this->assertSame('zaak-a', $result['objectUuid']);
	}//end testAnEditedSubjectDoesNotMatterBecauseTheSubjectIsNeverRead()

	public function testReferencesAreWalkedFromTheNearestAncestor(): void {
		$result = $this->resolver->resolve(
			['References' => '<old@gemeente.nl> <newer@gemeente.nl>'],
			$this->lookup(['<old@gemeente.nl>' => 'zaak-a', '<newer@gemeente.nl>' => 'zaak-a'])
		);

		$this->assertSame(ReplyThreadResolver::THREADED, $result['state']);
		// The last entry is the nearest ancestor and it is the one a reply is
		// actually about.
		$this->assertSame('<newer@gemeente.nl>', $result['messageId']);
	}//end testReferencesAreWalkedFromTheNearestAncestor()

	public function testInReplyToIsPreferredOverReferences(): void {
		$result = $this->resolver->resolve(
			['In-Reply-To' => '<parent@gemeente.nl>', 'References' => '<ancestor@gemeente.nl>'],
			$this->lookup(['<parent@gemeente.nl>' => 'zaak-a', '<ancestor@gemeente.nl>' => 'zaak-a'])
		);

		$this->assertSame('In-Reply-To', $result['matchedOn']);
	}//end testInReplyToIsPreferredOverReferences()

	/**
	 * The one the whole class is shaped around.
	 *
	 * @return void
	 */
	public function testAChainPointingAtTwoCitizensCasesResolvesNeither(): void {
		$result = $this->resolver->resolve(
			['References' => '<mail-about-a@gemeente.nl> <mail-about-b@gemeente.nl>'],
			$this->lookup([
				'<mail-about-a@gemeente.nl>' => 'zaak-van-jansen',
				'<mail-about-b@gemeente.nl>' => 'zaak-van-de-vries',
			])
		);

		$this->assertSame(ReplyThreadResolver::AMBIGUOUS, $result['state']);
		$this->assertSame('', $result['objectUuid'], 'neither case is chosen');
		// Both are named so a person can decide; nothing is filed on either.
		sort($result['candidates']);
		$this->assertSame(['zaak-van-de-vries', 'zaak-van-jansen'], $result['candidates']);
		$this->assertFalse($this->resolver->mayFileAutomatically($result['state']));
	}//end testAChainPointingAtTwoCitizensCasesResolvesNeither()

	public function testAReplyWithNoUsableReferenceIsNamedNotEmpty(): void {
		$result = $this->resolver->resolve(
			['In-Reply-To' => '<never-seen@elders.nl>'],
			$this->lookup(['<sent-1@gemeente.nl>' => 'zaak-a'])
		);

		$this->assertSame(ReplyThreadResolver::UNTHREADED, $result['state']);
		$this->assertSame('', $result['objectUuid']);
		// The control, the same shape as the notification refusal: the named
		// state is what tells this apart from a threaded result, never the
		// empty object id on its own.
		$this->assertNotSame(ReplyThreadResolver::THREADED, $result['state']);
		$this->assertFalse($this->resolver->mayFileAutomatically($result['state']));
	}//end testAReplyWithNoUsableReferenceIsNamedNotEmpty()

	public function testAReplyWithNoHeadersAtAllIsUnthreadedRatherThanGuessed(): void {
		$result = $this->resolver->resolve(['Subject' => 'Re: [ZAAK-42] iets'], $this->lookup([]));

		// The subject tag is right there and is deliberately not read: it is
		// the guess that files a reply on a stranger's case.
		$this->assertSame(ReplyThreadResolver::UNTHREADED, $result['state']);
		$this->assertSame('', $result['objectUuid']);
	}//end testAReplyWithNoHeadersAtAllIsUnthreadedRatherThanGuessed()

	public function testAPartialIdNeverMatches(): void {
		$result = $this->resolver->resolve(
			['In-Reply-To' => '<sent-1@gemeente.nl.evil.example>'],
			$this->lookup(['<sent-1@gemeente.nl>' => 'zaak-a'])
		);

		// No prefix match: an id that merely starts with ours is somebody
		// else's id.
		$this->assertSame(ReplyThreadResolver::UNTHREADED, $result['state']);
	}//end testAPartialIdNeverMatches()

	public function testHeaderNamesAreReadCaseInsensitively(): void {
		$result = $this->resolver->resolve(
			['in-reply-to' => '<sent-1@gemeente.nl>'],
			$this->lookup(['<sent-1@gemeente.nl>' => 'zaak-a'])
		);

		$this->assertSame('zaak-a', $result['objectUuid']);
	}//end testHeaderNamesAreReadCaseInsensitively()

	public function testAHeaderArrivingAsAnArrayIsRead(): void {
		$result = $this->resolver->resolve(
			['References' => ['<a@gemeente.nl>', '<b@gemeente.nl>']],
			$this->lookup(['<b@gemeente.nl>' => 'zaak-a'])
		);

		$this->assertSame('zaak-a', $result['objectUuid']);
	}//end testAHeaderArrivingAsAnArrayIsRead()

	public function testALongChainIsBounded(): void {
		$ids = [];
		for ($i = 0; $i < 200; $i++) {
			$ids[] = '<ref-' . $i . '@gemeente.nl>';
		}

		// The nearest ancestors are at the END, so the bound must keep those.
		$references = $this->resolver->referencesIn(['References' => implode(' ', $ids)], 'References');

		$this->assertCount(ReplyThreadResolver::MAX_REFERENCES, $references);
		$this->assertSame('<ref-199@gemeente.nl>', $references[0], 'the nearest ancestor survives the bound');
	}//end testALongChainIsBounded()

	public function testTextThatIsNotAMessageIdIsIgnored(): void {
		$this->assertSame([], $this->resolver->referencesIn(['In-Reply-To' => 'zie mijn vorige mail'], 'In-Reply-To'));
	}//end testTextThatIsNotAMessageIdIsIgnored()

	public function testOnlyAThreadedResultMayBeFiledWithoutAPerson(): void {
		$this->assertTrue($this->resolver->mayFileAutomatically(ReplyThreadResolver::THREADED));
		$this->assertFalse($this->resolver->mayFileAutomatically(ReplyThreadResolver::UNTHREADED));
		$this->assertFalse($this->resolver->mayFileAutomatically(ReplyThreadResolver::AMBIGUOUS));
	}//end testOnlyAThreadedResultMayBeFiledWithoutAPerson()
}//end class
