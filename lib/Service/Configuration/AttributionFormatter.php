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
     * Build the attribution block to prepend to the issue body.
     *
     * Format: `> Submitted by **<display_name>** via <instance_url>\n\n---\n\n`.
     *
     * Falls back to the bare UID when the user lookup fails (deleted user, etc.) so
     * the attribution line never crashes the submission flow.
     *
     * @param string $userId Nextcloud UID of the submitting user.
     *
     * @return string Attribution block ending with `\n\n---\n\n`.
     *
     * @spec openspec/changes/add-features-roadmap-menu/tasks.md#task-2
     */
    public function format(string $userId): string
    {
        $user        = $this->userManager->get($userId);
        $displayName = $user !== null ? $user->getDisplayName() : $userId;
        $instanceUrl = rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');

        return '> Submitted by **'.$displayName.'** via '.$instanceUrl."\n\n---\n\n";
    }//end format()
}//end class
