<?php

declare(strict_types=1);

namespace Unit\Service\Search;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Search\PlaceholderResolver;
use OCP\IUserSession;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class PlaceholderResolverTest extends TestCase
{
    /** @var IUserSession&MockObject */
    private $userSession;
    private PlaceholderResolver $r;

    protected function setUp(): void
    {
        $this->userSession = $this->createMock(IUserSession::class);
        $this->r = new PlaceholderResolver($this->userSession);
    }

    public function testNonStringPassesThrough(): void
    {
        $this->assertSame(42, $this->r->resolve(42));
        $this->assertSame(['a','b'], $this->r->resolve(['a','b']));
    }

    public function testNonPlaceholderStringPassesThrough(): void
    {
        $this->assertSame('hello', $this->r->resolve('hello'));
    }

    public function testNowReturnsDateTime(): void
    {
        $v = $this->r->resolve('$now');
        $this->assertInstanceOf(DateTimeImmutable::class, $v);
    }

    public function testStartOfMonthIsFirstOfMonth(): void
    {
        $v = $this->r->resolve('$startOfMonth');
        $this->assertInstanceOf(DateTimeImmutable::class, $v);
        $this->assertSame('01', $v->format('d'));
    }

    public function testStartOfYearIsJanuaryFirst(): void
    {
        $v = $this->r->resolve('$startOfYear');
        $this->assertInstanceOf(DateTimeImmutable::class, $v);
        $this->assertSame('01-01', $v->format('m-d'));
    }

    public function testNowMinus7Days(): void
    {
        $now = new DateTimeImmutable('now');
        $v = $this->r->resolve('$now-7d');
        $this->assertInstanceOf(DateTimeImmutable::class, $v);
        $diff = $now->diff($v)->days;
        // Should be ~7 days ago.
        $this->assertGreaterThanOrEqual(6, $diff);
        $this->assertLessThanOrEqual(8, $diff);
    }

    public function testStartOfMonthMinus1Month(): void
    {
        $current = new DateTimeImmutable('first day of this month');
        $v = $this->r->resolve('$startOfMonth-1');
        $this->assertInstanceOf(DateTimeImmutable::class, $v);
        // Should be one month before $startOfMonth.
        $expected = $current->modify('-1 months');
        $this->assertSame($expected->format('Y-m'), $v->format('Y-m'));
    }

    public function testCurrentUserUnauthenticated(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->assertSame('', $this->r->resolve('$currentUser'));
    }

    public function testCurrentUserAuthenticated(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $this->userSession->method('getUser')->willReturn($user);
        $this->assertSame('admin', $this->r->resolve('$currentUser'));
    }

    public function testResolveArrayRecurses(): void
    {
        $resolved = $this->r->resolveArray([
            'a' => 'plain',
            'b' => '$now',
            'c' => ['nested' => '$startOfMonth'],
        ]);
        $this->assertSame('plain', $resolved['a']);
        $this->assertInstanceOf(DateTimeImmutable::class, $resolved['b']);
        $this->assertInstanceOf(DateTimeImmutable::class, $resolved['c']['nested']);
    }
}
