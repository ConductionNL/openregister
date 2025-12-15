<?php
/**
 * Add these DI registrations to lib/AppInfo/Application.php
 * in the register() method, after the existing service registrations
 */

// ============================================
// Settings Handlers Registration
// ============================================

// 1. SearchBackendHandler
$context->registerService(\OCA\OpenRegister\Service\Settings\SearchBackendHandler::class, function (IContainer $c) {
    return new \OCA\OpenRegister\Service\Settings\SearchBackendHandler(
        $c->get(IConfig::class),
        $c->get(LoggerInterface::class),
        'openregister'
    );
});

// 2. LlmSettingsHandler
$context->registerService(\OCA\OpenRegister\Service\Settings\LlmSettingsHandler::class, function (IContainer $c) {
    return new \OCA\OpenRegister\Service\Settings\LlmSettingsHandler(
        $c->get(IConfig::class),
        'openregister'
    );
});

// 3. FileSettingsHandler
$context->registerService(\OCA\OpenRegister\Service\Settings\FileSettingsHandler::class, function (IContainer $c) {
    return new \OCA\OpenRegister\Service\Settings\FileSettingsHandler(
        $c->get(IConfig::class),
        'openregister'
    );
});

// 4. ObjectRetentionHandler
$context->registerService(\OCA\OpenRegister\Service\Settings\ObjectRetentionHandler::class, function (IContainer $c) {
    return new \OCA\OpenRegister\Service\Settings\ObjectRetentionHandler(
        $c->get(IConfig::class),
        'openregister'
    );
});

// 5. CacheSettingsHandler
$context->registerService(\OCA\OpenRegister\Service\Settings\CacheSettingsHandler::class, function (IContainer $c) {
    return new \OCA\OpenRegister\Service\Settings\CacheSettingsHandler(
        $c->get(ICacheFactory::class),
        $c->get(\OCA\OpenRegister\Service\SchemaCacheService::class),
        $c->get(\OCA\OpenRegister\Service\Schemas\FacetCacheHandler::class),
        null, // objectCacheService - lazy loaded
        $c    // container for lazy loading
    );
});

// 6. SolrSettingsHandler
$context->registerService(\OCA\OpenRegister\Service\Settings\SolrSettingsHandler::class, function (IContainer $c) {
    return new \OCA\OpenRegister\Service\Settings\SolrSettingsHandler(
        $c->get(IConfig::class),
        null, // objectCacheService - lazy loaded
        $c,   // container for lazy loading
        'openregister'
    );
});

// 7. ConfigurationSettingsHandler
$context->registerService(\OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler::class, function (IContainer $c) {
    return new \OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler(
        $c->get(IConfig::class),
        $c->get(IGroupManager::class),
        $c->get(IUserManager::class),
        $c->get(\OCA\OpenRegister\Db\OrganisationMapper::class),
        $c->get(LoggerInterface::class),
        'openregister'
    );
});

// 8. ValidationOperationsHandler - verify it's registered
// (This handler should already exist - verify registration)

// ============================================
// Update SettingsService to inject handlers
// ============================================

// Find the existing SettingsService registration and update it to:
$context->registerService(\OCA\OpenRegister\Service\SettingsService::class, function (IContainer $c) {
    return new \OCA\OpenRegister\Service\SettingsService(
        $c->get(IConfig::class),
        $c->get(\OCA\OpenRegister\Db\AuditTrailMapper::class),
        $c->get(ICacheFactory::class),
        $c->get(IGroupManager::class),
        $c->get(LoggerInterface::class),
        $c->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class),
        $c->get(\OCA\OpenRegister\Db\OrganisationMapper::class),
        $c->get(\OCA\OpenRegister\Service\SchemaCacheService::class),
        $c->get(\OCA\OpenRegister\Service\Schemas\FacetCacheHandler::class),
        $c->get(\OCA\OpenRegister\Db\SearchTrailMapper::class),
        $c->get(IUserManager::class),
        $c->get(IDBConnection::class),
        null, // objectCacheService - lazy loaded
        $c,   // container
        'openregister',
        // ADD THESE 8 NEW HANDLER INJECTIONS:
        $c->get(\OCA\OpenRegister\Service\Settings\SearchBackendHandler::class),
        $c->get(\OCA\OpenRegister\Service\Settings\LlmSettingsHandler::class),
        $c->get(\OCA\OpenRegister\Service\Settings\FileSettingsHandler::class),
        $c->get(\OCA\OpenRegister\Service\Settings\ObjectRetentionHandler::class),
        $c->get(\OCA\OpenRegister\Service\Settings\CacheSettingsHandler::class),
        $c->get(\OCA\OpenRegister\Service\Settings\SolrSettingsHandler::class),
        $c->get(\OCA\OpenRegister\Service\Settings\ConfigurationSettingsHandler::class),
        $c->get(\OCA\OpenRegister\Service\Settings\ValidationOperationsHandler::class)
    );
});

