<?php

/**
 * Behaviour test for DeploymentPreviewService::refusalsFor(): only the refusing values of a set.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment
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

namespace OCA\OpenRegister\Tests\Unit\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\ConfigurationDraft;
use OCA\OpenRegister\Db\ConfigurationDraftSet;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationAddress;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationDraftService;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationKeyRegistry;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationLayer;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationProvenance;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationSnapshot;
use OCA\OpenRegister\Service\ConfigurationDeployment\ConfigurationValueStore;
use OCA\OpenRegister\Service\ConfigurationDeployment\DeploymentPreviewService;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real key registry and value objects, so it names no covered class
 * (which would require listing every one of them as used).
 */
class DeploymentPreviewRefusalsForTest extends TestCase
{
    private function draft(string $key, mixed $value, mixed $base): ConfigurationDraft
    {
        $draft = new ConfigurationDraft();
        $draft->setUuid('draft-'.$key);
        $draft->setSetUuid('set-1');
        $draft->setLayer(ConfigurationLayer::INSTANCE);
        $draft->setLayerRef(null);
        $draft->setConfigKey($key);
        $draft->setDraftValue(['value' => $value]);
        $draft->setBaseValue(['value' => $base]);
        $draft->setBasePresent(true);
        $draft->setRemoves(false);
        return $draft;
    }//end draft()

    private function service(array $drafts): DeploymentPreviewService
    {
        $draftService = $this->createMock(ConfigurationDraftService::class);
        $draftService->method('draftsIn')->willReturn($drafts);
        $store = $this->createMock(ConfigurationValueStore::class);
        $store->method('read')->willReturnCallback(
            static fn(string $layer, ?string $ref, string $key): ConfigurationSnapshot => match ($key) {
                'rbac'  => new ConfigurationSnapshot(new ConfigurationAddress($layer, null, $key), ['enabled' => true], true, new ConfigurationProvenance('dep-1', 'admin')),
                default => ConfigurationSnapshot::absent(new ConfigurationAddress($layer, $ref, $key)),
            }
        );
        return new DeploymentPreviewService($draftService, $store, new ConfigurationKeyRegistry());
    }//end service()

    private function set(): ConfigurationDraftSet
    {
        $set = new ConfigurationDraftSet();
        $set->setUuid('set-1');
        $set->setState(ConfigurationDraftSet::STATE_OPEN);
        return $set;
    }//end set()

    public function testOnlyTheRefusingValuesAreReturned(): void
    {
        $refusals = $this->service(
            [
                $this->draft('rbac', ['enabled' => false], ['enabled' => true]),
                $this->draft('not_a_setting', 'x', 'x'),
            ]
        )->refusalsFor($this->set());

        $this->assertCount(1, $refusals);
        $this->assertSame('not_a_setting', $refusals[0]['key']);
        $this->assertSame(DeploymentPreviewService::REFUSAL_UNKNOWN_KEY, $refusals[0]['refusal']);
    }//end testOnlyTheRefusingValuesAreReturned()

    public function testASetWithoutRefusalsHasNone(): void
    {
        $this->assertSame(
            [],
            $this->service([$this->draft('rbac', ['enabled' => false], ['enabled' => true])])->refusalsFor($this->set())
        );
    }//end testASetWithoutRefusalsHasNone()
}//end class
