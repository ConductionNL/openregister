<?php

/**
 * Behaviour tests for LocalConfigurationFile::read(), called directly.
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

use Exception;
use OCA\OpenRegister\Service\Configuration\LocalConfigurationFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The root is three levels above the app data path, as in an installed instance.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\LocalConfigurationFile
 */
class LocalConfigurationFileBehaviourTest extends TestCase
{
    private string $base;

    private string $outside;

    protected function setUp(): void
    {
        $this->base    = sys_get_temp_dir().'/or_localb_'.bin2hex(random_bytes(4));
        $this->outside = $this->base.'-outside';
        mkdir($this->base.'/data/app/x', 0777, true);
        mkdir($this->base.'/config', 0777, true);
        mkdir($this->outside);
        file_put_contents($this->outside.'/secret.json', '{"secret":true}');
        symlink($this->outside.'/secret.json', $this->base.'/config/escape.json');
        file_put_contents($this->base.'/config/broken.json', '{not json');
        file_put_contents($this->base.'/config/list.json', '[1,2]');
        file_put_contents($this->base.'/config/scalar.json', '"text"');
        file_put_contents($this->base.'/config/section.json', '{"x-openregister":true}');
        file_put_contents($this->base.'/config/plain.json', '{"title":"Plain","x-openregister":{"app":"demo"}}');
        file_put_contents($this->base.'/config/traced.json', '{"x-openregister":{"sourceUrl":"https://a.example","sourceType":"url"}}');
    }//end setUp()

    protected function tearDown(): void
    {
        foreach (['escape', 'broken', 'list', 'scalar', 'section', 'plain', 'traced'] as $name) {
            unlink($this->base.'/config/'.$name.'.json');
        }

        unlink($this->outside.'/secret.json');
        rmdir($this->outside);
        rmdir($this->base.'/config');
        rmdir($this->base.'/data/app/x');
        rmdir($this->base.'/data/app');
        rmdir($this->base.'/data');
        rmdir($this->base);
    }//end tearDown()

    private function file(): LocalConfigurationFile
    {
        return new LocalConfigurationFile($this->base.'/data/app/x');
    }//end file()

    public function testAPlainFileIsStampedWithItsPathAndLocalSourceKeepingItsSection(): void
    {
        $this->assertSame(
            ['title' => 'Plain', 'x-openregister' => ['app' => 'demo', 'sourceUrl' => 'config/plain.json', 'sourceType' => 'local']],
            $this->file()->read('config/plain.json')
        );
    }//end testAPlainFileIsStampedWithItsPathAndLocalSourceKeepingItsSection()

    public function testADeclaredSourceIsKept(): void
    {
        $this->assertSame(
            ['x-openregister' => ['sourceUrl' => 'https://a.example', 'sourceType' => 'url']],
            $this->file()->read('config/traced.json')
        );
    }//end testADeclaredSourceIsKept()

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'absolute'       => ['/etc/passwd', 'Invalid configuration file path: /etc/passwd'],
            'traversal'      => ['config/../config/plain.json', 'Invalid configuration file path: config/../config/plain.json'],
            'control char'   => ["config/pl\x1fain.json", "Invalid configuration file path: config/pl\x1fain.json"],
            'missing'        => ['config/nope.json', 'Configuration file not found: config/nope.json'],
            'escaping link'  => ['config/escape.json', 'Configuration file is outside the allowed directory: config/escape.json'],
            'the root'       => ['.', 'Configuration file is outside the allowed directory: .'],
            'broken json'    => ['config/broken.json', 'Invalid JSON in configuration file: Syntax error'],
            'a directory'    => ['config', 'Invalid JSON in configuration file: Syntax error'],
            'a string'       => ['config/scalar.json', 'Configuration file is not a JSON object: config/scalar.json'],
            'scalar section' => ['config/section.json', 'Configuration file is not a JSON object: config/section.json'],
        ];
    }//end refusals()

    #[DataProvider('refusals')]
    public function testAnUnsafeOrUnusableFileIsRefusedWithItsReason(string $path, string $reason): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($reason);
        @$this->file()->read($path);
    }//end testAnUnsafeOrUnusableFileIsRefusedWithItsReason()

    public function testAJsonListIsAcceptedAsAnArrayAndStamped(): void
    {
        $this->assertSame(
            [1, 2, 'x-openregister' => ['sourceUrl' => 'config/list.json', 'sourceType' => 'local']],
            $this->file()->read('config/list.json')
        );
    }//end testAJsonListIsAcceptedAsAnArrayAndStamped()

    public function testANonexistentRootFallsBackAndFindsNothing(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Configuration file not found: config/plain.json');
        (new LocalConfigurationFile('/nonexistent/or/data/app'))->read('config/plain.json');
    }//end testANonexistentRootFallsBackAndFindsNothing()
}//end class
