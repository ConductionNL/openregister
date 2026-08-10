<?php

declare(strict_types=1);

/**
 * DenialFinaliseGuard Unit Tests
 *
 * Verifies the lifecycle guard the head's `finaliseDenial` transition binds via
 * `requires`: blocked without a regulatorReference, allowed with one,
 * draftDenial never gated, and fail-closed on absent/blank/non-string values.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Lifecycle
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\OpenRegister\Service\Gdpr\Lifecycle\DenialFinaliseGuard;
use PHPUnit\Framework\TestCase;

/**
 * Test class for DenialFinaliseGuard.
 */
class DenialFinaliseGuardTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var DenialFinaliseGuard
     */
    private DenialFinaliseGuard $guard;

    /**
     * Set up the SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->guard = new DenialFinaliseGuard();

    }//end setUp()

    /**
     * The guard implements the OR lifecycle guard contract (so the registry
     * resolves it by FQCN).
     *
     * @return void
     */
    public function testImplementsLifecycleGuardInterface(): void
    {
        $this->assertInstanceOf(LifecycleGuardInterface::class, $this->guard);

    }//end testImplementsLifecycleGuardInterface()

    /**
     * finaliseDenial is refused when regulatorReference is empty.
     *
     * @return void
     */
    public function testFinaliseBlockedWithoutRegulatorReference(): void
    {
        $result = $this->guard->check(['regulatorReference' => ''], 'finaliseDenial', 'handler1');
        $this->assertFalse($result->isAllowed());
        $this->assertNotNull($result->getMessage());

    }//end testFinaliseBlockedWithoutRegulatorReference()

    /**
     * finaliseDenial is permitted once a regulatorReference is present.
     *
     * @return void
     */
    public function testFinaliseAllowedWithRegulatorReference(): void
    {
        $result = $this->guard->check(
            ['regulatorReference' => 'REG-REF-PLACEHOLDER-0001'],
            'finaliseDenial',
            'handler1'
        );
        $this->assertTrue($result->isAllowed());

    }//end testFinaliseAllowedWithRegulatorReference()

    /**
     * Fail closed on an unidentified caller: `LifecycleValidationListener`
     * passes `''` when there is no session user, and an unattributable
     * finalise must be refused even when the regulatorReference is present.
     *
     * @param string $userId The caller uid variant.
     *
     * @dataProvider unidentifiedCallerProvider
     *
     * @return void
     */
    public function testFinaliseBlockedForUnidentifiedCaller(string $userId): void
    {
        $result = $this->guard->check(
            ['regulatorReference' => 'REG-REF-PLACEHOLDER-0001'],
            'finaliseDenial',
            $userId
        );
        $this->assertFalse(
            $result->isAllowed(),
            'A finalise with no attributable caller must be denied'
        );
        $this->assertNotNull($result->getMessage());

    }//end testFinaliseBlockedForUnidentifiedCaller()

    /**
     * Caller-identity variants that are not a usable uid.
     *
     * @return array<string, array{0: string}>
     */
    public static function unidentifiedCallerProvider(): array
    {
        return [
            'no session user' => [''],
            'whitespace only' => ['   '],
        ];

    }//end unidentifiedCallerProvider()

    /**
     * draftDenial stays ungated even for an unidentified caller — the
     * identity requirement is a FINALISE-time precondition only.
     *
     * @return void
     */
    public function testDraftDenialNotGatedForUnidentifiedCaller(): void
    {
        $result = $this->guard->check(['denialGround' => 'some-ground'], 'draftDenial', '');
        $this->assertTrue($result->isAllowed());

    }//end testDraftDenialNotGatedForUnidentifiedCaller()

    /**
     * draftDenial is never gated — allowed even with no regulatorReference.
     *
     * @return void
     */
    public function testDraftDenialNotGated(): void
    {
        $result = $this->guard->check(['denialGround' => 'some-ground'], 'draftDenial', 'handler1');
        $this->assertTrue($result->isAllowed());

    }//end testDraftDenialNotGated()

    /**
     * Fail-closed: a missing key, a null, whitespace, or a non-string all deny
     * finalise.
     *
     * @param array<string, mixed> $payload The case payload variant.
     *
     * @dataProvider failClosedProvider
     *
     * @return void
     */
    public function testFailsClosedOnUnsatisfiablePrecondition(array $payload): void
    {
        $result = $this->guard->check($payload, 'finaliseDenial', 'handler1');
        $this->assertFalse($result->isAllowed());

    }//end testFailsClosedOnUnsatisfiablePrecondition()

    /**
     * Fail-closed payload variants.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function failClosedProvider(): array
    {
        return [
            'missing key'     => [[]],
            'null value'      => [['regulatorReference' => null]],
            'whitespace only' => [['regulatorReference' => '   ']],
            'non-string'      => [['regulatorReference' => 12345]],
        ];

    }//end failClosedProvider()
}//end class
