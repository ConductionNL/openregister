<?php

/**
 * Unit tests for AgentRunRequestedEvent (ADR-041 cross-app command event).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace Unit\Event;

use OCA\OpenRegister\Event\AgentRunRequestedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

class AgentRunRequestedEventTest extends TestCase
{
    private function makeEvent(): AgentRunRequestedEvent
    {
        return new AgentRunRequestedEvent(
            subjectUuid: 'obj-uuid-1',
            subjectRegister: '1',
            subjectSchema: '10',
            agent: 'agent-uuid-1',
            skill: 'classify-tender',
            prompt: 'Classify this tender: Rex',
            resultField: 'categorySlug',
            requiresApproval: true,
            mode: 'async',
            flowName: 'classify-tender'
        );
    }

    public function testExtendsEvent(): void
    {
        $this->assertInstanceOf(Event::class, $this->makeEvent());
    }

    public function testGetters(): void
    {
        $event = $this->makeEvent();

        $this->assertSame('obj-uuid-1', $event->getSubjectUuid());
        $this->assertSame('1', $event->getSubjectRegister());
        $this->assertSame('10', $event->getSubjectSchema());
        $this->assertSame('agent-uuid-1', $event->getAgent());
        $this->assertSame('classify-tender', $event->getSkill());
        $this->assertSame('Classify this tender: Rex', $event->getPrompt());
        $this->assertSame('categorySlug', $event->getResultField());
        $this->assertTrue($event->isRequiresApproval());
        $this->assertSame('async', $event->getMode());
        $this->assertSame('classify-tender', $event->getFlowName());
        $this->assertNotSame('', $event->getCorrelationId());
    }

    public function testCorrelationIdIsUniquePerInstance(): void
    {
        $first  = $this->makeEvent();
        $second = $this->makeEvent();

        $this->assertNotSame($first->getCorrelationId(), $second->getCorrelationId());
    }

    public function testGetPayloadMatchesGetters(): void
    {
        $event   = $this->makeEvent();
        $payload = $event->getPayload();

        $this->assertSame(
            [
                'subjectUuid'      => $event->getSubjectUuid(),
                'subjectRegister'  => $event->getSubjectRegister(),
                'subjectSchema'    => $event->getSubjectSchema(),
                'agent'            => $event->getAgent(),
                'skill'            => $event->getSkill(),
                'prompt'           => $event->getPrompt(),
                'resultField'      => $event->getResultField(),
                'requiresApproval' => $event->isRequiresApproval(),
                'mode'             => $event->getMode(),
                'flowName'         => $event->getFlowName(),
                'correlationId'    => $event->getCorrelationId(),
            ],
            $payload
        );
    }

    public function testOptionalSkillDefaultsToNull(): void
    {
        $event = new AgentRunRequestedEvent(
            subjectUuid: 'obj-uuid-1',
            subjectRegister: '1',
            subjectSchema: '10',
            agent: 'agent-uuid-1',
            skill: null,
            prompt: 'hi',
            resultField: 'result',
            requiresApproval: false,
            mode: 'async',
            flowName: 'x'
        );

        $this->assertNull($event->getSkill());
        $this->assertNull($event->getPayload()['skill']);
    }
}
