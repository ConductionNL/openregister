<?php

/**
 * AttributionFormatter Unit Tests.
 *
 * Covers display-name + URL sanitization (task 1.15) for the server-PAT-fallback
 * attribution prefix prepended to GitHub issue bodies.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\AttributionFormatter;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for `AttributionFormatter`.
 *
 * @package OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @covers \OCA\OpenRegister\Service\Configuration\AttributionFormatter
 *
 * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-15
 */
class AttributionFormatterTest extends TestCase
{
    /**
     * @return void
     */
    public function testHappyPathBuildsCanonicalPrefix(): void
    {
        $formatter = $this->buildFormatter(displayName: 'Alice Example', instanceUrl: 'https://cloud.example.org/');

        $prefix = $formatter->format(userId: 'alice');

        $this->assertStringContainsString('> Submitted by **Alice Example**', $prefix);
        $this->assertStringContainsString('https://cloud.example.org', $prefix);
        $this->assertStringEndsWith("\n\n---\n\n", $prefix);

        // Per the spec scenario, the separator MUST appear exactly once.
        $this->assertEquals(1, substr_count($prefix, "\n\n---\n\n"));
    }//end testHappyPathBuildsCanonicalPrefix()

    /**
     * @return void
     *
     * @spec openspec/changes/add-features-roadmap-menu/specs/github-issue-proxy/spec.md (scenario "Display name with markdown-injection characters")
     */
    public function testMarkdownInjectionInDisplayNameIsStripped(): void
    {
        $formatter = $this->buildFormatter(
            displayName: "** via evil.com\n\n[Forged Admin]",
            instanceUrl: 'https://cloud.example.org/'
        );

        $prefix = $formatter->format(userId: 'malicious');

        // All markdown-significant chars stripped to spaces.
        $this->assertStringNotContainsString('**', explode('** ', $prefix)[1] ?? '', 'no markdown emphasis chars should survive in the name');
        $this->assertStringNotContainsString('[', $prefix);
        $this->assertStringNotContainsString(']', $prefix);
        // The separator still appears exactly once — name injection cannot break out of the blockquote.
        $this->assertEquals(1, substr_count($prefix, "\n\n---\n\n"));
    }//end testMarkdownInjectionInDisplayNameIsStripped()

    /**
     * @return void
     */
    public function testOversizedDisplayNameTruncatedToEighty(): void
    {
        $longName  = str_repeat('A', 200);
        $formatter = $this->buildFormatter(displayName: $longName, instanceUrl: 'https://cloud.example.org/');

        $prefix = $formatter->format(userId: 'alice');

        // Extract the part between **'s.
        if (preg_match('/\*\*(.*?)\*\*/s', $prefix, $matches) === 1) {
            $this->assertLessThanOrEqual(80, strlen($matches[1]));
        } else {
            $this->fail('display name not found between ** ** markers');
        }
    }//end testOversizedDisplayNameTruncatedToEighty()

    /**
     * @return void
     */
    public function testNonHttpsInstanceUrlFallsBackToGenericPrefix(): void
    {
        $formatter = $this->buildFormatter(
            displayName: 'Alice',
            instanceUrl: 'http://example.org/'
        );

        $prefix = $formatter->format(userId: 'alice');

        $this->assertStringContainsString('> Submitted via Nextcloud OpenRegister', $prefix);
        $this->assertStringNotContainsString('http://example.org', $prefix);
        // No display name embedded in the fallback prefix.
        $this->assertStringNotContainsString('Alice', $prefix);
    }//end testNonHttpsInstanceUrlFallsBackToGenericPrefix()

    /**
     * @return void
     */
    public function testHttpLocalhostIsAccepted(): void
    {
        $formatter = $this->buildFormatter(
            displayName: 'Dev User',
            instanceUrl: 'http://localhost:8080/'
        );

        $prefix = $formatter->format(userId: 'dev');

        $this->assertStringContainsString('> Submitted by **Dev User**', $prefix);
        $this->assertStringContainsString('http://localhost:8080', $prefix);
    }//end testHttpLocalhostIsAccepted()

    /**
     * @return void
     */
    public function testMissingUserFallsBackToUidAsDisplayName(): void
    {
        $userManager  = $this->createMock(IUserManager::class);
        $urlGenerator = $this->createMock(IURLGenerator::class);
        $userManager->method('get')->willReturn(null);
        $urlGenerator->method('getAbsoluteURL')->willReturn('https://cloud.example.org/');

        $formatter = new AttributionFormatter(userManager: $userManager, urlGenerator: $urlGenerator);

        $prefix = $formatter->format(userId: 'deleted-uid');

        $this->assertStringContainsString('> Submitted by **deleted-uid**', $prefix);
    }//end testMissingUserFallsBackToUidAsDisplayName()

    /**
     * Helper: build an AttributionFormatter with a mocked IUserManager + IURLGenerator that
     * return the supplied display name + URL.
     *
     * @param string $displayName Display name returned by IUserManager::get()->getDisplayName().
     * @param string $instanceUrl Absolute URL returned by IURLGenerator::getAbsoluteURL().
     *
     * @return AttributionFormatter
     */
    private function buildFormatter(string $displayName, string $instanceUrl): AttributionFormatter
    {
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn($displayName);

        $userManager = $this->createMock(IUserManager::class);
        $userManager->method('get')->willReturn($user);

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('getAbsoluteURL')->willReturn($instanceUrl);

        return new AttributionFormatter(userManager: $userManager, urlGenerator: $urlGenerator);
    }//end buildFormatter()
}//end class
