<?php

/**
 * OpenRegister Attribution Formatter.
 *
 * Builds the markdown attribution block prepended to GitHub issue bodies when the
 * server-PAT fallback path is taken in `GitHubHandler::createIssue`. Encapsulates
 * the user-manager + URL-generator lookups so the handler does not have to import
 * them directly (keeps PHPMD's CouplingBetweenObjects on the handler under
 * threshold).
 *
 * Sanitization (markdown-injection-safe display name, scheme-validated instance
 * URL) is scoped to task 1.15 (follow-up); this skeleton implementation builds the
 * prefix from the raw values returned by IUserManager + IURLGenerator.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
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

namespace OCA\OpenRegister\Service\Configuration;

use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Formats the attribution block for server-PAT submissions.
 *
 * @package OCA\OpenRegister\Service\Configuration
 *
 * @psalm-suppress UnusedClass
 */
class AttributionFormatter
{
    /**
     * AttributionFormatter constructor.
     *
     * @param IUserManager  $userManager  User manager for display-name lookup.
     * @param IURLGenerator $urlGenerator URL generator for the absolute instance URL.
     */
    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IURLGenerator $urlGenerator
    ) {
    }//end __construct()

    /**
     * Maximum sanitized display-name length before the attribution prefix truncates (task 1.15).
     */
    private const DISPLAY_NAME_MAX_LENGTH = 80;

    /**
     * Markdown / DOM characters stripped from a user-controlled display name before it is
     * embedded in the attribution prefix (task 1.15).
     *
     * The set covers:
     *   - CR / LF (newline injection that could break out of the blockquote)
     *   - `* _ ` `` ` `` `\` (markdown emphasis / code / escape)
     *   - `[ ] ( )` (markdown link syntax)
     *   - `< >` (HTML tag boundaries — GitHub renders <... in code spans but defensive)
     *
     * Each occurrence is replaced with a single space; consecutive spaces are not collapsed,
     * matching the spec scenario "Display name with markdown-injection characters" example.
     */
    private const DISPLAY_NAME_STRIP_CHARS = ["\r", "\n", '*', '_', '[', ']', '(', ')', '`', '<', '>', '\\'];

    /**
     * Generic prefix used when the instance URL fails scheme validation (task 1.15).
     */
    private const FALLBACK_PREFIX = "> Submitted via Nextcloud OpenRegister\n\n---\n\n";

    /**
     * Build the attribution block to prepend to the issue body.
     *
     * Format on the happy path:
     *   `> Submitted by **<sanitized_display_name>** via <validated_instance_url>\n\n---\n\n`
     *
     * Sanitization (task 1.15):
     *   - Display name: every character in `DISPLAY_NAME_STRIP_CHARS` replaced with a space,
     *     then truncated to `DISPLAY_NAME_MAX_LENGTH` characters. Falls back to the bare UID
     *     when the user lookup fails (deleted user, missing IUserManager record, etc.).
     *   - Instance URL: must begin with `https://` OR `http://localhost`. On scheme failure
     *     the attribution falls back to the URL-free generic block (`FALLBACK_PREFIX`).
     *
     * @param string $userId Nextcloud UID of the submitting user.
     *
     * @return string Attribution block ending with `\n\n---\n\n`.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-2
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-15
     */
    public function format(string $userId): string
    {
        $user           = $this->userManager->get($userId);
        $rawDisplayName = $user !== null ? $user->getDisplayName() : $userId;
        $displayName    = $this->sanitizeDisplayName(rawDisplayName: $rawDisplayName);
        $instanceUrl    = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');

        if ($this->isValidInstanceUrl(url: $instanceUrl) === false) {
            return self::FALLBACK_PREFIX;
        }

        return '> Submitted by **'.$displayName.'** via '.$instanceUrl."\n\n---\n\n";
    }//end format()

    /**
     * Strip markdown / DOM significance characters from the display name and truncate.
     *
     * @param string $rawDisplayName Raw display name from IUserManager (or the UID fallback).
     *
     * @return string Sanitized + truncated display name (≤ DISPLAY_NAME_MAX_LENGTH chars).
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-15
     */
    private function sanitizeDisplayName(string $rawDisplayName): string
    {
        $sanitized = str_replace(self::DISPLAY_NAME_STRIP_CHARS, ' ', $rawDisplayName);
        if (strlen($sanitized) > self::DISPLAY_NAME_MAX_LENGTH) {
            $sanitized = substr($sanitized, 0, self::DISPLAY_NAME_MAX_LENGTH);
        }

        return $sanitized;
    }//end sanitizeDisplayName()

    /**
     * Accept only `https://` URLs and `http://localhost` for development. Other schemes (`http:`
     * on a non-localhost host, `javascript:`, `data:`, etc.) cause the caller to fall back to
     * the generic attribution prefix with no URL embedded.
     *
     * @param string $url Absolute instance URL from IURLGenerator::getAbsoluteURL.
     *
     * @return bool True when safe to embed, false to trigger the fallback prefix.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-15
     */
    private function isValidInstanceUrl(string $url): bool
    {
        if (str_starts_with($url, 'https://') === true) {
            return true;
        }

        if (str_starts_with($url, 'http://localhost') === true) {
            return true;
        }

        return false;
    }//end isValidInstanceUrl()
}//end class
