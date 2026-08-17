<?php

/**
 * The listener that keeps the grantable-rights index honest.
 *
 * It is one method, and it is the half of the index's correctness that fails
 * SILENTLY: if it does not fire, a right removed from a schema keeps being
 * offered and nothing about the stale answer looks wrong. There is no TTL
 * behind it to paper over a miss.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Event\SchemaCreatedEvent;
use OCA\OpenRegister\Event\SchemaDeletedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Listener\GrantableRightsInvalidationListener;
use OCA\OpenRegister\Service\Authorization\GrantableRightsIndex;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage metadata is deliberately absent. This suite runs under
 * `beStrictAboutCoverageMetadata="true"`, where the deprecated
 * covers-default-class docblock form does not resolve — an annotated test
 * records NO coverage at all and is reported risky. (Named rather than written
 * literally: a docblock parser reading this comment would find the annotation
 * and defeat the point of removing it.) Leaving the annotations off
 * is what the well-covered suites in this repo do, and it is why this file's
 * subject counts toward coverage instead of reading 0%.
 */
class GrantableRightsInvalidationListenerTest extends TestCase {

	private GrantableRightsIndex&MockObject $index;

	private LoggerInterface&MockObject $logger;

	private GrantableRightsInvalidationListener $listener;

	/**
	 * Wire the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->index = $this->createMock(GrantableRightsIndex::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new GrantableRightsInvalidationListener($this->index, $this->logger);
	}//end setUp()

	/**
	 * A schema write invalidates the index.
	 *
	 * @return void
	 */
	public function testAWriteInvalidatesTheIndex(): void {
		$this->index->expects($this->once())->method('invalidate');

		$this->listener->handle($this->createMock(SchemaUpdatedEvent::class));
	}//end testAWriteInvalidatesTheIndex()

	/**
	 * Every schema write does, not just updates. A create that did not
	 * invalidate would leave a newly-offered right unlistable; a delete that
	 * did not would keep offering a right whose schema is gone.
	 *
	 * @return void
	 */
	public function testCreateAndDeleteInvalidateToo(): void {
		$this->index->expects($this->exactly(2))->method('invalidate');

		$this->listener->handle($this->createMock(SchemaCreatedEvent::class));
		$this->listener->handle($this->createMock(SchemaDeletedEvent::class));
	}//end testCreateAndDeleteInvalidateToo()

	/**
	 * The listener is deliberately blind to WHICH schema changed — the index is
	 * a single cache entry covering every schema, so there is no partial
	 * invalidation to do and inspecting the event would only add a way to get
	 * it wrong. An unrelated event reaching it still invalidates.
	 *
	 * @return void
	 */
	public function testItDoesNotInspectTheEvent(): void {
		$this->index->expects($this->once())->method('invalidate');

		$this->listener->handle(new class extends Event {
		});
	}//end testItDoesNotInspectTheEvent()

	/**
	 * 🔴 A failed invalidation must not escape — it would turn a cache problem
	 * into a failed schema write — but it MUST be logged at error level. That
	 * log line is the only trace that the index is now stale, and a stale
	 * permission menu is a bug that looks exactly like a correct one.
	 *
	 * @return void
	 */
	public function testAFailedInvalidationIsSwallowedButLoggedLoudly(): void {
		$this->index->method('invalidate')->willThrowException(new \RuntimeException('cache down'));

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('may now be stale'),
				$this->callback(
					static fn (array $context): bool => ($context['error'] ?? null) === 'cache down'
				)
			);

		$this->listener->handle($this->createMock(SchemaUpdatedEvent::class));
	}//end testAFailedInvalidationIsSwallowedButLoggedLoudly()
}//end class
