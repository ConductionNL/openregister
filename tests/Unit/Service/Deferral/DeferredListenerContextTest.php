<?php

declare(strict_types=1);

namespace Unit\Service\Deferral;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use PHPUnit\Framework\TestCase;

/**
 * Round-trip and tolerance tests for the deferral context value object.
 */
class DeferredListenerContextTest extends TestCase
{
    public function testJobArgumentRoundTripPreservesActorAndEntries(): void
    {
        $entries = [
            [
                'uuid'     => 'uuid-1',
                'register' => 'reg',
                'schema'   => 'sch',
                'version'  => '0.0.2',
                'trigger'  => 'updated',
                'oldData'  => ['title' => 'before'],
            ],
            [
                'uuid'     => 'uuid-2',
                'register' => 'reg',
                'schema'   => 'sch',
                'version'  => null,
                'trigger'  => 'created',
            ],
        ];

        $context = new DeferredListenerContext(userId: 'alice', orgUuid: 'org-1', entries: $entries);

        // Simulate the oc_jobs.argument JSON round trip.
        $wire    = json_decode(json_encode($context->toJobArguments()), true);
        $rebuilt = DeferredListenerContext::fromJobArguments($wire);

        $this->assertSame('alice', $rebuilt->getUserId());
        $this->assertSame('org-1', $rebuilt->getOrganisationUuid());
        $this->assertSame($entries, $rebuilt->getEntries());
    }

    public function testNullActorRoundTrips(): void
    {
        $context = new DeferredListenerContext(userId: null, orgUuid: null, entries: [['uuid' => 'u']]);

        $wire    = json_decode(json_encode($context->toJobArguments()), true);
        $rebuilt = DeferredListenerContext::fromJobArguments($wire);

        $this->assertNull($rebuilt->getUserId());
        $this->assertNull($rebuilt->getOrganisationUuid());
        $this->assertSame([['uuid' => 'u']], $rebuilt->getEntries());
    }

    public function testMalformedArgumentDegradesToEmptyContext(): void
    {
        $rebuilt = DeferredListenerContext::fromJobArguments('not-an-array');

        $this->assertNull($rebuilt->getUserId());
        $this->assertNull($rebuilt->getOrganisationUuid());
        $this->assertSame([], $rebuilt->getEntries());
    }

    public function testNonArrayEntriesAndEmptyStringsAreDropped(): void
    {
        $rebuilt = DeferredListenerContext::fromJobArguments(
            [
                'userId'           => '',
                'organisationUuid' => 42,
                'entries'          => ['scalar', ['uuid' => 'ok'], null],
            ]
        );

        $this->assertNull($rebuilt->getUserId());
        $this->assertNull($rebuilt->getOrganisationUuid());
        $this->assertSame([['uuid' => 'ok']], $rebuilt->getEntries());
    }
}
