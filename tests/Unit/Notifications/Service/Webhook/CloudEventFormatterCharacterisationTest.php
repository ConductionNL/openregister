<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Webhook;

use OCA\OpenRegister\Notifications\Service\Webhook\CloudEventFormatter;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * A request that also exposes its raw body, as Nextcloud's Request does.
 */
interface RawBodyRequest extends IRequest
{
    /**
     * The raw request body.
     *
     * @return string The body.
     */
    public function getRawBody(): string;
}

/**
 * Pins the envelope key order and the request data CloudEventFormatter builds.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Webhook\CloudEventFormatter
 */
class CloudEventFormatterCharacterisationTest extends TestCase
{

    private const ENVELOPE_KEYS = ['specversion', 'type', 'source', 'id', 'time', 'datacontenttype', 'subject', 'dataschema', 'data', 'openregister'];

    /**
     * A request with a raw body and parameters.
     *
     * @return RawBodyRequest The request.
     */
    private function request(string $rawBody, array $params): RawBodyRequest
    {
        $request = $this->createMock(RawBodyRequest::class);
        $request->method('getRawBody')->willReturn($rawBody);
        $request->method('getParams')->willReturn($params);
        $request->method('getMethod')->willReturn('PUT');
        $request->method('getPathInfo')->willReturn('/api/objects/reg/sch/uuid-1');
        $request->method('getServerProtocol')->willReturn('https');
        $request->method('getServerHost')->willReturn('nc.example');
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): string => $name === 'Content-Type' ? 'application/merge-patch+json' : ''
        );
        return $request;
    }

    public function testEventEnvelope(): void
    {
        $event = (new CloudEventFormatter())->formatAsCloudEvent('object.created', ['a' => 1], null, 'subj');

        $this->assertSame(self::ENVELOPE_KEYS, array_keys($event));
        $this->assertSame(['1.0', 'object.created', '/apps/openregister'], [$event['specversion'], $event['type'], $event['source']]);
        $this->assertSame(['application/json', 'subj', null, ['a' => 1]], [$event['datacontenttype'], $event['subject'], $event['dataschema'], $event['data']]);
        $this->assertSame('openregister', $event['openregister']['app']);
        $this->assertSame('/custom', (new CloudEventFormatter())->formatAsCloudEvent('t', [], '/custom')['source']);
    }

    public function testRequestEnvelopeMergesJsonBodyUnderParamsAndExtraDataLast(): void
    {
        $request = $this->request('{"title":"from body","only":"body"}', ['title' => 'from params']);

        $event = (new CloudEventFormatter())->formatRequestAsCloudEvent($request, 'object.updating', ['path' => 'override', 'extra' => true]);

        $this->assertSame(self::ENVELOPE_KEYS, array_keys($event));
        $this->assertSame('object.updating', $event['type']);
        $this->assertSame('application/merge-patch+json', $event['datacontenttype']);
        $this->assertSame(['method', 'path', 'queryParams', 'headers', 'body', 'extra'], array_keys($event['data']));
        $this->assertSame('PUT', $event['data']['method']);
        $this->assertSame('override', $event['data']['path']);
        $this->assertSame(['title' => 'from params'], $event['data']['queryParams']);
        $this->assertSame(['title' => 'from params', 'only' => 'body'], $event['data']['body']);
        $this->assertTrue($event['data']['extra']);
    }

    public function testInvalidOrEmptyRawBodyKeepsOnlyTheParams(): void
    {
        $formatter = new CloudEventFormatter();

        $invalid = $formatter->formatRequestAsCloudEvent($this->request('{not json', ['p' => 1]), 't');
        $empty   = $formatter->formatRequestAsCloudEvent($this->request('', ['p' => 2]), 't');

        $this->assertSame(['p' => 1], $invalid['data']['body']);
        $this->assertSame(['p' => 2], $empty['data']['body']);
    }
}
