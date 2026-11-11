<?php

/**
 * Behaviour tests for GitHubGuards: the guard runner and the anonymous / exhausted rate-limit paths.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
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

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\DistinctKeyBudget;
use OCA\OpenRegister\Service\Configuration\GitHubGuards;
use OCA\OpenRegister\Service\Configuration\RateLimiterService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\GitHubGuards
 */
class GitHubGuardsBehaviourTest extends TestCase
{
    /** @var IUserSession&MockObject */
    private IUserSession $session;

    /** @var RateLimiterService&MockObject */
    private RateLimiterService $limiter;

    protected function setUp(): void
    {
        $this->session = $this->createMock(IUserSession::class);
        $this->limiter = $this->createMock(RateLimiterService::class);
    }//end setUp()

    private function guards(): GitHubGuards
    {
        return new GitHubGuards($this->createMock(IAppConfig::class), $this->session, $this->limiter);
    }//end guards()

    public function testRunGuardsReturnsTheFirstRefusalAndSkipsTheRest(): void
    {
        $refusal = new JSONResponse(['error' => 'first'], 403);
        $calls   = [];

        $result = $this->guards()->runGuards(
            [
                static function () use (&$calls): ?JSONResponse {
                    $calls[] = 'a';
                    return null;
                },
                static function () use (&$calls, $refusal): ?JSONResponse {
                    $calls[] = 'b';
                    return $refusal;
                },
                static function () use (&$calls): ?JSONResponse {
                    $calls[] = 'c';
                    return new JSONResponse([], 500);
                },
            ]
        );

        $this->assertSame($refusal, $result);
        $this->assertSame(['a', 'b'], $calls);
    }//end testRunGuardsReturnsTheFirstRefusalAndSkipsTheRest()

    public function testRunGuardsPassesWhenEveryGuardPassesOrThereAreNone(): void
    {
        $this->assertNull($this->guards()->runGuards([static fn(): ?JSONResponse => null, static fn(): ?JSONResponse => null]));
        $this->assertNull($this->guards()->runGuards([]));
    }//end testRunGuardsPassesWhenEveryGuardPassesOrThereAreNone()

    public function testAnAnonymousCallerIsNotCounted(): void
    {
        $this->session->method('getUser')->willReturn(null);
        $this->limiter->expects($this->never())->method('isOperational');
        $this->limiter->expects($this->never())->method('consumeFromBudget');

        $this->assertNull($this->guards()->enforceGetRateLimit('k-1'));
    }//end testAnAnonymousCallerIsNotCounted()

    public function testAnExhaustedBudgetIsA429WithRetryAfterForThatUser(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->session->method('getUser')->willReturn($user);
        $this->limiter->method('isOperational')->willReturn(true);
        $this->limiter->expects($this->once())
            ->method('consumeFromBudget')
            ->with(
                $this->callback(static fn (DistinctKeyBudget $budget): bool => $budget->bucketKey === 'getmiss:alice' && $budget->maxKeys === 10 && $budget->windowSeconds > 0),
                'k-9'
            )
            ->willReturn(42);

        $response = $this->guards()->enforceGetRateLimit('k-9');

        $this->assertSame(429, $response->getStatus());
        $this->assertSame(['error' => 'user_rate_limited', 'retry_after' => 42], $response->getData());
        $this->assertSame('42', $response->getHeaders()['Retry-After']);
    }//end testAnExhaustedBudgetIsA429WithRetryAfterForThatUser()
}//end class
