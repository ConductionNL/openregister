<?php
/**
 * The save path is the seam that matters.
 *
 * A validation that only guarded the HTTP controller would be bypassed by the
 * configuration importer, which is exactly the path a flow arrives on. These
 * tests pin the refusal to `ObjectCreatingEvent`/`ObjectUpdatingEvent`, which
 * `MagicMapper` dispatches for every persisted object.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\FlowNodePreflightListener;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * @covers \OCA\OpenRegister\Listener\FlowNodePreflightListener
 */
class FlowNodePreflightListenerTest extends TestCase
{

    /**
     * An object entity carrying the given data.
     *
     * @param array $data The object data.
     *
     * @return ObjectEntity
     */
    private function entity(array $data): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('11111111-1111-1111-1111-111111111111');
        $entity->setObject($data);

        return $entity;
    }

    /**
     * A flow document naming a type this instance cannot run.
     *
     * @return array
     */
    private function badFlow(): array
    {
        return [
            'name'  => 'hydra-file-findings',
            'nodes' => [['id' => 'a'], ['id' => 'b']],
            'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b', 'type' => 'openregister.explode']],
        ];
    }

    /**
     * A preflight stub that refuses everything graph-shaped.
     *
     * @param boolean $refuse Whether assertRunnable throws.
     *
     * @return FlowNodePreflight
     */
    private function preflight(bool $refuse): FlowNodePreflight
    {
        $preflight = $this->createMock(FlowNodePreflight::class);
        $preflight->method('looksLikeFlow')->willReturnCallback(
            static fn (array $data): bool => (isset($data['nodes']) === true && isset($data['edges']) === true)
        );

        if ($refuse === true) {
            $preflight->method('assertRunnable')->willThrowException(
                new UnexpectedValueException('Flow "hydra-file-findings" names 1 step type(s) this instance cannot run')
            );
        }

        return $preflight;
    }

    /**
     * Creation is stopped and carries a message naming the problem.
     */
    public function testCreationIsRefused(): void
    {
        $listener = new FlowNodePreflightListener(
            $this->preflight(refuse: true),
            $this->createMock(LoggerInterface::class)
        );

        $event = new ObjectCreatingEvent($this->entity($this->badFlow()));
        $listener->handle($event);

        $this->assertTrue($event->isPropagationStopped());
        $this->assertSame('flow-node-type-unavailable', $event->getErrors()['code']);
        $this->assertStringContainsString('cannot run', $event->getErrors()['message']);
    }

    /**
     * An update to an existing flow is refused the same way.
     */
    public function testUpdateIsRefused(): void
    {
        $listener = new FlowNodePreflightListener(
            $this->preflight(refuse: true),
            $this->createMock(LoggerInterface::class)
        );

        $event = new ObjectUpdatingEvent(
            newObject: $this->entity($this->badFlow()),
            oldObject: $this->entity([])
        );
        $listener->handle($event);

        $this->assertTrue($event->isPropagationStopped());
    }

    /**
     * An ordinary object is untouched — the listener must not gate every save.
     */
    public function testANonFlowObjectIsUntouched(): void
    {
        $listener = new FlowNodePreflightListener(
            $this->preflight(refuse: true),
            $this->createMock(LoggerInterface::class)
        );

        $event = new ObjectCreatingEvent($this->entity(['title' => 'a lead', 'status' => 'open']));
        $listener->handle($event);

        $this->assertFalse($event->isPropagationStopped());
        $this->assertSame([], $event->getErrors());
    }

    /**
     * A runnable flow saves.
     */
    public function testARunnableFlowIsAllowed(): void
    {
        $listener = new FlowNodePreflightListener(
            $this->preflight(refuse: false),
            $this->createMock(LoggerInterface::class)
        );

        $event = new ObjectCreatingEvent($this->entity($this->badFlow()));
        $listener->handle($event);

        $this->assertFalse($event->isPropagationStopped());
    }

    /**
     * Preflight failing for a reason of its own must not block unrelated saves.
     *
     * The run-time refusal in FlowNodeRegistry::get() is still the backstop,
     * which is precisely the situation that held before this listener existed.
     */
    public function testAnInternalFailureDoesNotBlockTheSave(): void
    {
        $preflight = $this->createMock(FlowNodePreflight::class);
        $preflight->method('looksLikeFlow')->willReturn(true);
        $preflight->method('assertRunnable')->willThrowException(new \RuntimeException('registry exploded'));

        $listener = new FlowNodePreflightListener($preflight, $this->createMock(LoggerInterface::class));

        $event = new ObjectCreatingEvent($this->entity($this->badFlow()));
        $listener->handle($event);

        $this->assertFalse($event->isPropagationStopped());
    }
}
