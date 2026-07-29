<?php

/**
 * ContextChat contract stubs for PHPUnit runs without the context_chat app.
 *
 * `OCP\ContextChat\*` ships with Nextcloud's context_chat app, which is NOT a
 * composer dependency of OpenRegister — `ContentProvider` implements the
 * interface and `ContextChatService` resolves the manager lazily, exactly the
 * same optional-app seam the Doriath stubs cover. Without the app installed the
 * symbols simply do not exist, so `ContentProviderTest` died with
 * `Interface "OCP\ContextChat\IContentProvider" not found` and every
 * `createMock(IContentManager::class)` raised `UnknownTypeException` — 19 of the
 * suite's errors, none of them a defect in OpenRegister.
 *
 * Every declaration is `class_exists`/`interface_exists`-guarded, so the moment
 * the real app IS installed these are skipped and the genuine contract wins.
 *
 * The surface mirrors what OpenRegister actually consumes, taken from the call
 * sites rather than guessed:
 *
 *   - IContentManager::isContextChatAvailable(): bool
 *   - IContentManager::submitContent(string $appId, array $items): void
 *   - IContentManager::deleteContent(string $appId, string $providerId, array $ids): void
 *   - IContentProvider::getId/getAppId/getItemUrl/triggerInitialImport
 *   - ContentItem — the value object handed to submitContent()
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCP\ContextChat {

    if (interface_exists('OCP\ContextChat\IContentProvider') === false) {
        /**
         * Registers a source of indexable content with context_chat.
         */
        interface IContentProvider
        {
            /**
             * The provider's own id, unique within its app.
             *
             * @return string The id.
             */
            public function getId(): string;

            /**
             * The app the provider belongs to.
             *
             * @return string The app id.
             */
            public function getAppId(): string;

            /**
             * A user-facing URL for one indexed item.
             *
             * @param string $id The item id.
             *
             * @return string The URL.
             */
            public function getItemUrl(string $id): string;

            /**
             * Called when context_chat wants the provider's whole corpus.
             *
             * @return void
             */
            public function triggerInitialImport(): void;
        }
    }

    if (interface_exists('OCP\ContextChat\IContentManager') === false) {
        /**
         * The context_chat side: accepts and removes indexed content.
         */
        interface IContentManager
        {
            /**
             * Whether context_chat is installed and ready to accept content.
             *
             * @return boolean Whether it is available.
             */
            public function isContextChatAvailable(): bool;

            /**
             * Hand content to context_chat for indexing.
             *
             * @param string             $appId The submitting app.
             * @param array<int, object> $items The content items.
             *
             * @return void
             */
            public function submitContent(string $appId, array $items): void;

            /**
             * Remove previously-submitted content.
             *
             * @param string            $appId      The submitting app.
             * @param string            $providerId The provider that owns it.
             * @param array<int,string> $itemIds    The item ids to drop.
             *
             * @return void
             */
            public function deleteContent(string $appId, string $providerId, array $itemIds): void;

            /**
             * Announce a provider so context_chat will ask it for content.
             *
             * @param string $appId        The owning app.
             * @param string $providerId   The provider's id.
             * @param string $providerClass The provider's FQCN.
             *
             * @return void
             */
            public function registerContentProvider(string $appId, string $providerId, string $providerClass): void;
        }
    }

    if (class_exists('OCP\ContextChat\ContentItem') === false) {
        /**
         * One indexable item handed to {@see IContentManager::submitContent()}.
         */
        class ContentItem
        {
            /**
             * Build a content item.
             *
             * @param string             $itemId       The item's id within its provider.
             * @param string             $providerId   The provider that owns it.
             * @param string             $title        Human-readable title.
             * @param string             $content      The indexable text.
             * @param string             $documentType A coarse type label.
             * @param \DateTime          $lastModified When it last changed.
             * @param array<int, string> $users        The users allowed to see it.
             */
            public function __construct(
                public string $itemId,
                public string $providerId,
                public string $title,
                public string $content,
                public string $documentType,
                public \DateTime $lastModified,
                public array $users
            ) {

            }//end __construct()
        }
    }
}

namespace OCP\ContextChat\Events {

    if (class_exists('OCP\ContextChat\Events\ContentProviderRegisterEvent') === false) {
        /**
         * Dispatched so apps can announce their content providers.
         *
         * The real event carries the manager and forwards `registerContentProvider`
         * to it, which is exactly what `ContentProviderRegistrationListener` relies
         * on — so the stub forwards too rather than merely recording the call.
         */
        class ContentProviderRegisterEvent extends \OCP\EventDispatcher\Event
        {
            /**
             * Build the event over the manager that will receive registrations.
             *
             * @param \OCP\ContextChat\IContentManager $contentManager The manager.
             */
            public function __construct(private \OCP\ContextChat\IContentManager $contentManager)
            {
                parent::__construct();

            }//end __construct()

            /**
             * Announce a provider.
             *
             * @param string $appId         The owning app.
             * @param string $providerId    The provider's id.
             * @param string $providerClass The provider's FQCN.
             *
             * @return void
             */
            public function registerContentProvider(string $appId, string $providerId, string $providerClass): void
            {
                $this->contentManager->registerContentProvider($appId, $providerId, $providerClass);

            }//end registerContentProvider()
        }
    }
}
