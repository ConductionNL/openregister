<?php

/**
 * Behaviour tests for CalendarFeedTokenService guards: minting, revoking, read notes and resolving.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calendar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Calendar;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\CalendarFeedToken;
use OCA\OpenRegister\Db\CalendarFeedTokenMapperInterface;
use OCA\OpenRegister\Service\Calendar\CalendarFeedTokenService;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Calendar\CalendarFeedTokenService
 * @uses   \OCA\OpenRegister\Db\CalendarFeedToken
 */
class CalendarFeedTokenServiceGuardsTest extends TestCase
{
    /** @var CalendarFeedTokenMapperInterface&MockObject */
    private CalendarFeedTokenMapperInterface $mapper;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(CalendarFeedTokenMapperInterface::class);
    }//end setUp()

    private function service(): CalendarFeedTokenService
    {
        return new CalendarFeedTokenService(
            $this->mapper,
            $this->createMock(ISecureRandom::class),
            $this->createMock(IURLGenerator::class)
        );
    }//end service()

    private function draft(string $scopeType, string $scopeId): CalendarFeedToken
    {
        $draft = new CalendarFeedToken();
        $draft->setScopeType($scopeType);
        $draft->setScopeId($scopeId);
        return $draft;
    }//end draft()

    private function owned(string $userId, ?DateTime $revokedAt = null): CalendarFeedToken
    {
        $token = new CalendarFeedToken();
        $token->setId(4);
        $token->setUserId($userId);
        $token->setRevokedAt($revokedAt);
        return $token;
    }//end owned()

    public function testMintRefusesAMissingScopeIdAndAMissingUserBeforeWriting(): void
    {
        $this->mapper->expects($this->never())->method('insert');
        $service = $this->service();

        try {
            $service->mint('alice', $this->draft(CalendarFeedToken::SCOPE_SCHEMA, '  '));
            $this->fail('A token without scope id must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('A feed token must name the schema or view it covers.', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A logged-in user is required to mint a calendar feed token.');
        $service->mint('  ', $this->draft(CalendarFeedToken::SCOPE_SCHEMA, 'schema-1'));
    }//end testMintRefusesAMissingScopeIdAndAMissingUserBeforeWriting()

    public function testAMintWithATtlExpiresThatManySecondsLaterAndCarriesItsFeedUrl(): void
    {
        $random = $this->createMock(ISecureRandom::class);
        $random->method('generate')->willReturn('tok123');
        $urls = $this->createMock(IURLGenerator::class);
        $urls->method('linkToRoute')->with('openregister.calendarFeed.feed', ['token' => 'tok123'])->willReturn('/feed/tok123');
        $urls->method('getAbsoluteURL')->with('/feed/tok123')->willReturn('https://nc.example/feed/tok123');
        $this->mapper->method('insert')->willReturnArgument(0);
        $draft  = $this->draft(' '.CalendarFeedToken::SCOPE_VIEW.' ', ' view-9 ');
        $before = time();

        $data = (new CalendarFeedTokenService($this->mapper, $random, $urls))->mint(' alice ', $draft, 3600);

        $this->assertSame('https://nc.example/feed/tok123', $data['url']);
        $this->assertSame(['tok123', 'alice', CalendarFeedToken::SCOPE_VIEW, 'view-9'], [$draft->getToken(), $draft->getUserId(), $draft->getScopeType(), $draft->getScopeId()]);
        $lifetime = $draft->getExpiresAt()->getTimestamp() - $draft->getCreatedAt()->getTimestamp();
        $this->assertSame(3600, $lifetime);
        $this->assertGreaterThanOrEqual($before, $draft->getCreatedAt()->getTimestamp());
    }//end testAMintWithATtlExpiresThatManySecondsLaterAndCarriesItsFeedUrl()

    public function testAMintWithoutAPositiveTtlNeverExpires(): void
    {
        $this->mapper->method('insert')->willReturnArgument(0);
        $draft = $this->draft(CalendarFeedToken::SCOPE_SCHEMA, 's-1');

        $this->service()->mint('alice', $draft, 0);

        $this->assertNull($draft->getExpiresAt());
    }//end testAMintWithoutAPositiveTtlNeverExpires()

    public function testABlankTokenResolvesToNothingWithoutALookup(): void
    {
        $this->mapper->expects($this->never())->method('findByToken');

        $this->assertNull($this->service()->resolve('   '));
    }//end testABlankTokenResolvesToNothingWithoutALookup()

    public function testARevokedTokenDoesNotResolve(): void
    {
        $this->mapper->method('findByToken')->with('abc')->willReturn($this->owned('alice', new DateTime('-1 day')));

        $this->assertNull($this->service()->resolve(' abc '));
    }//end testARevokedTokenDoesNotResolve()

    public function testNoteReadStampsTheReadAndSwallowsAFailingWrite(): void
    {
        $token = $this->owned('alice');
        $this->mapper->expects($this->once())->method('update')->with($token)->willThrowException(new RuntimeException('db locked'));

        $this->service()->noteRead($token);

        $this->assertInstanceOf(DateTime::class, $token->getLastReadAt());
    }//end testNoteReadStampsTheReadAndSwallowsAFailingWrite()

    public function testRevokeWithoutAUserIsRefusedWithoutALookup(): void
    {
        $this->mapper->expects($this->never())->method('findById');

        $this->assertFalse($this->service()->revoke(4, ' '));
    }//end testRevokeWithoutAUserIsRefusedWithoutALookup()

    public function testRevokingAnAlreadyRevokedTokenSucceedsWithoutRewriting(): void
    {
        $revokedAt = new DateTime('2026-01-01T00:00:00Z');
        $this->mapper->method('findById')->with(4)->willReturn($this->owned('alice', $revokedAt));
        $this->mapper->expects($this->never())->method('update');

        $this->assertTrue($this->service()->revoke(4, 'alice'));
    }//end testRevokingAnAlreadyRevokedTokenSucceedsWithoutRewriting()

    public function testRevokingSomebodyElsesTokenIsRefused(): void
    {
        $this->mapper->method('findById')->willReturn($this->owned('bob'));
        $this->mapper->expects($this->never())->method('update');

        $this->assertFalse($this->service()->revoke(4, 'alice'));
    }//end testRevokingSomebodyElsesTokenIsRefused()

    public function testListingForABlankUserIsEmptyWithoutALookup(): void
    {
        $this->mapper->expects($this->never())->method('findByUser');

        $this->assertSame([], $this->service()->listForUser(''));
    }//end testListingForABlankUserIsEmptyWithoutALookup()
}//end class
