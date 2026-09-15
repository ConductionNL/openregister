<?php

/**
 * Unit tests for TextBlockService — canned text, inserted rather than retyped.
 *
 * Covers the scoping a handler's list obeys, the substitution, and the
 * placeholder nothing supplies, which is left standing on purpose.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Timeline;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\TextBlock;
use OCA\OpenRegister\Db\TextBlockMapper;
use OCA\OpenRegister\Service\Timeline\TextBlockService;
use OCA\OpenRegister\Service\Timeline\TimelineValidationException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * TextBlockServiceTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class TextBlockServiceTest extends TestCase {
	/**
	 * Block mapper mock.
	 *
	 * @var TextBlockMapper&MockObject
	 */
	private TextBlockMapper&MockObject $blocks;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Service under test.
	 *
	 * @var TextBlockService
	 */
	private TextBlockService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->blocks = $this->createMock(TextBlockMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);

		$this->service = new TextBlockService($this->blocks, $this->userSession, $this->groupManager);
	}

	private function block(string $body): TextBlock {
		$block = new TextBlock();
		$block->setSlug('ontvangstbevestiging');
		$block->setBody($body);

		return $block;
	}

	private function signIn(array $groups = []): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('kcc');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('getUserGroupIds')->willReturn($groups);
	}

	public function testAStandardAnswerIsInsertedWithItsVariablesFilledIn(): void {
		$this->blocks->method('findBySlug')->willReturn(
			$this->block('Uw aanvraag {{ zaaknummer }} is ontvangen op {{ datum }}.')
		);

		$text = $this->service->insert(
			'ontvangstbevestiging',
			['zaaknummer' => 'Z-2026-0044', 'datum' => '14 september 2026']
		);

		$this->assertSame('Uw aanvraag Z-2026-0044 is ontvangen op 14 september 2026.', $text);
	}

	public function testAPlaceholderNobodySuppliesIsLeftStanding(): void {
		$this->blocks->method('findBySlug')->willReturn($this->block('Uw aanvraag {{ zaaknummer }} is ontvangen.'));

		// A blank space reads as a finished sentence and gets sent. A visible
		// placeholder does not.
		$this->assertSame(
			'Uw aanvraag {{ zaaknummer }} is ontvangen.',
			$this->service->insert('ontvangstbevestiging', [])
		);
	}

	public function testAnArrayValueIsNotSubstitutedIntoASentence(): void {
		$this->blocks->method('findBySlug')->willReturn($this->block('Betreft {{ onderwerpen }}.'));

		$this->assertSame(
			'Betreft {{ onderwerpen }}.',
			$this->service->insert('ontvangstbevestiging', ['onderwerpen' => ['a', 'b']])
		);
	}

	public function testABlockNobodyAdministeredIsRefused(): void {
		$this->blocks->method('findBySlug')->willReturn(null);

		$this->expectException(TimelineValidationException::class);
		$this->service->insert('ontvangstbevestiging');
	}

	public function testABlockWithoutASlugOrABodyIsRefused(): void {
		$refused = 0;
		foreach ([['body' => 'x'], ['slug' => 'x']] as $payload) {
			try {
				$this->service->declareBlock($payload);
			} catch (TimelineValidationException $e) {
				$refused++;
			}
		}

		$this->assertSame(2, $refused);
	}

	public function testAnAnonymousCallerSeesOnlyTheUnscopedBlocks(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->blocks->expects($this->once())->method('findInScope')
			->with('zaken', 'zaak', [])
			->willReturn([]);

		$this->service->listBlocks('zaken', 'zaak');
	}

	public function testTheListIsScopedToTheCallersOwnGroups(): void {
		$this->signIn(['kcc', 'balie']);
		$this->blocks->expects($this->once())->method('findInScope')
			->with('zaken', 'zaak', ['kcc', 'balie'])
			->willReturn([]);

		$this->service->listBlocks('zaken', 'zaak');
	}

	public function testDeclaringABlockThatExistsRewritesIt(): void {
		$this->blocks->method('findBySlug')->willReturn($this->block('oud'));
		$this->blocks->expects($this->never())->method('insert');
		$this->blocks->expects($this->once())->method('update')
			->willReturnCallback(static fn (TextBlock $block): TextBlock => $block);

		$block = $this->service->declareBlock(['slug' => 'ontvangstbevestiging', 'body' => 'nieuw']);

		$this->assertSame('nieuw', $block->getBody());
	}
}
