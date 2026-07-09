<?php

/**
 * Unit tests for DsarPolicyPackResolver — pack-selector-driven seam resolution.
 *
 * Covers:
 *  - a case's jurisdiction selects the matching pack's seam selectors
 *  - an unknown jurisdiction falls back to the neutral `default` pack
 *  - a case with no jurisdiction resolves the `default` pack
 *  - an empty pack set / unset selector returns null (registry then fails closed)
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Policy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Policy;

use OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test class for DsarPolicyPackResolver.
 */
class DsarPolicyPackResolverTest extends TestCase
{
    /**
     * The two shipped packs (default + an nl-example) as rendered rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function packs(): array
    {
        return [
            [
                'jurisdiction'              => 'default',
                'identityVerifyProvider'    => 'or.default.identity-verify.null',
                'regulatorEscalateProvider' => 'or.default.regulator-escalate.null',
            ],
            [
                'jurisdiction'              => 'nl-example',
                'identityVerifyProvider'    => 'leaf.identity.nl-brp',
                'regulatorEscalateProvider' => 'leaf.regulator.nl-ap',
            ],
        ];
    }//end packs()

    /**
     * Build a resolver whose ObjectService::findAll returns the given rows.
     *
     * @param array<int, array<string, mixed>> $rows Rendered pack rows.
     *
     * @return DsarPolicyPackResolver
     */
    private function resolverReturning(array $rows): DsarPolicyPackResolver
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn($rows);
        return new DsarPolicyPackResolver($objectService, new NullLogger());
    }//end resolverReturning()

    /**
     * A case's jurisdiction selects that pack's seam selectors.
     *
     * @return void
     */
    public function testJurisdictionSelectsPackSelectors(): void
    {
        $resolver = $this->resolverReturning($this->packs());
        $case     = ['jurisdiction' => 'nl-example'];

        $this->assertSame('leaf.identity.nl-brp', $resolver->identityVerifyProviderId($case));
        $this->assertSame('leaf.regulator.nl-ap', $resolver->regulatorEscalateProviderId($case));
    }//end testJurisdictionSelectsPackSelectors()

    /**
     * An unknown jurisdiction falls back to the neutral default pack.
     *
     * @return void
     */
    public function testUnknownJurisdictionFallsBackToDefaultPack(): void
    {
        $resolver = $this->resolverReturning($this->packs());
        $case     = ['jurisdiction' => 'zz-nowhere'];

        $this->assertSame('or.default.identity-verify.null', $resolver->identityVerifyProviderId($case));
        $this->assertSame('or.default.regulator-escalate.null', $resolver->regulatorEscalateProviderId($case));
    }//end testUnknownJurisdictionFallsBackToDefaultPack()

    /**
     * A case with no jurisdiction resolves the default pack.
     *
     * @return void
     */
    public function testMissingJurisdictionResolvesDefaultPack(): void
    {
        $resolver = $this->resolverReturning($this->packs());

        $this->assertSame('or.default.identity-verify.null', $resolver->identityVerifyProviderId([]));
    }//end testMissingJurisdictionResolvesDefaultPack()

    /**
     * No packs at all → null selector (the registry then fails closed).
     *
     * @return void
     */
    public function testNoPacksReturnsNull(): void
    {
        $resolver = $this->resolverReturning([]);

        $this->assertNull($resolver->identityVerifyProviderId(['jurisdiction' => 'nl-example']));
        $this->assertNull($resolver->regulatorEscalateProviderId(['jurisdiction' => 'nl-example']));
    }//end testNoPacksReturnsNull()

    /**
     * A pack that leaves a selector unset yields null for that seam.
     *
     * @return void
     */
    public function testUnsetSelectorOnPackReturnsNull(): void
    {
        $resolver = $this->resolverReturning(
            [
                ['jurisdiction' => 'default', 'identityVerifyProvider' => 'or.default.identity-verify.null'],
            ]
        );

        // identity is set on the pack, regulator is not.
        $this->assertSame('or.default.identity-verify.null', $resolver->identityVerifyProviderId([]));
        $this->assertNull($resolver->regulatorEscalateProviderId([]));
    }//end testUnsetSelectorOnPackReturnsNull()
}//end class
