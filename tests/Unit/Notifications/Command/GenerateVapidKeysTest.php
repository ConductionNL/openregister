<?php

/**
 * The VAPID key command keeps an existing keypair unless forced and stores a fresh one otherwise.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Command;

use OCA\OpenRegister\Notifications\Command\GenerateVapidKeys;
use OCA\OpenRegister\Notifications\Service\WebPush\WebPushService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Behaviour of the `openregister:web-push:generate-vapid` command.
 */
class GenerateVapidKeysTest extends TestCase
{

    private IAppConfig&MockObject $appConfig;


    protected function setUp(): void
    {
        parent::setUp();
        $this->appConfig = $this->createMock(IAppConfig::class);

    }//end setUp()


    public function testCommandIsRegisteredUnderItsNameWithAForceFlag(): void
    {
        $command = new GenerateVapidKeys($this->appConfig);

        $this->assertSame('openregister:web-push:generate-vapid', $command->getName());
        $this->assertSame('f', $command->getDefinition()->getOption('force')->getShortcut());
        $this->assertFalse($command->getDefinition()->getOption('force')->acceptValue());

    }//end testCommandIsRegisteredUnderItsNameWithAForceFlag()


    public function testExistingKeypairIsKeptWithoutForce(): void
    {
        $this->appConfig->method('getValueString')
            ->with('openregister', WebPushService::VAPID_PUBLIC_KEY, '')
            ->willReturn('PUBKEY');
        $this->appConfig->expects($this->never())->method('setValueString');

        $tester = new CommandTester(new GenerateVapidKeys($this->appConfig));
        $exit   = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('A VAPID keypair already exists. Re-run with --force to rotate it.', $tester->getDisplay());
        $this->assertStringContainsString('Public key: PUBKEY', $tester->getDisplay());

    }//end testExistingKeypairIsKeptWithoutForce()


    public function testForceRotatesAnExistingKeypair(): void
    {
        $this->appConfig->method('getValueString')->willReturn('OLDKEY');
        $stored = $this->captureWrites();

        $tester = new CommandTester(new GenerateVapidKeys($this->appConfig));
        $exit   = $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertKeypairStored(stored: $stored, display: $tester->getDisplay());
        $this->assertNotSame('OLDKEY', $stored[WebPushService::VAPID_PUBLIC_KEY]['value']);

    }//end testForceRotatesAnExistingKeypair()


    public function testMissingKeypairIsGeneratedAndThePrivateKeyStoredSensitive(): void
    {
        $this->appConfig->method('getValueString')->willReturn('');
        $stored = $this->captureWrites();

        $tester = new CommandTester(new GenerateVapidKeys($this->appConfig));
        $exit   = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertKeypairStored(stored: $stored, display: $tester->getDisplay());

    }//end testMissingKeypairIsGeneratedAndThePrivateKeyStoredSensitive()


    /**
     * @return \ArrayObject<string, array{value: string, lazy: bool, sensitive: bool}>
     */
    private function captureWrites(): \ArrayObject
    {
        $stored = new \ArrayObject();
        $this->appConfig->expects($this->exactly(2))->method('setValueString')->willReturnCallback(
            static function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use ($stored): bool {
                $stored[$key] = ['app' => $app, 'value' => $value, 'lazy' => $lazy, 'sensitive' => $sensitive];
                return true;
            }
        );
        return $stored;

    }//end captureWrites()


    /**
     * @param \ArrayObject<string, array<string, mixed>> $stored  Captured writes
     * @param string                                     $display Command output
     */
    private function assertKeypairStored(\ArrayObject $stored, string $display): void
    {
        $public  = $stored[WebPushService::VAPID_PUBLIC_KEY];
        $private = $stored[WebPushService::VAPID_PRIVATE_KEY];

        $this->assertSame('openregister', $public['app']);
        $this->assertNotSame('', $public['value']);
        $this->assertFalse($public['sensitive']);
        $this->assertSame('openregister', $private['app']);
        $this->assertNotSame('', $private['value']);
        $this->assertTrue($private['sensitive']);
        $this->assertStringContainsString('VAPID keypair generated and stored in app config.', $display);
        $this->assertStringContainsString('Public key: '.$public['value'], $display);
        $this->assertStringNotContainsString($private['value'], $display);

    }//end assertKeypairStored()
}//end class
