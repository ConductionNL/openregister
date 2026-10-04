<?php

return [
    'resources' => [
        'Registers' => ['url' => 'api/registers'],
        'Schemas' => ['url' => 'api/schemas'],
        'Sources' => ['url' => 'api/sources'],
        'Configurations' => ['url' => 'api/configurations'],
        'Applications' => ['url' => 'api/applications'],
        'Agents' => ['url' => 'api/agents'],
        'Endpoints' => ['url' => 'api/endpoints'],
        'Mappings' => ['url' => 'api/mappings'],
        'Consumers' => ['url' => 'api/consumers'],
    ],
    'routes' => [
                                                                                    
                                                                              
                                                              
                                                                                   
        ['name' => 'setup#status',    'url' => '/api/setup/status',            'verb' => 'GET'],
        ['name' => 'setup#runAction', 'url' => '/api/setup/action/{actionId}', 'verb' => 'POST', 'requirements' => ['actionId' => '[a-z0-9\\-]+']],
        ['name' => 'setup#saveConfig', 'url' => '/api/setup/config',           'verb' => 'POST'],
        ['name' => 'federation#objects', 'url' => '/api/federation/{shareToken}/objects',      'verb' => 'GET', 'requirements' => ['shareToken' => '[^/]+']],
        ['name' => 'federation#object',  'url' => '/api/federation/{shareToken}/objects/{id}', 'verb' => 'GET', 'requirements' => ['shareToken' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'federation#meta',    'url' => '/api/federation/{shareToken}/meta',         'verb' => 'GET', 'requirements' => ['shareToken' => '[^/]+']],
                                                                           
        ['name' => 'federation#createObject', 'url' => '/api/federation/{shareToken}/objects',      'verb' => 'POST',   'requirements' => ['shareToken' => '[^/]+']],
        ['name' => 'federation#updateObject', 'url' => '/api/federation/{shareToken}/objects/{id}', 'verb' => 'PUT',    'requirements' => ['shareToken' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'federation#deleteObject', 'url' => '/api/federation/{shareToken}/objects/{id}', 'verb' => 'DELETE', 'requirements' => ['shareToken' => '[^/]+', 'id' => '[^/]+']],
                                                                            
        ['name' => 'federation#shares',      'url' => '/api/federation/shares',      'verb' => 'GET'],
        ['name' => 'federation#createShare', 'url' => '/api/federation/shares',      'verb' => 'POST'],
        ['name' => 'federation#revokeShare', 'url' => '/api/federation/shares/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],

                                                                                  
                                                                                   
                                                                                      
                                                                              
                                                                                    
                                                                                  
                                                                                     
        ['name' => 'credential#index',         'url' => '/api/credentials',                       'verb' => 'GET'],
        ['name' => 'credential#providers',     'url' => '/api/credentials/providers',             'verb' => 'GET'],
                                                                                
                                                                                   
                                                                     
        ['name' => 'credential#sharedWithMe',  'url' => '/api/credentials/shared-with-me',        'verb' => 'GET'],
        ['name' => 'credential#shares',        'url' => '/api/credentials/{id}/shares',           'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'credential#updateShares',  'url' => '/api/credentials/{id}/shares',           'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'credential#create',        'url' => '/api/credentials',                       'verb' => 'POST'],
        ['name' => 'credential#update',        'url' => '/api/credentials/{id}',                  'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'credential#destroy',       'url' => '/api/credentials/{id}',                  'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'credential#registerApp',   'url' => '/api/credentials/apps/{appId}/register', 'verb' => 'POST',   'requirements' => ['appId' => '[a-z0-9_-]+']],
        ['name' => 'credential#brokerRequest', 'url' => '/api/credentials/{id}/request',           'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'credential#sessionBrokerRequest', 'url' => '/api/credentials/{id}/session-request', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
                                                                                  
                                                                            
                                                                               
                                                                               
                                                                                   
                                                                                   
                                                                                 
                                                                 
        ['name' => 'credentialOauth2#start',          'url' => '/api/credentials/oauth2/start',     'verb' => 'POST'],
        ['name' => 'credentialOauth2#disconnect',     'url' => '/api/credentials/oauth2/{id}',      'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'credentialOauth2#callback',       'url' => '/oauth2/callback',                  'verb' => 'GET'],
        ['name' => 'credentialOauth2#clientMetadata', 'url' => '/oauth2/client-metadata.json',      'verb' => 'GET'],

                                                           
                                                                                    
                                                                                      
        ['name' => 'webPush#vapidPublicKey', 'url' => '/webpush/vapid-public-key', 'verb' => 'GET'],
        ['name' => 'webPush#subscribe',      'url' => '/webpush/subscription',     'verb' => 'POST'],
        ['name' => 'webPush#unsubscribe',    'url' => '/webpush/subscription',     'verb' => 'DELETE'],
        ['name' => 'webPush#hexIcon',  'url' => '/webpush/icon/{app}',  'verb' => 'GET', 'requirements' => ['app' => '[a-z0-9_-]+']],
        ['name' => 'webPush#hexBadge', 'url' => '/webpush/badge/{app}', 'verb' => 'GET', 'requirements' => ['app' => '[a-z0-9_-]+']],

                                                           
                                                                      
        ['name' => 'integrations#index', 'url' => '/api/integrations', 'verb' => 'GET'],
        ['name' => 'integrations#show',  'url' => '/api/integrations/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

                                                            
                                                                      
                                                                                
                                                                               
                                                                             
        ['name' => 'objectActions#invoke', 'url' => '/api/objects/{register}/{schema}/{id}/actions/{action}', 'verb' => 'POST',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'action' => '[^/]+']],
        ['name' => 'objectIntegrations#index',   'url' => '/api/objects/{register}/{schema}/{id}/integrations/{integrationId}',            'verb' => 'GET',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'integrationId' => '[^/]+']],
        ['name' => 'objectIntegrations#show',    'url' => '/api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}', 'verb' => 'GET',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'integrationId' => '[^/]+', 'entityId' => '[^/]+']],
        ['name' => 'objectIntegrations#create',  'url' => '/api/objects/{register}/{schema}/{id}/integrations/{integrationId}',            'verb' => 'POST',   'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'integrationId' => '[^/]+']],
        ['name' => 'objectIntegrations#update',  'url' => '/api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}', 'verb' => 'PUT',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'integrationId' => '[^/]+', 'entityId' => '[^/]+']],
        ['name' => 'objectIntegrations#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/integrations/{integrationId}/{entityId}', 'verb' => 'DELETE', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'integrationId' => '[^/]+', 'entityId' => '[^/]+']],

                                                                                   
                                                                                 
                                                                           
                                                                           
        ['name' => 'objectSharing#scope',        'url' => '/api/objects/{register}/{schema}/{id}/scope',            'verb' => 'GET',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectSharing#setScope',     'url' => '/api/objects/{register}/{schema}/{id}/scope',            'verb' => 'PUT',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectSharing#shares',       'url' => '/api/objects/{register}/{schema}/{id}/shares',           'verb' => 'GET',    'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectSharing#createShare',  'url' => '/api/objects/{register}/{schema}/{id}/shares',           'verb' => 'POST',   'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectSharing#destroyShare', 'url' => '/api/objects/{register}/{schema}/{id}/shares/{shareId}', 'verb' => 'DELETE', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'shareId' => '[^/]+']],
        ['name' => 'objectSharing#createLink',   'url' => '/api/objects/{register}/{schema}/{id}/links',            'verb' => 'POST',   'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectSharing#inviteByEmail','url' => '/api/objects/{register}/{schema}/{id}/invitations',      'verb' => 'POST',   'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],

                                                                             
                                                                            
                                                                           
                                                                          
                                                                         
                         
        ['name' => 'objectPermissions#history', 'url' => '/api/objects/{register}/{schema}/{id}/permissions/history', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'objectPermissions#index',   'url' => '/api/objects/{register}/{schema}/{id}/permissions',         'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],

                                                                                   
                                                                                  
                                                                                  
                                                                                 
                                                                              
                                                    
                                                                         
                                                                               
                                                           
        [
            'name' => 'objectWatchers#watch',
            'url' => '/api/objects/{register}/{schema}/{id}/watch',
            'verb' => 'PUT',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+'],
        ],
        [
            'name' => 'objectWatchers#unwatch',
            'url' => '/api/objects/{register}/{schema}/{id}/watch',
            'verb' => 'DELETE',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+'],
        ],
        [
            'name' => 'objectWatchers#index',
            'url' => '/api/objects/{register}/{schema}/{id}/watchers',
            'verb' => 'GET',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+'],
        ],
        [
            'name' => 'objectWatchers#add',
            'url' => '/api/objects/{register}/{schema}/{id}/watchers/{userId}',
            'verb' => 'PUT',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'userId' => '[^/]+'],
        ],
        [
            'name' => 'objectWatchers#remove',
            'url' => '/api/objects/{register}/{schema}/{id}/watchers/{userId}',
            'verb' => 'DELETE',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'userId' => '[^/]+'],
        ],

                                                                                 
                                                                          
                                                                           
                                                                   
                                                                             
                                                                                
                                                              
                                                                               
                                                                                
        [
            'name' => 'objectFavourite#star',
            'url' => '/api/objects/{register}/{schema}/{id}/favourite',
            'verb' => 'PUT',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+'],
        ],
        [
            'name' => 'objectFavourite#unstar',
            'url' => '/api/objects/{register}/{schema}/{id}/favourite',
            'verb' => 'DELETE',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+'],
        ],

                                                                               
                                                                           
                                                                                 
                                                                               
                                                                               
                                                                                 
                                                 
                                                                               
                                                                                
        [
            'name' => 'objectReadState#show',
            'url' => '/api/objects/{register}/{schema}/{id}/read-state',
            'verb' => 'GET',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+'],
        ],
        [
            'name' => 'objectReadState#markRead',
            'url' => '/api/objects/{register}/{schema}/{id}/read-state',
            'verb' => 'PUT',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+'],
        ],
        [
            'name' => 'objectReadState#markUnread',
            'url' => '/api/objects/{register}/{schema}/{id}/read-state',
            'verb' => 'DELETE',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+'],
        ],

                                                                                
                                                                                 
                                                                               
                                                                              
        ['name' => 'objectShareLink#show', 'url' => '/api/shared/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[^/]+']],

                                                        
        ['name' => 'registers#patch', 'url' => '/api/registers/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#patch', 'url' => '/api/schemas/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'sources#patch', 'url' => '/api/sources/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],

                                                                                                
        ['name' => 'icon#mdi', 'url' => '/api/icon/mdi/{name}', 'verb' => 'GET', 'requirements' => ['name' => '[A-Za-z0-9-]+']],

                                                                                        
        ['name' => 'sources#syncNow',    'url' => '/api/sources/{id}/sync',        'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'sources#syncStatus', 'url' => '/api/sources/{id}/sync-status', 'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],

                                                                                                       
        ['name' => 'sources#testConnection', 'url' => '/api/sources/{id}/test-connection', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'sources#introspect',     'url' => '/api/sources/{id}/introspect',      'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

        ['name' => 'configurations#patch', 'url' => '/api/configurations/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'applications#patch', 'url' => '/api/applications/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'agents#patch', 'url' => '/api/agents/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'endpoints#patch', 'url' => '/api/endpoints/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'mappings#patch', 'url' => '/api/mappings/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'consumers#patch', 'url' => '/api/consumers/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],

                                    
        ['name' => 'mappings#test', 'url' => '/api/mappings/test', 'verb' => 'POST'],

                                     
        ['name' => 'endpoints#test', 'url' => '/api/endpoints/{id}/test', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'endpoints#logs', 'url' => '/api/endpoints/{id}/logs', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'endpoints#logStats', 'url' => '/api/endpoints/{id}/logs/stats', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'endpoints#allLogs', 'url' => '/api/endpoints/logs', 'verb' => 'GET'],

                                                                
                                                                            
                                                                              
                          
        ['name' => 'registerDescriptor#index', 'url' => '/api/register-descriptors', 'verb' => 'GET'],
        ['name' => 'registerDescriptor#import', 'url' => '/api/register-descriptors/{appId}/{slug}/import', 'verb' => 'POST'],

                                                                               
                                                                              
                                                                               
                                                                         
        ['name' => 'workingCalendar#preview', 'url' => '/api/flow-timers/calendars/preview', 'verb' => 'POST'],
                                                                                
                                                                              
                                                                   
        ['name' => 'flowTimerDiagnostic#explain', 'url' => '/api/flow-timers/diagnostic', 'verb' => 'POST'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#update', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'settings#rebase', 'url' => '/api/settings/rebase', 'verb' => 'POST'],
                                                                                                     
        ['name' => 'preferences#getPreference', 'url' => '/api/preferences/{key}', 'verb' => 'GET'],
        ['name' => 'preferences#setPreference', 'url' => '/api/preferences/{key}', 'verb' => 'PUT'],
        ['name' => 'settings#stats', 'url' => '/api/settings/stats', 'verb' => 'GET'],

                                                                          
        ['name' => 'migration#status', 'url' => '/api/migration/status/{register}/{schema}', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+']],
        ['name' => 'migration#migrate', 'url' => '/api/migration/migrate', 'verb' => 'POST'],

                                                               
        ['name' => 'settings#getSearchBackend', 'url' => '/api/settings/search-backend', 'verb' => 'GET'],
        ['name' => 'settings#getSearchIndexStatus', 'url' => '/api/settings/search-index', 'verb' => 'GET'],
        ['name' => 'settings#updateSearchBackend', 'url' => '/api/settings/search-backend', 'verb' => 'PUT'],
        ['name' => 'settings#updateSearchBackend', 'url' => '/api/settings/search-backend', 'verb' => 'PATCH', 'postfix' => 'patch'],
                                      
        ['name' => 'tables#sync', 'url' => '/api/tables/sync/{registerId}/{schemaId}', 'verb' => 'POST', 'requirements' => ['registerId' => '[^/]+', 'schemaId' => '[^/]+']],
        ['name' => 'tables#syncAll', 'url' => '/api/tables/sync', 'verb' => 'POST'],

        ['name' => 'Settings\ConfigurationSettings#getRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateRbacSettings', 'url' => '/api/settings/rbac', 'verb' => 'PUT', 'postfix' => 'put'],

        ['name' => 'Settings\ConfigurationSettings#getMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateMultitenancySettings', 'url' => '/api/settings/multitenancy', 'verb' => 'PUT', 'postfix' => 'put'],

        ['name' => 'Settings\ConfigurationSettings#getOrganisationSettings', 'url' => '/api/settings/organisation', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateOrganisationSettings', 'url' => '/api/settings/organisation', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateOrganisationSettings', 'url' => '/api/settings/organisation', 'verb' => 'PUT', 'postfix' => 'put'],

        ['name' => 'Settings\LlmSettings#getLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'GET'],
        ['name' => 'settings#getDatabaseInfo', 'url' => '/api/settings/database', 'verb' => 'GET'],
        ['name' => 'settings#refreshDatabaseInfo', 'url' => '/api/settings/database/refresh', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#updateLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#patchLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'PATCH'],
        ['name' => 'Settings\LlmSettings#updateLLMSettings', 'url' => '/api/settings/llm', 'verb' => 'PUT', 'postfix' => 'put'],
        ['name' => 'Settings\LlmSettings#testEmbedding', 'url' => '/api/vectors/test-embedding', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#testChat', 'url' => '/api/llm/test-chat', 'verb' => 'POST'],
        ['name' => 'Settings\LlmSettings#getOllamaModels', 'url' => '/api/llm/ollama-models', 'verb' => 'GET'],
        ['name' => 'Settings\LlmSettings#checkEmbeddingModelMismatch', 'url' => '/api/vectors/check-model-mismatch', 'verb' => 'GET'],
        ['name' => 'Settings\LlmSettings#clearAllEmbeddings', 'url' => '/api/vectors/clear-all', 'verb' => 'DELETE'],
        ['name' => 'Settings\FileSettings#getFileSettings', 'url' => '/api/settings/files', 'verb' => 'GET'],
        ['name' => 'Settings\FileSettings#updateFileSettings', 'url' => '/api/settings/files', 'verb' => 'PATCH'],
        ['name' => 'Settings\FileSettings#updateFileSettings', 'url' => '/api/settings/files', 'verb' => 'PUT', 'postfix' => 'put'],
        ['name' => 'Settings\FileSettings#getFileExtractionStats', 'url' => '/api/settings/files/stats', 'verb' => 'GET'],
        ['name' => 'Settings\FileSettings#testDolphinConnection', 'url' => '/api/settings/files/test-dolphin', 'verb' => 'POST'],
        ['name' => 'Settings\FileSettings#testPresidioConnection', 'url' => '/api/settings/files/test-presidio', 'verb' => 'POST'],
        ['name' => 'Settings\FileSettings#testOpenAnonymiserConnection', 'url' => '/api/settings/files/test-openanonymiser', 'verb' => 'POST'],

                                                        
        ['name' => 'anonymisationBackend#getBackendState', 'url' => '/api/admin/anonymisation/backend-state', 'verb' => 'GET'],
        ['name' => 'anonymisationBackend#testConnection', 'url' => '/api/admin/anonymisation/test-connection', 'verb' => 'POST'],

        ['name' => 'Settings\ConfigurationSettings#getObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'GET', 'postfix' => 'vectorize'],
        ['name' => 'Settings\ConfigurationSettings#getObjectSettings', 'url' => '/api/settings/objects', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'POST'],
        ['name' => 'Settings\ConfigurationSettings#patchObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateObjectSettings', 'url' => '/api/settings/objects/vectorize', 'verb' => 'PUT', 'postfix' => 'put'],

                                          
        ['name' => 'objects#vectorizeBatch', 'url' => '/api/objects/vectorize/batch', 'verb' => 'POST'],
        ['name' => 'objects#getObjectVectorizationCount', 'url' => '/api/objects/vectorize/count', 'verb' => 'GET'],
        ['name' => 'objects#getObjectVectorizationStats', 'url' => '/api/objects/vectorize/stats', 'verb' => 'GET'],

                                      
        ['name' => 'objects#validate', 'url' => '/api/objects/validate', 'verb' => 'POST'],

                                                                                         
        ['name' => 'objects#counts', 'url' => '/api/objects/counts', 'verb' => 'POST'],

                                                                                                                  
                                                                                
        ['name' => 'fileExtraction#index', 'url' => '/api/files', 'verb' => 'GET'],
        ['name' => 'fileExtraction#stats', 'url' => '/api/files/stats', 'verb' => 'GET'],
        ['name' => 'fileExtraction#fileTypes', 'url' => '/api/files/types', 'verb' => 'GET'],
        ['name' => 'fileExtraction#vectorizeBatch', 'url' => '/api/files/vectorize/batch', 'verb' => 'POST'],
        ['name' => 'fileExtraction#discover', 'url' => '/api/files/discover', 'verb' => 'POST'],
        ['name' => 'fileExtraction#extractAll', 'url' => '/api/files/extract', 'verb' => 'POST'],
        ['name' => 'fileExtraction#retryFailed', 'url' => '/api/files/retry-failed', 'verb' => 'POST'],
        ['name' => 'fileExtraction#cleanup', 'url' => '/api/files/cleanup', 'verb' => 'POST'],
        ['name' => 'fileExtraction#show', 'url' => '/api/files/{id}', 'verb' => 'GET'],
        ['name' => 'fileExtraction#extract', 'url' => '/api/files/{id}/extract', 'verb' => 'POST'],

        ['name' => 'Settings\ConfigurationSettings#getRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'GET'],
                                                                             
                                                                              
                                                                       
        ['name' => 'Settings\AuditSettings#getAggregationSettings', 'url' => '/api/settings/audit-aggregation', 'verb' => 'GET'],
        ['name' => 'Settings\AuditSettings#updateAggregationSettings', 'url' => '/api/settings/audit-aggregation', 'verb' => 'PATCH'],
        ['name' => 'Settings\AuditSettings#updateAggregationSettings', 'url' => '/api/settings/audit-aggregation', 'verb' => 'PUT', 'postfix' => 'put'],

                                           
        ['name' => 'settings#load',                     'url' => '/api/settings/load',                            'verb' => 'GET'],
        ['name' => 'settings#semanticSearch',           'url' => '/api/settings/search/semantic',                 'verb' => 'GET'],
        ['name' => 'settings#hybridSearch',             'url' => '/api/settings/search/hybrid',                   'verb' => 'GET'],
                                                    
        ['name' => 'settings#debugTypeFiltering', 'url' => '/api/debug/type-filtering', 'verb' => 'GET'],
        ['name' => 'Settings\ConfigurationSettings#updateRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'PATCH'],
        ['name' => 'Settings\ConfigurationSettings#updateRetentionSettings', 'url' => '/api/settings/retention', 'verb' => 'PUT', 'postfix' => 'put'],

        ['name' => 'settings#getVersionInfo', 'url' => '/api/settings/version', 'verb' => 'GET'],

                                            
        ['name' => 'Settings\ApiTokenSettings#getApiTokens', 'url' => '/api/settings/api-tokens', 'verb' => 'GET'],
        ['name' => 'Settings\ApiTokenSettings#saveApiTokens', 'url' => '/api/settings/api-tokens', 'verb' => 'POST'],
        ['name' => 'Settings\ApiTokenSettings#testGitHubToken', 'url' => '/api/settings/api-tokens/test/github', 'verb' => 'POST'],
        ['name' => 'Settings\ApiTokenSettings#testGitLabToken', 'url' => '/api/settings/api-tokens/test/gitlab', 'verb' => 'POST'],


                               
        ['name' => 'settings#getStatistics', 'url' => '/api/settings/statistics', 'verb' => 'GET'],

                            
        ['name' => 'Settings\CacheSettings#getCacheStats', 'url' => '/api/settings/cache', 'verb' => 'GET'],
        ['name' => 'Settings\CacheSettings#clearCache', 'url' => '/api/settings/cache', 'verb' => 'DELETE'],
        ['name' => 'Settings\CacheSettings#warmupNamesCache', 'url' => '/api/settings/cache/warmup-names', 'verb' => 'POST'],
        ['name' => 'Settings\CacheSettings#getWarmupInterval', 'url' => '/api/settings/cache/warmup-interval', 'verb' => 'GET'],
        ['name' => 'Settings\CacheSettings#setWarmupInterval', 'url' => '/api/settings/cache/warmup-interval', 'verb' => 'PUT'],
        ['name' => 'Settings\CacheSettings#clearAppStoreCache', 'url' => '/api/settings/cache/appstore', 'verb' => 'DELETE'],

                                                               
        ['name' => 'Settings\SecuritySettings#clearIpRateLimits', 'url' => '/api/settings/security/unblock-ip', 'verb' => 'POST'],
        ['name' => 'Settings\SecuritySettings#clearUserRateLimits', 'url' => '/api/settings/security/unblock-user', 'verb' => 'POST'],
        ['name' => 'Settings\SecuritySettings#clearAllRateLimits', 'url' => '/api/settings/security/unblock', 'verb' => 'POST'],
                                                                                
                                                                                  
                                                                                   
                                                                                    
                                                                     
        ['name' => 'hardening#report', 'url' => '/api/hardening/report', 'verb' => 'GET'],
        ['name' => 'hardening#floors', 'url' => '/api/hardening/floors', 'verb' => 'GET'],
        ['name' => 'hardening#updateControls', 'url' => '/api/hardening/controls', 'verb' => 'PUT'],
        ['name' => 'hardening#updateFloors', 'url' => '/api/hardening/floors', 'verb' => 'PUT'],
                                                                               
                                                                              
                                                                      
        ['name' => 'hardening#elevate', 'url' => '/api/hardening/elevation', 'verb' => 'POST'],
                                                                                
                                                                               
                                                                            
        ['name' => 'hardeningStatement#statement', 'url' => '/api/hardening/statement', 'verb' => 'GET'],
        ['name' => 'hardeningStatement#acceptStatement', 'url' => '/api/hardening/statement/acceptance', 'verb' => 'POST'],
        ['name' => 'hardeningStatement#publishStatement', 'url' => '/api/hardening/statement', 'verb' => 'PUT'],
        ['name' => 'hardeningStatement#withdrawStatement', 'url' => '/api/hardening/statement', 'verb' => 'DELETE'],
        ['name' => 'Settings\ValidationSettings#validateAllObjects', 'url' => '/api/settings/validate-all-objects', 'verb' => 'POST'],
        ['name' => 'Settings\ValidationSettings#massValidateObjects', 'url' => '/api/settings/mass-validate', 'verb' => 'POST'],
        ['name' => 'Settings\ValidationSettings#predictMassValidationMemory', 'url' => '/api/settings/mass-validate/memory-prediction', 'verb' => 'POST'],
                                                                                            
        ['name' => 'manifest#index', 'url' => '/api/manifest/{appId}', 'verb' => 'GET', 'requirements' => ['appId' => '[^/]+']],
                                                                       
        ['name' => 'heartbeat#heartbeat', 'url' => '/api/heartbeat', 'verb' => 'GET'],
                                                                             
                                                                              
                                                                                 
                                                                              
                                                                                 
                                                                  
        ['name' => 'AppHost\Controller\GenericMetrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
                                                                        
                                                                               
                                                                               
                                                                             
        ['name' => 'AppHost\Controller\GenericHealth#index', 'url' => '/api/health', 'verb' => 'GET'],
                                                                              
        ['name' => 'urn#resolve', 'url' => '/api/urn/resolve', 'verb' => 'GET'],
        ['name' => 'urn#lookup',  'url' => '/api/urn/lookup',  'verb' => 'GET'],
        ['name' => 'urn#bulk',    'url' => '/api/urn/bulk',    'verb' => 'POST'],
                                                                               
                                                                          
        ['name' => 'contexts#register', 'url' => '/api/contexts/{register}',          'verb' => 'GET'],
        ['name' => 'contexts#schema',   'url' => '/api/contexts/{register}/{schema}', 'verb' => 'GET'],
                                                                             
                                                                            
                                       
        ['name' => 'scopes#index', 'url' => '/api/scopes', 'verb' => 'GET'],
                                                                               
                                                                                
                                                                               
                                                                              
                                                           
        ['name' => 'permissions#index',       'url' => '/api/permissions',              'verb' => 'GET'],
        ['name' => 'permissionsAudit#denyPreview', 'url' => '/api/permissions/deny-preview', 'verb' => 'GET'],
        ['name' => 'permissionsAudit#compareRoles', 'url' => '/api/permissions/compare-roles', 'verb' => 'GET'],
        ['name' => 'permissionsAudit#scopeAudit',  'url' => '/api/permissions/scope-audit',   'verb' => 'GET'],
                                                                           
                                                                   
        ['name' => 'derivedGrants#reapply', 'url' => '/api/permissions/derived-grants/reapply', 'verb' => 'POST'],
                                                                                
        ['name' => 'verwerkingsactiviteiten#index',          'url' => '/api/avg/processing-activities',        'verb' => 'GET'],
        ['name' => 'verwerkingsactiviteiten#show',           'url' => '/api/avg/processing-activities/{id}',   'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'verwerkingsactiviteiten#create',         'url' => '/api/avg/processing-activities',        'verb' => 'POST'],
        ['name' => 'verwerkingsactiviteiten#update',         'url' => '/api/avg/processing-activities/{id}',   'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'verwerkingsactiviteiten#destroy',        'url' => '/api/avg/processing-activities/{id}',   'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'verwerkingsactiviteiten#accountability', 'url' => '/api/avg/accountability',               'verb' => 'GET'],
                                                                               
                                                                         
                                                                        
        ['name' => 'processingPurpose#index',   'url' => '/api/avg/purposes',        'verb' => 'GET'],
        ['name' => 'processingPurpose#report',  'url' => '/api/avg/purposes/report', 'verb' => 'GET'],
        ['name' => 'processingPurpose#show',    'url' => '/api/avg/purposes/{id}',   'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'processingPurpose#create',  'url' => '/api/avg/purposes',        'verb' => 'POST'],
        ['name' => 'processingPurpose#update',  'url' => '/api/avg/purposes/{id}',   'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'processingPurpose#destroy', 'url' => '/api/avg/purposes/{id}',   'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
                                                                                        
        ['name' => 'auditSink#show',        'url' => '/api/audit/sink',             'verb' => 'GET'],
        ['name' => 'auditSink#acknowledge', 'url' => '/api/audit/sink/acknowledge', 'verb' => 'POST'],
                                                                             
                                                                               
                                                                               
                                
        ['name' => 'contentReport#index',  'url' => '/api/content-reports',             'verb' => 'GET'],
        ['name' => 'contentReport#create', 'url' => '/api/content-reports',             'verb' => 'POST'],
        ['name' => 'contentReport#copy',   'url' => '/api/content-reports/{id}/copy',   'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        ['name' => 'contentReport#show',   'url' => '/api/content-reports/{id}',        'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        ['name' => 'contentReport#update', 'url' => '/api/content-reports/{id}',        'verb' => 'PUT',  'requirements' => ['id' => '[^/]+']],
                                                               
        ['name' => 'dsar#access',         'url' => '/api/avg/access',         'verb' => 'GET'],
        ['name' => 'dsar#portability',    'url' => '/api/avg/portability',    'verb' => 'GET'],
        ['name' => 'dsar#erasure',        'url' => '/api/avg/erasure',        'verb' => 'POST'],
        ['name' => 'dsar#rectification',  'url' => '/api/avg/rectification',  'verb' => 'POST'],
        ['name' => 'dsar#compliance',     'url' => '/api/avg/compliance',     'verb' => 'GET'],
                                                                           
                                                                           
        ['name' => 'dataSubjectRequest#subjectData',  'url' => '/api/gdpr/subject-data',  'verb' => 'GET'],
        ['name' => 'dataSubjectRequest#accessExport', 'url' => '/api/gdpr/access-export', 'verb' => 'GET'],
        ['name' => 'dataSubjectRequest#rectify',      'url' => '/api/gdpr/rectify',       'verb' => 'POST'],
        ['name' => 'dataSubjectRequest#erase',        'url' => '/api/gdpr/erase',         'verb' => 'POST'],
        ['name' => 'dataSubjectRequest#restrict',     'url' => '/api/gdpr/restrict',      'verb' => 'POST'],
        ['name' => 'dataSubjectRequest#objection',    'url' => '/api/gdpr/object',        'verb' => 'POST'],
                                                                             
                                                                           
                                                                               
                                                                            
                                               
        ['name' => 'erasurePreview#create', 'url' => '/api/gdpr/erasure-previews', 'verb' => 'POST'],
        ['name' => 'erasurePreview#show', 'url' => '/api/gdpr/erasure-previews/{id}',
            'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'erasurePreview#approve', 'url' => '/api/gdpr/erasure-previews/{id}/approve',
            'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'erasurePreview#run', 'url' => '/api/gdpr/erasure-previews/{id}/run',
            'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
                                                                                 
                                                                             
                                                                        
        ['name' => 'principalReach#show', 'url' => '/api/rbac/reach/{principal}',
            'verb' => 'GET', 'requirements' => ['principal' => '[^/]+']],
        ['name' => 'principalReach#revoke', 'url' => '/api/rbac/reach/{principal}/revoke',
            'verb' => 'POST', 'requirements' => ['principal' => '[^/]+']],
                                                                            
                                                                           
                                                                      
        ['name' => 'subjectExport#create', 'url' => '/api/gdpr/subject-exports', 'verb' => 'POST'],
        ['name' => 'subjectExport#show', 'url' => '/api/gdpr/subject-exports/{id}',
            'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'subjectExport#download', 'url' => '/api/gdpr/subject-exports/{id}/download',
            'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
                                                                           
                                                                                
                                                                                
                                                                           
                                                                            
                                  
        ['name' => 'configurationDeployment#index', 'url' => '/api/configuration/draft-sets',
            'verb' => 'GET'],
        ['name' => 'configurationDeployment#create', 'url' => '/api/configuration/draft-sets',
            'verb' => 'POST'],
        ['name' => 'configurationDeployment#show', 'url' => '/api/configuration/draft-sets/{id}',
            'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurationDeployment#discard', 'url' => '/api/configuration/draft-sets/{id}',
            'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurationDeployment#draftValue', 'url' => '/api/configuration/draft-sets/{id}/values',
            'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurationDeployment#preview', 'url' => '/api/configuration/draft-sets/{id}/preview',
            'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurationDeployment#approve', 'url' => '/api/configuration/draft-sets/{id}/approve',
            'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurationDeployment#deploy', 'url' => '/api/configuration/draft-sets/{id}/deploy',
            'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurationDeployment#deployments', 'url' => '/api/configuration/deployments',
            'verb' => 'GET'],
        ['name' => 'configurationDeployment#deployment', 'url' => '/api/configuration/deployments/{id}',
            'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurationDeployment#rollback', 'url' => '/api/configuration/deployments/{id}/rollback',
            'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'configurationDeployment#effective', 'url' => '/api/configuration/effective',
            'verb' => 'GET'],
                                                                                  
                                                                                
                                                                            
                                                                                 
        ['name' => 'dsarCase#create',         'url' => '/api/gdpr/cases',                        'verb' => 'POST'],
        ['name' => 'dsarCase#transition',     'url' => '/api/gdpr/cases/{id}/transition',        'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#evidence',       'url' => '/api/gdpr/cases/{id}/evidence',          'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#redact',         'url' => '/api/gdpr/cases/{id}/redactions',        'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#generateBundle', 'url' => '/api/gdpr/cases/{id}/bundle',            'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#downloadBundle', 'url' => '/api/gdpr/cases/{id}/bundle/download',   'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#dossier',        'url' => '/api/gdpr/cases/{id}/dossier',           'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
                                                                                 
                                                                      
        ['name' => 'dsarCase#identityVerify', 'url' => '/api/gdpr/cases/{id}/verify-identity',   'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'dsarCase#escalate',       'url' => '/api/gdpr/cases/{id}/escalate',          'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
                                                                                  
                                                                                  
        ['name' => 'processingLog#index',      'url' => '/api/avg/verwerkingen',            'verb' => 'GET'],
        ['name' => 'processingLog#involvedParty', 'url' => '/api/avg/verwerkingen/betrokkene', 'verb' => 'GET'],
                                                                                         
        ['name' => 'translation#search',        'url' => '/api/translations/search',                                          'verb' => 'GET'],
        ['name' => 'translation#showByObject',  'url' => '/api/translations/object/{uuid}',                                   'verb' => 'GET'],
        ['name' => 'translation#setStatus',     'url' => '/api/translations/object/{uuid}/{property}/{language}/status',      'verb' => 'POST'],
        ['name' => 'translation#bulkTranslate', 'url' => '/api/translations/object/{uuid}/bulk-translate',                    'verb' => 'POST'],
                                                                                  
                                                                                    
                                                                                
                                                                       
                                                                                  
                                                                              
        ['name' => 'names#index', 'url' => '/api/names', 'verb' => 'GET'],
        ['name' => 'names#create', 'url' => '/api/names', 'verb' => 'POST'],
                     
        ['name' => 'dashboard#index', 'url' => '/api/dashboard', 'verb' => 'GET'],
        ['name' => 'dashboard#calculate', 'url' => '/api/dashboard/calculate/{registerId}', 'verb' => 'POST', 'requirements' => ['registerId' => '\d+']],
                            
        ['name' => 'dashboard#getAuditTrailActionChart', 'url' => '/api/dashboard/charts/audit-trail-actions', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsByRegisterChart', 'url' => '/api/dashboard/charts/objects-by-register', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsBySchemaChart', 'url' => '/api/dashboard/charts/objects-by-schema', 'verb' => 'GET'],
        ['name' => 'dashboard#getObjectsBySizeChart', 'url' => '/api/dashboard/charts/objects-by-size', 'verb' => 'GET'],
                                
        ['name' => 'dashboard#getAuditTrailStatistics', 'url' => '/api/dashboard/statistics/audit-trail', 'verb' => 'GET'],
        ['name' => 'dashboard#getAuditTrailActionDistribution', 'url' => '/api/dashboard/statistics/audit-trail-distribution', 'verb' => 'GET'],
        ['name' => 'dashboard#getMostActiveObjects', 'url' => '/api/dashboard/statistics/most-active-objects', 'verb' => 'GET'],
                                                                  
                                                                                        
        ['name' => 'linked_entity#addObjectLink', 'url' => '/api/objects/{uuid}/_linked/{type}', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+']],
        ['name' => 'linked_entity#removeObjectLink', 'url' => '/api/objects/{uuid}/_linked/{type}/{entityId}', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+', 'entityId' => '.+']],
        ['name' => 'linked_entity#addRegisterLink', 'url' => '/api/registers/{uuid}/_linked/{type}', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+']],
        ['name' => 'linked_entity#addSchemaLink', 'url' => '/api/schemas/{uuid}/_linked/{type}', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+', 'type' => '[^/]+']],
                                                                                                    
                                                                                                    
                                                                                                
                                                                                                         
                                                                                                        
        ['name' => 'linked_entity#reverseLookup', 'url' => '/api/linked/{type}/{entityId}', 'verb' => 'GET', 'requirements' => ['type' => '[^/]+', 'entityId' => '.+']],

                   
        ['name' => 'objects#objects', 'url' => '/api/objects', 'verb' => 'GET'],
                                                                                                                  
                                                                                                 
                                                                                       
                                                                                           
        ['name' => 'transition#transition', 'url' => '/api/objects/{id}/transition', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'transition#availableActions', 'url' => '/api/objects/{id}/available-actions', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

                                                                       
                                                                            
        ['name' => 'aggregation#timeseries', 'url' => '/api/objects/aggregations/{register}/{schema}/timeseries', 'verb' => 'GET'],
                                                                                                        
        ['name' => 'aggregation#value', 'url' => '/api/objects/aggregations/{register}/{schema}/value', 'verb' => 'GET'],
        ['name' => 'aggregation#grouped', 'url' => '/api/objects/aggregations/{register}/{schema}/grouped', 'verb' => 'GET'],
                                                                  
        ['name' => 'aggregation#aggregate', 'url' => '/api/objects/aggregations/{register}/{schema}/{name}', 'verb' => 'GET'],

                                                                              
                                                                              
                                                 
        ['name' => 'quality#stats', 'url' => '/api/objects/quality/{register}/{schema}/stats', 'verb' => 'GET'],
        ['name' => 'quality#index', 'url' => '/api/objects/quality/{register}/{schema}', 'verb' => 'GET'],
                                                               
        ['name' => 'duplicate#index', 'url' => '/api/objects/duplicates/{register}/{schema}', 'verb' => 'GET'],
                                                                                   
          
                                                                                           
                                                                                     
                                                                                      
                                                                             
        ['name' => 'duplicate#check', 'url' => '/api/objects/{register}/{schema}/dedup-check', 'verb' => 'POST', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+']],
                                                                              
                                                                                 
                                                        
        [
            'name' => 'duplicate#dismiss',
            'url' => '/api/objects/duplicates/{register}/{schema}/dismiss',
            'verb' => 'POST',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+'],
        ],
        [
            'name' => 'duplicate#undismiss',
            'url' => '/api/objects/duplicates/{register}/{schema}/undismiss',
            'verb' => 'POST',
            'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+'],
        ],
                                                                                             
        ['name' => 'merge#preview', 'url' => '/api/objects/merge/preview', 'verb' => 'POST'],
        ['name' => 'merge#execute', 'url' => '/api/objects/merge/execute', 'verb' => 'POST'],
        ['name' => 'merge#reverse', 'url' => '/api/objects/merge/{id}/reverse', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

                                                                               
                                                                               
                             
        ['name' => 'survivorship#override', 'url' => '/api/objects/survivorship/{id}/override', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
                                                                               
                                          
        ['name' => 'survivorship#sources', 'url' => '/api/objects/survivorship/{id}/sources', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

                                                                               
        ['name' => 'contacts#match', 'url' => '/api/contacts/match', 'verb' => 'GET'],

                                                                          
                                                                            
                                                                           
                                                                             
                                                                        
                                                               
        ['name' => 'emails#index',    'url' => '/api/objects/{register}/{schema}/{id}/emails/list',  'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'emails#create',   'url' => '/api/objects/{register}/{schema}/{id}/emails/send',  'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'emails#destroy',  'url' => '/api/objects/{register}/{schema}/{id}/emails/direct/{emailId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'emailId' => '[^/]+']],
        ['name' => 'emails#search',   'url' => '/api/emails/search',                                'verb' => 'GET'],
        ['name' => 'emails#bySender', 'url' => '/api/emails/by-sender',                             'verb' => 'GET'],
        ['name' => 'emailLinks#index',   'url' => '/api/objects/{register}/{schema}/{id}/emails',           'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'emailLinks#link',    'url' => '/api/objects/{register}/{schema}/{id}/emails',           'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'emailLinks#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/emails/{linkId}',  'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'linkId' => '[0-9]+']],
        ['name' => 'emailLinks#accounts',  'url' => '/api/integrations/email/accounts',                                       'verb' => 'GET'],
        ['name' => 'emailLinks#mailboxes', 'url' => '/api/integrations/email/accounts/{accountId}/mailboxes',                 'verb' => 'GET',    'requirements' => ['accountId' => '[0-9]+']],
        ['name' => 'emailLinks#messages',  'url' => '/api/integrations/email/accounts/{accountId}/messages',                  'verb' => 'GET',    'requirements' => ['accountId' => '[0-9]+']],

                                                                                    
                                                                            
                                                                            
                                                                         
        ['name' => 'contacts#index',     'url' => '/api/objects/{register}/{schema}/{id}/contacts',                 'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'contacts#createNew', 'url' => '/api/objects/{register}/{schema}/{id}/contacts/new',             'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'contacts#create',    'url' => '/api/objects/{register}/{schema}/{id}/contacts',                 'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'contacts#update',    'url' => '/api/objects/{register}/{schema}/{id}/contacts/{contactUid}',    'verb' => 'PUT',    'requirements' => ['id' => '[^/]+', 'contactUid' => '[^/]+']],
        ['name' => 'contacts#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/contacts/{contactUid}',    'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'contactUid' => '[^/]+']],
        ['name' => 'contacts#objects',   'url' => '/api/contacts/{contactUid}/objects',                              'verb' => 'GET',    'requirements' => ['contactUid' => '[^/]+']],

                                                                              
                                                                               
                                                                             
                                                                             
                                            
        ['name' => 'party#index',          'url' => '/api/objects/{register}/{schema}/{id}/parties',              'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'party#create',         'url' => '/api/objects/{register}/{schema}/{id}/parties',              'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'party#replacePrimary', 'url' => '/api/objects/{register}/{schema}/{id}/parties/primary',      'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'party#destroy',        'url' => '/api/objects/{register}/{schema}/{id}/parties/{partyUuid}',  'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'partyUuid' => '[^/]+']],
                                                                               
                                                                 
        ['name' => 'party#search',         'url' => '/api/parties/search',                                        'verb' => 'GET'],
        ['name' => 'party#resolve',        'url' => '/api/parties/resolve',                                       'verb' => 'GET'],
        ['name' => 'party#show',           'url' => '/api/parties/{partyUuid}',                                   'verb' => 'GET',    'requirements' => ['partyUuid' => '[^/]+']],

                                                                         
        ['name' => 'calendarEvents#index',     'url' => '/api/objects/{register}/{schema}/{id}/events',                 'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'calendarEvents#create',    'url' => '/api/objects/{register}/{schema}/{id}/events',                 'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'calendarEvents#link',      'url' => '/api/objects/{register}/{schema}/{id}/events/link',            'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'calendarEvents#unlink',    'url' => '/api/objects/{register}/{schema}/{id}/events/{eventUid}/link', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'eventUid' => '[^/]+']],
        ['name' => 'calendarEvents#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/events/{eventId}',       'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'eventId' => '[^/]+']],
                                                                                  
        ['name' => 'calendarEvents#listCalendars',      'url' => '/api/integrations/calendar/calendars',                              'verb' => 'GET'],
        ['name' => 'calendarEvents#listCalendarEvents', 'url' => '/api/integrations/calendar/calendars/{calendarUri}/events',         'verb' => 'GET',    'requirements' => ['calendarUri' => '[^/]+']],

                                                                    
                                                                  
                                                            
        ['name' => 'deckLinks#index',     'url' => '/api/objects/{register}/{schema}/{id}/deck',              'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'deckLinks#link',      'url' => '/api/objects/{register}/{schema}/{id}/deck',              'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'deckLinks#createNew', 'url' => '/api/objects/{register}/{schema}/{id}/deck/new',          'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'deckLinks#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/deck/{cardId}',     'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'cardId' => '[0-9]+']],
        ['name' => 'deckLinks#boards',    'url' => '/api/integrations/deck/boards',                           'verb' => 'GET'],
        ['name' => 'deckLinks#stacks',    'url' => '/api/integrations/deck/boards/{boardId}/stacks',          'verb' => 'GET',    'requirements' => ['boardId' => '[0-9]+']],
                                                                                        
        ['name' => 'deckLinks#getDefault', 'url' => '/api/integrations/deck/default/{schema}',                 'verb' => 'GET'],
        ['name' => 'deckLinks#setDefault', 'url' => '/api/integrations/deck/default/{schema}',                 'verb' => 'PUT'],
                                                                                   
        ['name' => 'deck#index',          'url' => '/api/objects/{register}/{schema}/{id}/deck/cards',         'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'deck#create',         'url' => '/api/objects/{register}/{schema}/{id}/deck/cards',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
                                                                            
        ['name' => 'deck#objects',        'url' => '/api/deck/boards/{boardId}/objects',                      'verb' => 'GET',    'requirements' => ['boardId' => '[^/]+']],

                                                                         
                                                                       
                                                                    
                                       
        ['name' => 'talkLinks#index',     'url' => '/api/objects/{register}/{schema}/{id}/talk',              'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'talkLinks#link',      'url' => '/api/objects/{register}/{schema}/{id}/talk',              'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'talkLinks#createNew', 'url' => '/api/objects/{register}/{schema}/{id}/talk/new',          'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'talkLinks#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/talk/{roomToken}',  'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'roomToken' => '[A-Za-z0-9]+']],
        ['name' => 'talkLinks#rooms',     'url' => '/api/integrations/talk/rooms',                            'verb' => 'GET'],

                                                                          
                                                                            
                                                                 
        ['name' => 'pollLinks#available', 'url' => '/api/integrations/polls/available',                       'verb' => 'GET'],
        ['name' => 'pollLinks#index',     'url' => '/api/objects/{register}/{schema}/{id}/polls',             'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'pollLinks#link',      'url' => '/api/objects/{register}/{schema}/{id}/polls',             'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'pollLinks#createNew', 'url' => '/api/objects/{register}/{schema}/{id}/polls/new',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'pollLinks#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/polls/{pollId}',    'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'pollId' => '[0-9]+']],

                                                                     
                                                                          
                                                                         
                         
        ['name' => 'bookmarkLinks#available', 'url' => '/api/integrations/bookmarks/available',                       'verb' => 'GET'],
        ['name' => 'bookmarkLinks#index',     'url' => '/api/objects/{register}/{schema}/{id}/bookmarks',             'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'bookmarkLinks#link',      'url' => '/api/objects/{register}/{schema}/{id}/bookmarks',             'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'bookmarkLinks#createNew', 'url' => '/api/objects/{register}/{schema}/{id}/bookmarks/new',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'bookmarkLinks#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/bookmarks/{bookmarkId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'bookmarkId' => '[0-9]+']],

                                                                         
                                                                      
                                                                      
                                                                        
                                                                     
                                                                     
        ['name' => 'shareLinks#files',   'url' => '/api/integrations/shares/files/{register}/{schema}/{id}', 'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'shareLinks#index',   'url' => '/api/objects/{register}/{schema}/{id}/shares',            'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'shareLinks#create',  'url' => '/api/objects/{register}/{schema}/{id}/shares',            'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'shareLinks#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/shares/{shareId}',  'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'shareId' => '[^/]+']],

                                                                      
                                                                      
                                                                      
                                                                       
                                                                    
                                                                              
        ['name' => 'flow#eventCatalog', 'url' => '/api/flow/event-catalog', 'verb' => 'GET'],
        ['name' => 'flow#nodeCatalog',  'url' => '/api/flow/node-catalog',  'verb' => 'GET'],
                                                                         
                                                                         
                                                                  
        ['name' => 'flowPrincipal#types', 'url' => '/api/flow/principal-types', 'verb' => 'GET'],
                                                                              
                                                                               
                                                                                
                              
        ['name' => 'flow#logActions', 'url' => '/api/flow/log-actions', 'verb' => 'POST'],
                                                                           
                                                                           
                                                                            
                                                       
        ['name' => 'flow#validate',     'url' => '/api/flow/validate',      'verb' => 'POST'],
                                                                             
                                                              
        ['name' => 'flow#state',        'url' => '/api/flow/{flowId}/state', 'verb' => 'GET', 'requirements' => ['flowId' => '[^/]+']],

                                                                                
                                                                              
                                                                               
                                                      
          
                                                                               
                                                                              
          
                                                                            
                                                                             
                                                                               
                                                                      
        ['name' => 'flow#run',     'url' => '/api/flows/{id}/run', 'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
                                                                     
                                                                               
                                                                                
                                                                 
        ['name' => 'flow#exportBpmn', 'url' => '/api/flows/{id}/bpmn',  'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#importBpmn', 'url' => '/api/flows/import/bpmn', 'verb' => 'POST'],

                                                                             
                                                                      
                                                                             
                                                                                
                                                                            
                                                                            
                                                                              
                                                                     
                                                  
        ['name' => 'flowNodeRun#form', 'url' => '/api/flows/{id}/nodes/{nodeId}/run', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+', 'nodeId' => '[^/]+']],
        ['name' => 'flowNodeRun#run',  'url' => '/api/flows/{id}/nodes/{nodeId}/run', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'nodeId' => '[^/]+']],

                                                                                
                                                                             
                                                                               
                                                                            
          
                                                                  
                                                                            
                                                              
                                                                               
                                                                                
                                                                          
        ['name' => 'flow#adopt',     'url' => '/api/flows/{id}/adopt',               'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#versions',  'url' => '/api/flows/{id}/versions',            'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#version',   'url' => '/api/flows/{id}/versions/{version}',  'verb' => 'GET',  'requirements' => ['id' => '[^/]+', 'version' => '\d+']],
        ['name' => 'flow#publish',   'url' => '/api/flows/{id}/publish',             'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
                                                                              
                                                                           
        ['name' => 'flow#versionPreview', 'url' => '/api/flows/{id}/version-preview', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#draft',     'url' => '/api/flows/{id}/draft',               'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#deprecate', 'url' => '/api/flows/{id}/deprecate',           'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#index',   'url' => '/api/flows',          'verb' => 'GET'],
        ['name' => 'flow#create',  'url' => '/api/flows',          'verb' => 'POST'],
        ['name' => 'flow#show',    'url' => '/api/flows/{id}',     'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#update',  'url' => '/api/flows/{id}',     'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'flow#destroy', 'url' => '/api/flows/{id}',     'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],

        ['name' => 'flowLinks#available', 'url' => '/api/integrations/flow/operations',                       'verb' => 'GET'],
        ['name' => 'flowLinks#index',     'url' => '/api/objects/{register}/{schema}/{id}/flow',              'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'flowLinks#link',      'url' => '/api/objects/{register}/{schema}/{id}/flow',              'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'flowLinks#destroy',   'url' => '/api/objects/{register}/{schema}/{id}/flow/{operationId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'operationId' => '[0-9]+']],

                                                                      
                                                                        
                                                                      
        ['name' => 'photoLinks#available',    'url' => '/api/integrations/photos/available',                   'verb' => 'GET'],
        ['name' => 'photoLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/photos',         'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'photoLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/photos/new',     'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'photoLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/photos',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'photoLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/photos/{albumId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'albumId' => '[0-9]+']],

                                                                          
                                                                           
                                                                         
                                                                          
                                
        ['name' => 'collectiveLinks#available',    'url' => '/api/integrations/collectives/available',                  'verb' => 'GET'],
        ['name' => 'collectiveLinks#collectives',  'url' => '/api/integrations/collectives/list',                       'verb' => 'GET'],
        ['name' => 'collectiveLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/collectives',        'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'collectiveLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/collectives/new',    'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'collectiveLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/collectives',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'collectiveLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/collectives/{pageId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'pageId' => '[0-9]+']],

                                                                        
                                                                             
                                                                            
                                                                        
                                                                          
                                                                             
                                                                
        ['name' => 'xwikiLinks#available',    'url' => '/api/integrations/xwiki/available',                  'verb' => 'GET'],
        ['name' => 'xwikiLinks#search',       'url' => '/api/integrations/xwiki/search',                     'verb' => 'GET'],
        ['name' => 'xwikiLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/xwiki',        'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'xwikiLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/xwiki/new',    'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'xwikiLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/xwiki',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'xwikiLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/xwiki/{pageRef}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'pageRef' => '[^/]+']],

                                                                               
                                                                           
                                                                             
                                                                          
                                                                                                                                        
        ['name' => 'companyLookup#kvkCompany',           'url' => '/api/integrations/kvk/company',            'verb' => 'GET'],
        ['name' => 'companyLookup#kvkSearch',            'url' => '/api/integrations/kvk/search',             'verb' => 'GET'],
                                                                                                                                                   
        ['name' => 'companyLookup#openCorporatesSearch', 'url' => '/api/integrations/opencorporates/search',  'verb' => 'GET'],
                                                                            
                                                                                
                                                                                
                                                                                
                                                                               
                                                                                 
                                                                                                                             
        ['name' => 'personLookup#brpPerson',             'url' => '/api/integrations/brp/person',             'verb' => 'GET'],
                                                                         
                                                                      
                                                             
                                                                              
                                                                               
                                                                         
                                                                           
                                                                                
                                    
                                                                                                                                                  
        ['name' => 'messageDispatch#smsSend',            'url' => '/api/integrations/sms/send',               'verb' => 'POST'],
        ['name' => 'messageDispatch#whatsappSend',       'url' => '/api/integrations/whatsapp/send',          'verb' => 'POST'],
                                                                      
                                                                         
                                                                           
                                                                      
                                                                   
                                                                 
        ['name' => 'cospendLinks#available',    'url' => '/api/integrations/cospend/available',                  'verb' => 'GET'],
        ['name' => 'cospendLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/cospend',        'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'cospendLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/cospend/new',    'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'cospendLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/cospend',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'cospendLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/cospend/{entryId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'entryId' => '[0-9]+']],

                                                                            
                                                                    
                                                           
                                                                    
                                                                          
                                                               
        ['name' => 'openProjectLinks#available',    'url' => '/api/integrations/openproject/available',                  'verb' => 'GET'],
        ['name' => 'openProjectLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/openproject',        'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'openProjectLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/openproject/new',    'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'openProjectLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/openproject',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'openProjectLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/openproject/{wpId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'wpId' => '[0-9]+']],

                                                                         
                                                                          
                                                                       
        ['name' => 'mapLinks#available',    'url' => '/api/integrations/maps/available',                       'verb' => 'GET'],
        ['name' => 'mapLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/maps',             'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'mapLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/maps/new',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'mapLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/maps',             'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'mapLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/maps/{favoriteId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'favoriteId' => '[0-9]+']],

                                                                             
                                                                            
                                                                              
                                                       
                                                                     
                                                                            
                                                                 
        ['name' => 'timeTrackerLinks#available',    'url' => '/api/integrations/time-tracker/available',                    'verb' => 'GET'],
        ['name' => 'timeTrackerLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/time-tracker',          'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'timeTrackerLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/time-tracker/new',      'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'timeTrackerLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/time-tracker',          'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'timeTrackerLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/time-tracker/{entryId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'entryId' => '[^/]+']],

                                                                        
                                                                         
                                                                         
                                                                          
                                          
                                                                                                                     
        ['name' => 'analyticsLinks#available',    'url' => '/api/integrations/analytics/available',                  'verb' => 'GET'],
        ['name' => 'analyticsLinks#index',        'url' => '/api/objects/{register}/{schema}/{id}/analytics',        'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'analyticsLinks#createAndLink','url' => '/api/objects/{register}/{schema}/{id}/analytics/new',    'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'analyticsLinks#link',         'url' => '/api/objects/{register}/{schema}/{id}/analytics',        'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'analyticsLinks#destroy',      'url' => '/api/objects/{register}/{schema}/{id}/analytics/{reportId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'reportId' => '[0-9]+']],

                                                                        
                                                                         
                                                                      
                                                             
                                                                                                                      
        ['name' => 'analyticsSeries#register', 'url' => '/api/integrations/analytics/series',              'verb' => 'POST'],
                                                                                                                               
        ['name' => 'analyticsSeries#fetch',    'url' => '/api/integrations/analytics/series/{seriesKey}',  'verb' => 'GET',  'requirements' => ['seriesKey' => '[^/]+']],

                                                                        
                                                                       
                                                                  
                                                                            
                                                                           
                                                                                                                       
        ['name' => 'mapsOverview#register', 'url' => '/api/integrations/maps/overviews',                            'verb' => 'POST'],
                                                                                                                        
        ['name' => 'mapsOverview#points',   'url' => '/api/integrations/maps/overviews/{register}/{schema}/points', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+']],

                                                                          
                                                                    
                                                                          
                                                                                                                           
        ['name' => 'caseToken#resolve', 'url' => '/api/public/case-tokens/{token}', 'verb' => 'GET', 'requirements' => ['token' => '[^/]+']],

                                                                          
                                                                               
                                                                               
                                                                             
                                     
                                                                                                 
        ['name' => 'calendarFeed#feed', 'url' => '/api/public/calendar-feeds/{token}.ics', 'verb' => 'GET', 'requirements' => ['token' => '[^/.]+']],
        ['name' => 'calendarFeed#index', 'url' => '/api/calendar-feeds', 'verb' => 'GET'],
        ['name' => 'calendarFeed#mint', 'url' => '/api/calendar-feeds', 'verb' => 'POST'],
        ['name' => 'calendarFeed#revoke', 'url' => '/api/calendar-feeds/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
        [
            'name' => 'calendarFeed#attendeeResponses',
            'url' => '/api/objects/{id}/attendee-responses',
            'verb' => 'GET',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name' => 'calendarFeed#recordAttendeeResponse',
            'url' => '/api/objects/{id}/attendee-responses',
            'verb' => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],

                                                                                
                                                                                
                                                                                 
                                                                             
                                                                               
                                                                               
                                                                                
                                                                            
                                                                           
                                                                                 
                                                                                                 
        ['name' => 'accessLink#open', 'url' => '/api/public/links/{anchor}', 'verb' => 'GET', 'requirements' => ['anchor' => '[A-Za-z0-9]+']],
        [
            'name' => 'accessLink#comment',
            'url' => '/api/public/links/{anchor}/comments',
            'verb' => 'POST',
            'requirements' => ['anchor' => '[A-Za-z0-9]+'],
        ],
        [
            'name' => 'accessLink#upload',
            'url' => '/api/public/links/{anchor}/files',
            'verb' => 'POST',
            'requirements' => ['anchor' => '[A-Za-z0-9]+'],
        ],
                                                                                
                                                                                 
        ['name' => 'accessLinkPage#show', 'url' => '/links/{anchor}', 'verb' => 'GET', 'requirements' => ['anchor' => '[A-Za-z0-9]+']],
        ['name' => 'accessLink#index', 'url' => '/api/access-links', 'verb' => 'GET'],
        ['name' => 'accessLink#mint', 'url' => '/api/access-links', 'verb' => 'POST'],
        ['name' => 'accessLink#update', 'url' => '/api/access-links/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
        ['name' => 'accessLink#revoke', 'url' => '/api/access-links/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],

                                                                              
                                                                               
                                                                          
                                                                          
                                                                       
        ['name' => 'vocabulary#resolveByUri', 'url' => '/api/vocabulary/concept', 'verb' => 'GET'],
        ['name' => 'vocabulary#resolveByNotation', 'url' => '/api/vocabulary/concept/notation', 'verb' => 'GET'],
        ['name' => 'vocabulary#listConcepts', 'url' => '/api/vocabulary/concepts', 'verb' => 'GET'],

                                                                            
                                                                             
                                                                            
                                                                       
                                                           
                                                                                                        
        ['name' => 'vocabulary#propertyOptions', 'url' => '/api/vocabulary/options', 'verb' => 'GET'],

                                                                   
                                                                     
                                                                      
                                                                    
                                                                 
                                                                       
                                                                       
                 
                                                                                                                         
        ['name' => 'activityLinks#types',  'url' => '/api/integrations/activity/types',                  'verb' => 'GET'],
        ['name' => 'activityLinks#actors', 'url' => '/api/integrations/activity/actors',                 'verb' => 'GET'],
        ['name' => 'activityLinks#index',  'url' => '/api/objects/{register}/{schema}/{id}/activity',    'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

                                                                                       
                                                                               
                                                                                     
        [
            'name' => 'formLinks#available',
            'url'  => '/api/integrations/forms/available',
            'verb' => 'GET',
        ],
        [
            'name'         => 'formLinks#index',
            'url'          => '/api/objects/{register}/{schema}/{id}/forms',
            'verb'         => 'GET',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'formLinks#create',
            'url'          => '/api/objects/{register}/{schema}/{id}/forms/new',
            'verb'         => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'formLinks#link',
            'url'          => '/api/objects/{register}/{schema}/{id}/forms',
            'verb'         => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name'         => 'formLinks#destroySubmission',
            'url'          => '/api/objects/{register}/{schema}/{id}/forms/{formId}/submissions/{submissionId}',
            'verb'         => 'DELETE',
            'requirements' => [
                'id'           => '[^/]+',
                'formId'       => '[0-9]+',
                'submissionId' => '[0-9]+',
            ],
        ],
        [
            'name'         => 'formLinks#destroyForm',
            'url'          => '/api/objects/{register}/{schema}/{id}/forms/{formId}',
            'verb'         => 'DELETE',
            'requirements' => ['id' => '[^/]+', 'formId' => '[0-9]+'],
        ],

                                                                                               
        ['name' => 'relations#index', 'url' => '/api/objects/{register}/{schema}/{id}/relations',          'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],

                                                                                             
        ['name' => 'linkedEntity#addObjectLink',    'url' => '/api/objects/{uuid}/_{type}',           'verb' => 'POST',   'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+']],
        ['name' => 'linkedEntity#removeObjectLink', 'url' => '/api/objects/{uuid}/_{type}/{entityId}','verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+', 'entityId' => '.+']],
        ['name' => 'linkedEntity#addRegisterLink',  'url' => '/api/registers/{uuid}/_{type}',         'verb' => 'POST',   'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+']],
        ['name' => 'linkedEntity#addSchemaLink',    'url' => '/api/schemas/{uuid}/_{type}',           'verb' => 'POST',   'requirements' => ['uuid' => '[^/]+', 'type' => '[a-z]+']],
        ['name' => 'linkedEntity#reverseLookup',    'url' => '/api/linked/_{type}/{entityId}',        'verb' => 'GET',    'requirements' => ['type' => '[a-z]+', 'entityId' => '.+']],

                                                                                                  
        ['name' => 'tmlo#summary',      'url' => '/api/tmlo/{register}/{schema}/summary',                'verb' => 'GET'],
        ['name' => 'tmlo#exportSingle', 'url' => '/api/tmlo/{register}/{schema}/{id}/export',            'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
        ['name' => 'tmlo#exportBatch',  'url' => '/api/tmlo/{register}/{schema}/export',                 'verb' => 'GET'],

                                                                                            
        ['name' => 'fileSidebar#getObjectsForFile',    'url' => '/api/files/{fileId}/objects',           'verb' => 'GET',  'requirements' => ['fileId' => '[0-9]+']],
        ['name' => 'fileSidebar#getExtractionStatus',  'url' => '/api/files/{fileId}/extraction-status', 'verb' => 'GET',  'requirements' => ['fileId' => '[0-9]+']],

                                            

        ['name' => 'objects#index', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'GET'],

        ['name' => 'objects#geoSearch', 'url' => '/api/objects/{register}/{schema}/geo-search', 'verb' => 'POST'],
        ['name' => 'objects#geoJson', 'url' => '/api/geo/{register}/{schema}/geojson', 'verb' => 'GET'],
        ['name' => 'objects#wfs', 'url' => '/api/geo/{register}/{schema}/wfs', 'verb' => 'GET'],
        ['name' => 'objects#geocode', 'url' => '/api/geo/geocode', 'verb' => 'GET'],

        ['name' => 'objects#create', 'url' => '/api/objects/{register}/{schema}', 'verb' => 'POST'],
        ['name' => 'objects#export', 'url' => '/api/objects/{register}/{schema}/export', 'verb' => 'GET'],
                                                                               
                                                                               
        ['name' => 'objects#referenceOptions', 'url' => '/api/objects/{register}/{schema}/{id}/reference-options', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#show', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#update', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#patch', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#postPatch', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#destroy', 'url' => '/api/objects/{register}/{schema}/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#canDelete', 'url' => '/api/objects/{register}/{schema}/{id}/can-delete', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#merge', 'url' => '/api/objects/{register}/{schema}/{id}/merge', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#migrate', 'url' => '/api/migrate', 'verb' => 'POST'],
                     
        ['name' => 'objects#contracts', 'url' => '/api/objects/{register}/{schema}/{id}/contracts', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#uses',      'url' => '/api/objects/{register}/{schema}/{id}/uses',      'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#used',      'url' => '/api/objects/{register}/{schema}/{id}/used',      'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
                                                                                    
                                                                                  
        [
            'name' => 'objects#referencedBy',
            'url' => '/api/objects/{register}/{schema}/{id}/referenced-by',
            'verb' => 'GET',
            'requirements' => ['id' => '[^/]+'],
        ],
                                                                               
                                                                                 
        [
            'name' => 'objects#geoFeatures',
            'url' => '/api/objects/{register}/{schema}/{id}/geo-features',
            'verb' => 'GET',
            'requirements' => ['id' => '[^/]+'],
        ],
        ['name' => 'objects#logs',      'url' => '/api/objects/{register}/{schema}/{id}/logs',      'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
                                                                               
                                                                             
                                                                              
                                                                           
                                                                              
                                 
        ['name' => 'objectRelations#index',       'url' => '/api/objects/{register}/{schema}/{id}/relation-rows',              'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'objectRelations#addLink',     'url' => '/api/objects/{register}/{schema}/{id}/relation-rows',              'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'objectRelations#removeLink',  'url' => '/api/objects/{register}/{schema}/{id}/relation-rows/{relationId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'relationId' => '[^/]+']],
        ['name' => 'objectRelations#removeReferences', 'url' => '/api/objects/{register}/{schema}/{id}/relation-references/{anchor}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'anchor' => '[^/]+']],
        ['name' => 'objectRelations#derive',      'url' => '/api/objects/{register}/{schema}/{id}/derive',                     'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
        ['name' => 'objectRelations#graph',       'url' => '/api/objects/{register}/{schema}/{id}/graph',                      'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'objectRelations#exportGraph', 'url' => '/api/objects/{register}/{schema}/{id}/graph/export',               'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
                 
                                                                             
                                                                             
                                                                              
                                                                           
                                                                               
                                                                               
                                                                           
        ['name' => 'objects#exists', 'url' => '/api/objects/exists', 'verb' => 'POST'],
                                                                             
                                                                           
                                                                        
                                                                             
                                                                            
                                                                      
        ['name' => 'objects#presenceBeat',   'url' => '/api/objects/{register}/{schema}/{id}/presence', 'verb' => 'PUT',    'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#presenceDepart', 'url' => '/api/objects/{register}/{schema}/{id}/presence', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#presenceList',   'url' => '/api/objects/{register}/{schema}/{id}/presence', 'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
                                                                              
                                                                           
                                                                              
                                                                               
                                                                           
                          
        ['name' => 'objects#move', 'url' => '/api/objects/{register}/{schema}/{id}/move', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#lock', 'url' => '/api/objects/{register}/{schema}/{id}/lock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objects#unlock', 'url' => '/api/objects/{register}/{schema}/{id}/unlock', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
                                                                            
                                                                                
                                                                           
                                                                       
                                                                               
                                                                      
                                                                           
                                                                            
              
                                                                               
                                                                               
                                                                               
                                                                             
                                                                                 
        ['name' => 'objects#unlock', 'url' => '/api/objects/{register}/{schema}/{id}/lock', 'verb' => 'DELETE', 'postfix' => 'delete', 'requirements' => ['id' => '[^/]+']],
                                                                               
                                                                        
                                                                          
                                                                              
                                                   
        ['name' => 'objectState#archive', 'url' => '/api/objects/{register}/{schema}/{id}/archive', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objectState#unarchive', 'url' => '/api/objects/{register}/{schema}/{id}/archive', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objectState#freeze', 'url' => '/api/objects/{register}/{schema}/{id}/freeze', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'objectState#unfreeze', 'url' => '/api/objects/{register}/{schema}/{id}/freeze', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
                                                                               
                                                                   
                                                                              
                                                                             
                                                                               
                                        
        ['name' => 'corrections#correct', 'url' => '/api/objects/{register}/{schema}/{id}/correct', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
                                                                        
        ['name' => 'registrySubscription#subscribe', 'url' => '/api/objects/{register}/{schema}/{id}/registry-subscription', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registrySubscription#unsubscribe', 'url' => '/api/objects/{register}/{schema}/{id}/registry-subscription', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
                                                                     
        ['name' => 'registryUpdates#update', 'url' => '/api/registry/{registry}/updates', 'verb' => 'POST'],
                           
        ['name' => 'bulk#save', 'url' => '/api/bulk/{register}/{schema}/save', 'verb' => 'POST'],
        ['name' => 'bulk#delete', 'url' => '/api/bulk/{register}/{schema}/delete', 'verb' => 'POST'],
        ['name' => 'bulk#deleteSchema', 'url' => '/api/bulk/{register}/{schema}/delete-schema', 'verb' => 'POST'],
        ['name' => 'bulk#deleteSchemaObjects', 'url' => '/api/bulk/{register}/{schema}/delete-objects', 'verb' => 'POST'],
        ['name' => 'bulk#deleteRegister', 'url' => '/api/bulk/{register}/delete-register', 'verb' => 'POST'],
        ['name' => 'bulk#runSchemaValidation', 'url' => '/api/bulk/schema/{schema}/validate', 'verb' => 'POST'],
                                                                           
                                                                     
        ['name' => 'bulkJobs#actions', 'url' => '/api/bulk-actions', 'verb' => 'GET'],
        ['name' => 'bulkJobs#index', 'url' => '/api/bulk-jobs', 'verb' => 'GET'],
        ['name' => 'bulkJobs#create', 'url' => '/api/bulk-jobs', 'verb' => 'POST'],
        ['name' => 'bulkJobs#show', 'url' => '/api/bulk-jobs/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
        ['name' => 'bulkJobs#members', 'url' => '/api/bulk-jobs/{id}/members', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
        ['name' => 'bulkJobs#download', 'url' => '/api/bulk-jobs/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
        ['name' => 'bulkJobs#commit', 'url' => '/api/bulk-jobs/{id}/commit', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        ['name' => 'bulkJobs#cancel', 'url' => '/api/bulk-jobs/{id}/cancel', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        ['name' => 'bulkJobs#retry', 'url' => '/api/bulk-jobs/{id}/retry', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        ['name' => 'bulkJobs#reverse', 'url' => '/api/bulk-jobs/{id}/reverse', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
                                                                           
                                                                    
        ['name' => 'bulkJobs#pause', 'url' => '/api/bulk-jobs/{id}/pause', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
        ['name' => 'bulkJobs#resume', 'url' => '/api/bulk-jobs/{id}/resume', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
                                                                         
                                                                          
                                                                              
                                                              
                                                                            
                                                                            
                                                                       
        ['name' => 'operationsConsole#index', 'url' => '/api/operations/console', 'verb' => 'GET'],
        ['name' => 'operationsConsole#jobs', 'url' => '/api/operations/jobs', 'verb' => 'GET'],
        ['name' => 'operationsConsole#ruleRuns', 'url' => '/api/operations/rule-runs', 'verb' => 'GET'],
                                                                           
                                                                   
                                                             
        ['name' => 'operationsConsole#runs', 'url' => '/api/operations/runs', 'verb' => 'GET'],
        ['name' => 'operationsConsole#runNow', 'url' => '/api/operations/run-now', 'verb' => 'POST'],
        ['name' => 'operationsConsole#schedule', 'url' => '/api/operations/schedule', 'verb' => 'GET'],
        ['name' => 'operationsConsole#schedule', 'url' => '/api/operations/schedule', 'verb' => 'PUT', 'postfix' => 'administer'],
        ['name' => 'operationsConsole#alerts', 'url' => '/api/operations/alerts', 'verb' => 'GET'],
        ['name' => 'operationsConsole#administerAlerts', 'url' => '/api/operations/alerts', 'verb' => 'PUT'],
        ['name' => 'operationsConsistency#consistency', 'url' => '/api/operations/consistency', 'verb' => 'GET'],
        ['name' => 'operationsConsistency#repairPlan', 'url' => '/api/operations/repair-plan', 'verb' => 'GET'],
        ['name' => 'operationsConsistency#repair', 'url' => '/api/operations/repair', 'verb' => 'POST'],
        ['name' => 'operationsMaintenance#maintenance', 'url' => '/api/operations/maintenance', 'verb' => 'GET'],
        ['name' => 'operationsMaintenance#maintenance', 'url' => '/api/operations/maintenance', 'verb' => 'POST', 'postfix' => 'enter'],
        ['name' => 'operationsMaintenance#maintenance', 'url' => '/api/operations/maintenance', 'verb' => 'DELETE', 'postfix' => 'leave'],
        ['name' => 'operationsMaintenance#supportBundle', 'url' => '/api/operations/support-bundle', 'verb' => 'GET'],
        ['name' => 'operationsMaintenance#facts', 'url' => '/api/operations/facts', 'verb' => 'GET'],
                                                                            
                                                                     
                                                                     
        ['name' => 'importPreview#policies', 'url' => '/api/import-previews/policies', 'verb' => 'GET'],
        ['name' => 'importPreview#index', 'url' => '/api/import-previews', 'verb' => 'GET'],
        ['name' => 'importPreview#create', 'url' => '/api/import-previews', 'verb' => 'POST'],
        ['name' => 'importPreview#show', 'url' => '/api/import-previews/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
        ['name' => 'importPreview#rows', 'url' => '/api/import-previews/{id}/rows', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
        ['name' => 'importPreview#commit', 'url' => '/api/import-previews/{id}/commit', 'verb' => 'POST', 'requirements' => ['id' => '\\d+']],
                                                                                     
        ['name' => 'auditTrail#objects', 'url' => '/api/objects/{register}/{schema}/{id}/audit-trails', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#index', 'url' => '/api/audit-trails', 'verb' => 'GET'],
                                                                                 
                                                                           
                                                                               
                                                                             
                                           
        ['name' => 'auditTrail#readable', 'url' => '/api/audit-trails/readable', 'verb' => 'GET'],
        ['name' => 'auditTrail#statistics', 'url' => '/api/audit-trails/statistics', 'verb' => 'GET'],
        ['name' => 'auditTrail#export', 'url' => '/api/audit-trails/export', 'verb' => 'GET'],
        ['name' => 'auditTrail#verify', 'url' => '/api/audit-trails/verify', 'verb' => 'GET'],
        ['name' => 'auditTrail#integrity', 'url' => '/api/audit-trails/integrity', 'verb' => 'GET'],
        ['name' => 'auditTrail#processingActivities', 'url' => '/api/audit-trails/processing-activities', 'verb' => 'GET'],
        ['name' => 'auditTrail#subjectAuditTrail', 'url' => '/api/audit-trails/subject-lookup', 'verb' => 'GET'],
        ['name' => 'auditTrail#clearAll', 'url' => '/api/audit-trails/clear-all', 'verb' => 'DELETE'],
        ['name' => 'auditTrail#show', 'url' => '/api/audit-trails/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#update', 'url' => '/api/audit-trails/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#destroy', 'url' => '/api/audit-trails/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'auditTrail#destroyMultiple', 'url' => '/api/audit-trails', 'verb' => 'DELETE'],
                                                                            
                                                                                    
                                                                           
                                                                          
                                                                             
                                                                                
                                                                 
        ['name' => 'auditQuery#export', 'url' => '/api/v2/audit/export', 'verb' => 'GET'],
        ['name' => 'auditQuery#query', 'url' => '/api/v2/audit', 'verb' => 'GET'],
                                                                          
        ['name' => 'notificationHistory#index', 'url' => '/api/notification-history', 'verb' => 'GET'],
                                                                           
                                                                              
                                                                               
                                                                          
        [
            'name' => 'notificationHistory#snooze',
            'url' => '/api/notification-history/{id}/snooze',
            'verb' => 'PUT',
            'requirements' => ['id' => '\\d+'],
        ],
        [
            'name' => 'notificationHistory#archive',
            'url' => '/api/notification-history/{id}/archive',
            'verb' => 'PUT',
            'requirements' => ['id' => '\\d+'],
        ],
        ['name' => 'notificationHistory#markThreadRead', 'url' => '/api/notification-history/thread/read', 'verb' => 'PUT'],
                                                                                              
                                                                                                          
        ['name' => 'notificationSubscriptions#index',   'url' => '/api/notification-subscriptions', 'verb' => 'GET'],
        ['name' => 'notificationSubscriptions#create',  'url' => '/api/notification-subscriptions', 'verb' => 'POST'],
        ['name' => 'notificationSubscriptions#destroy', 'url' => '/api/notification-subscriptions', 'verb' => 'DELETE'],
                                                                                                 
        ['name' => 'notificationPreferences#index',  'url' => '/api/notification-preferences', 'verb' => 'GET'],
        ['name' => 'notificationPreferences#update', 'url' => '/api/notification-preferences', 'verb' => 'PUT'],
                                                                            
                                                                        
        ['name' => 'notificationTemplates#index',  'url' => '/api/notification-templates', 'verb' => 'GET'],
        ['name' => 'notificationTemplates#gaps',   'url' => '/api/notification-templates/gaps', 'verb' => 'GET'],
        ['name' => 'notificationTemplates#update', 'url' => '/api/notification-templates/{event}', 'verb' => 'PUT'],
                                                                            
                                                                              
                                                                               
        ['name' => 'notificationBroadcast#index',   'url' => '/api/notification-broadcasts', 'verb' => 'GET'],
        ['name' => 'notificationBroadcast#create',  'url' => '/api/notification-broadcasts', 'verb' => 'POST'],
        ['name' => 'notificationBroadcast#active',  'url' => '/api/notification-broadcasts/active', 'verb' => 'GET'],
        [
            'name' => 'notificationBroadcast#acknowledge',
            'url' => '/api/notification-broadcasts/{uuid}/acknowledge',
            'verb' => 'POST',
        ],
        ['name' => 'notificationBroadcast#destroy', 'url' => '/api/notification-broadcasts/{uuid}', 'verb' => 'DELETE'],
                                                                               
                                                                              
                                                           
        ['name' => 'notificationGroupPreferences#index',  'url' => '/api/notification-group-preferences', 'verb' => 'GET'],
        ['name' => 'notificationGroupPreferences#update', 'url' => '/api/notification-group-preferences', 'verb' => 'PUT'],
                                                                                         
        ['name' => 'notificationDeliveryWindow#index',  'url' => '/api/notification-delivery-window', 'verb' => 'GET'],
        ['name' => 'notificationDeliveryWindow#update', 'url' => '/api/notification-delivery-window', 'verb' => 'PUT'],
                                                                    
        ['name' => 'searchTrail#index', 'url' => '/api/search-trails', 'verb' => 'GET'],
        ['name' => 'searchTrail#statistics', 'url' => '/api/search-trails/statistics', 'verb' => 'GET'],
        ['name' => 'searchTrail#popularTerms', 'url' => '/api/search-trails/popular-terms', 'verb' => 'GET'],
        ['name' => 'searchTrail#activity', 'url' => '/api/search-trails/activity', 'verb' => 'GET'],
        ['name' => 'searchTrail#registerSchemaStats', 'url' => '/api/search-trails/register-schema-stats', 'verb' => 'GET'],
        ['name' => 'searchTrail#userAgentStats', 'url' => '/api/search-trails/user-agent-stats', 'verb' => 'GET'],
        ['name' => 'searchTrail#export', 'url' => '/api/search-trails/export', 'verb' => 'GET'],
        ['name' => 'searchTrail#cleanup', 'url' => '/api/search-trails/cleanup', 'verb' => 'POST'],
        ['name' => 'searchTrail#destroyMultiple', 'url' => '/api/search-trails', 'verb' => 'DELETE'],
        ['name' => 'searchTrail#clearAll', 'url' => '/api/search-trails/clear-all', 'verb' => 'DELETE'],
        ['name' => 'searchTrail#show', 'url' => '/api/search-trails/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'searchTrail#destroy', 'url' => '/api/search-trails/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
                           
        ['name' => 'deleted#index', 'url' => '/api/deleted', 'verb' => 'GET'],
        ['name' => 'deleted#statistics', 'url' => '/api/deleted/statistics', 'verb' => 'GET'],
        ['name' => 'deleted#topDeleters', 'url' => '/api/deleted/top-deleters', 'verb' => 'GET'],
        ['name' => 'deleted#restore', 'url' => '/api/deleted/{id}/restore', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'deleted#restoreMultiple', 'url' => '/api/deleted/restore', 'verb' => 'POST'],
        [
            'name' => 'deleted#destructionPreview',
            'url' => '/api/deleted/{id}/destruction-preview',
            'verb' => 'GET',
            'requirements' => ['id' => '[^/]+'],
        ],
        ['name' => 'deleted#destructionRecord', 'url' => '/api/deleted/{id}/destruction', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'deleted#destroy', 'url' => '/api/deleted/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'deleted#destroyMultiple', 'url' => '/api/deleted', 'verb' => 'DELETE'],
                  
        ['name' => 'revert#revert', 'url' => '/api/objects/{register}/{schema}/{id}/revert', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

                                          
		['name' => 'files#create', 'url' => '/api/objects/{register}/{schema}/{id}/files', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#save', 'url' => '/api/objects/{register}/{schema}/{id}/files/save', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#index', 'url' => '/api/objects/{register}/{schema}/{id}/files', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'files#show', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
        ['name' => 'objects#downloadFiles', 'url' => '/api/objects/{register}/{schema}/{id}/files/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#createMultipart', 'url' => '/api/objects/{register}/{schema}/{id}/filesMultipart', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#update', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#delete', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		                                                                                    
		['name' => 'files#rename',         'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/rename',                       'verb' => 'PUT',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#copy',           'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/copy',                         'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#move',           'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/move',                         'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#listVersions',   'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/versions',                     'verb' => 'GET',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#restoreVersion', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/versions/{versionId}/restore', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+', 'versionId' => '[^/]+']],
		['name' => 'files#lock',           'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/lock',                         'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#unlock',         'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/unlock',                       'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#batch',          'url' => '/api/objects/{register}/{schema}/{id}/files/batch',                                 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'files#preview',        'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/preview',                      'verb' => 'GET',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		['name' => 'files#updateLabels',   'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/labels',                       'verb' => 'PUT',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		                                                                        
		                                                                             
		['name' => 'files#updateMetadata', 'url' => '/api/objects/{register}/{schema}/{id}/files/{fileId}/metadata', 'verb' => 'PUT',  'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']],
		                                                                       
		                                                                  
		                                 
		['name' => 'files#saveMetadataForm', 'url' => '/api/objects/{register}/{schema}/{id}/files/metadata', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],

                                                    
        ['name' => 'files#downloadById', 'url' => '/api/files/{fileId}/download', 'verb' => 'GET', 'requirements' => ['fileId' => '\d+']],

                                                                           
        ['name' => 'tasks#allUserTasks', 'url' => '/api/tasks', 'verb' => 'GET'],

                                                                 
        ['name' => 'tasks#index', 'url' => '/api/objects/{register}/{schema}/{id}/tasks', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'tasks#create', 'url' => '/api/objects/{register}/{schema}/{id}/tasks', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'tasks#update', 'url' => '/api/objects/{register}/{schema}/{id}/tasks/{taskId}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+', 'taskId' => '[^/]+']],
        ['name' => 'tasks#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/tasks/{taskId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'taskId' => '[^/]+']],

                                                                       
        ['name' => 'notes#index', 'url' => '/api/objects/{register}/{schema}/{id}/notes', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'notes#create', 'url' => '/api/objects/{register}/{schema}/{id}/notes', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'notes#update', 'url' => '/api/objects/{register}/{schema}/{id}/notes/{noteId}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+', 'noteId' => '[^/]+']],
        [
            'name' => 'notes#patch',
            'url' => '/api/objects/{register}/{schema}/{id}/notes/{noteId}',
            'verb' => 'PATCH',
            'requirements' => ['id' => '[^/]+', 'noteId' => '[^/]+'],
        ],
        [
            'name' => 'notes#versions',
            'url' => '/api/objects/{register}/{schema}/{id}/notes/{noteId}/versions',
            'verb' => 'GET',
            'requirements' => ['id' => '[^/]+', 'noteId' => '[^/]+'],
        ],
        ['name' => 'notes#destroy', 'url' => '/api/objects/{register}/{schema}/{id}/notes/{noteId}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'noteId' => '[^/]+']],

                                                                          
                                                                       
                                                                             
                                                                           
        [
            'name' => 'timelineEntries#index',
            'url' => '/api/objects/{register}/{schema}/{id}/timeline',
            'verb' => 'GET',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name' => 'timelineEntries#create',
            'url' => '/api/objects/{register}/{schema}/{id}/timeline',
            'verb' => 'POST',
            'requirements' => ['id' => '[^/]+'],
        ],
        [
            'name' => 'timelineEntries#show',
            'url' => '/api/objects/{register}/{schema}/{id}/timeline/{entryId}',
            'verb' => 'GET',
            'requirements' => ['id' => '[^/]+', 'entryId' => '[^/]+'],
        ],
        [
            'name' => 'timelineEntries#update',
            'url' => '/api/objects/{register}/{schema}/{id}/timeline/{entryId}',
            'verb' => 'PATCH',
            'requirements' => ['id' => '[^/]+', 'entryId' => '[^/]+'],
        ],
        [
            'name' => 'timelineEntries#source',
            'url' => '/api/objects/{register}/{schema}/{id}/timeline/{entryId}/source',
            'verb' => 'GET',
            'requirements' => ['id' => '[^/]+', 'entryId' => '[^/]+'],
        ],

                                                                          
                                                                          
        ['name' => 'timelineEntries#searchEntries', 'url' => '/api/timeline/search', 'verb' => 'GET'],

                                                                           
                                                                              
                                                                             
        ['name' => 'timelineAdmin#kinds', 'url' => '/api/timeline/kinds', 'verb' => 'GET'],
        ['name' => 'timelineAdmin#declareKind', 'url' => '/api/timeline/kinds', 'verb' => 'POST'],
        ['name' => 'timelineAdmin#withdrawKind', 'url' => '/api/timeline/kinds/{slug}', 'verb' => 'DELETE', 'requirements' => ['slug' => '[^/]+']],
        ['name' => 'timelineAdmin#patterns', 'url' => '/api/timeline/reference-patterns', 'verb' => 'GET'],
        ['name' => 'timelineAdmin#declarePattern', 'url' => '/api/timeline/reference-patterns', 'verb' => 'POST'],
        [
            'name' => 'timelineAdmin#withdrawPattern',
            'url' => '/api/timeline/reference-patterns/{slug}',
            'verb' => 'DELETE',
            'requirements' => ['slug' => '[^/]+'],
        ],
        ['name' => 'timelineAdmin#textBlocks', 'url' => '/api/timeline/text-blocks', 'verb' => 'GET'],
        ['name' => 'timelineAdmin#declareTextBlock', 'url' => '/api/timeline/text-blocks', 'verb' => 'POST'],
        [
            'name' => 'timelineAdmin#withdrawTextBlock',
            'url' => '/api/timeline/text-blocks/{slug}',
            'verb' => 'DELETE',
            'requirements' => ['slug' => '[^/]+'],
        ],

                                                                            
                                                                             
                                                                  
        ['name' => 'handoff#availability', 'url' => '/api/objects/{register}/{schema}/{id}/handoffs', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
        ['name' => 'handoff#execute', 'url' => '/api/objects/{register}/{schema}/{id}/handoffs/{handoffId}', 'verb' => 'POST', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+', 'handoffId' => '[^/]+']],

                   
                                                                                
                                                                           
                                                                            
        ['name' => 'schemas#resolveByImplements', 'url' => '/api/schemas/resolve-by-implements', 'verb' => 'GET'],
                                                                            
                                                                             
                                                                          
                                                                            
                                                                            
                                                                                 
        ['name' => 'calculations#operators', 'url' => '/api/schemas/calculation-operators', 'verb' => 'GET'],
        ['name' => 'calculations#evaluate', 'url' => '/api/schemas/calculation-evaluate', 'verb' => 'POST'],
                                                                              
                                                                              
                                                                               
                                                                               
                                                                            
                                                                                
                                           
        ['name' => 'rules#vocabulary', 'url' => '/api/rules/vocabulary', 'verb' => 'GET'],
        ['name' => 'rules#runs', 'url' => '/api/rules/{ruleId}/runs', 'verb' => 'GET', 'requirements' => ['ruleId' => '[^/]+']],
        ['name' => 'rules#index', 'url' => '/api/schemas/{schema}/rules', 'verb' => 'GET', 'requirements' => ['schema' => '[^/]+']],
        ['name' => 'rules#setEnabled', 'url' => '/api/schemas/{schema}/rules/{ruleId}', 'verb' => 'PATCH', 'requirements' => ['schema' => '[^/]+', 'ruleId' => '[^/]+']],
        ['name' => 'rules#evaluate', 'url' => '/api/schemas/{schema}/rules/{ruleId}/evaluate', 'verb' => 'POST', 'requirements' => ['schema' => '[^/]+', 'ruleId' => '[^/]+']],
        [
            'name' => 'rules#replay',
            'url' => '/api/schemas/{schema}/rules/{ruleId}/replay',
            'verb' => 'POST',
            'requirements' => ['schema' => '[^/]+', 'ruleId' => '[^/]+'],
        ],

                                                                           
                                                                          
                                                                            
                                                                               
                                                                          
                                               
        ['name' => 'propertyVocabulary#index', 'url' => '/api/schemas/property-vocabulary', 'verb' => 'GET'],
        ['name' => 'propertyVocabulary#extendingForms', 'url' => '/api/schemas/extending-forms', 'verb' => 'GET'],
        ['name' => 'schemas#upload', 'url' => '/api/schemas/upload', 'verb' => 'POST'],
        ['name' => 'schemas#uploadUpdate', 'url' => '/api/schemas/{id}/upload', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#download', 'url' => '/api/schemas/{id}/download', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#related', 'url' => '/api/schemas/{id}/related', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
                                                                              
                                                                             
        [
            'name' => 'schemas#listPresentation',
            'url' => '/api/schemas/{id}/list-presentation',
            'verb' => 'GET',
            'requirements' => ['id' => '[^/]+'],
        ],
        ['name' => 'schemas#stats', 'url' => '/api/schemas/{id}/stats', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#explore', 'url' => '/api/schemas/{id}/explore', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'schemas#updateFromExploration', 'url' => '/api/schemas/{id}/update-from-exploration', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
                                                                                         
        ['name' => 'schemaMigration#changelog', 'url' => '/api/schemas/{id}/changelog', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'schemaMigration#revalidate', 'url' => '/api/schemas/{id}/revalidate', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'schemaMigration#runs', 'url' => '/api/schemas/{id}/runs', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'schemaMigration#run', 'url' => '/api/schemas/{id}/runs/{run}', 'verb' => 'GET', 'requirements' => ['id' => '\d+', 'run' => '\d+']],
        ['name' => 'schemaMigration#previewMigration', 'url' => '/api/schemas/{id}/migrations/preview', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'schemaMigration#migrate', 'url' => '/api/schemas/{id}/migrations', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'schemaMigration#rollback', 'url' => '/api/schemas/{id}/runs/{run}/rollback', 'verb' => 'POST', 'requirements' => ['id' => '\d+', 'run' => '\d+']],

                                                                              
                                                                            
                                                                         
                                   
                                                                                                    
        ['name' => 'schemaMigration#conversions', 'url' => '/api/schemas/property-conversions', 'verb' => 'GET'],
        ['name' => 'schemaMigration#previewConversion', 'url' => '/api/schemas/{id}/conversions/preview', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
                                                                                                                
        ['name' => 'schemaImport#types', 'url' => '/api/schema-import/{dialect}/types', 'verb' => 'GET', 'requirements' => ['dialect' => '[^/]+']],
        ['name' => 'schemaImport#snapshot', 'url' => '/api/schema-import/{dialect}/snapshot', 'verb' => 'GET', 'requirements' => ['dialect' => '[^/]+']],
        ['name' => 'schemaImport#import', 'url' => '/api/schema-import/{dialect}', 'verb' => 'POST', 'requirements' => ['dialect' => '[^/]+']],
        ['name' => 'schemaImport#reimport', 'url' => '/api/schemas/{id}/reimport', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
                    
        ['name' => 'registers#export', 'url' => '/api/registers/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#import', 'url' => '/api/registers/{id}/import', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#rollbackImport', 'url' => '/api/registers/import/rollback', 'verb' => 'POST'],
        [
            'name'         => 'registers#importTemplate',
            'url'          => '/api/registers/{id}/schemas/{schema}/import-template',
            'verb'         => 'GET',
            'requirements' => ['id' => '[^/]+', 'schema' => '[^/]+'],
        ],
        ['name' => 'registers#publishToGitHub', 'url' => '/api/registers/{id}/publish/github', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#schemas', 'url' => '/api/registers/{id}/schemas', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'registers#stats', 'url' => '/api/registers/{id}/stats', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'oas#generate', 'url' => '/api/registers/{id}/oas', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
        ['name' => 'oas#generateAll', 'url' => '/api/registers/oas', 'verb' => 'GET'],

                                                                                  
                                                                                 
                                                                              
                                                                                
                                                                          
                                                                       
          
                                                                                 
                                                                                 
                                             
        ['name' => 'apiSurface#capabilities', 'url' => '/api/capabilities', 'verb' => 'GET'],
        ['name' => 'apiSurface#versions', 'url' => '/api/versions', 'verb' => 'GET'],
        [
            'name'         => 'apiSurface#contract',
            'url'          => '/api/versions/{version}/oas',
            'verb'         => 'GET',
            'requirements' => ['version' => '[0-9]{1,3}'],
        ],

                                                                         
                                                                             
                                                                            
                                                                                
                               
        ['name' => 'apiCallers#index', 'url' => '/api/callers', 'verb' => 'GET'],
        ['name' => 'apiCallers#readDeclaration', 'url' => '/api/settings/api-versions', 'verb' => 'GET'],
        ['name' => 'apiCallers#writeDeclaration', 'url' => '/api/settings/api-versions', 'verb' => 'PUT'],

                                                                               
                                                                           
                                                                               
                                                                               
                                                                        
        ['name' => 'wellKnown#index', 'url' => '/.well-known', 'verb' => 'GET'],
        ['name' => 'wellKnown#securityTxt', 'url' => '/.well-known/security.txt', 'verb' => 'GET'],
                                                                                                                                              
        ['name' => 'configuration#index',  'url' => '/api/configuration',         'verb' => 'GET'],
        ['name' => 'configuration#show',   'url' => '/api/configuration/{id}',    'verb' => 'GET',    'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#create', 'url' => '/api/configuration',         'verb' => 'POST'],
        ['name' => 'configuration#update', 'url' => '/api/configuration/{id}',    'verb' => 'PUT',    'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#destroy','url' => '/api/configuration/{id}',    'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
                                       
        ['name' => 'configuration#versionStatus', 'url' => '/api/configurations/{id}/check-version', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#preview', 'url' => '/api/configurations/{id}/preview', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#import', 'url' => '/api/configurations/{id}/import', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
        ['name' => 'configuration#export', 'url' => '/api/configurations/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

                                             
        ['name' => 'configuration#discover', 'url' => '/api/configurations/discover', 'verb' => 'GET'],
        ['name' => 'configuration#enrichDetails', 'url' => '/api/configurations/enrich', 'verb' => 'GET'],
        ['name' => 'configuration#getGitHubBranches', 'url' => '/api/configurations/github/branches', 'verb' => 'GET'],
        ['name' => 'configuration#getGitHubRepositories', 'url' => '/api/configurations/github/repositories', 'verb' => 'GET'],
        ['name' => 'configuration#getGitHubConfigurations', 'url' => '/api/configurations/github/files', 'verb' => 'GET'],
        ['name' => 'configuration#getGitLabBranches', 'url' => '/api/configurations/gitlab/branches', 'verb' => 'GET'],
        ['name' => 'configuration#getGitLabConfigurations', 'url' => '/api/configurations/gitlab/files', 'verb' => 'GET'],

                                          
        ['name' => 'configurations#import', 'url' => '/api/configurations/import', 'verb' => 'POST'],
        ['name' => 'configuration#importFromGitHub', 'url' => '/api/configurations/import/github', 'verb' => 'POST'],
        ['name' => 'configuration#importFromGitLab', 'url' => '/api/configurations/import/gitlab', 'verb' => 'POST'],
        ['name' => 'configuration#importFromUrl', 'url' => '/api/configurations/import/url', 'verb' => 'POST'],

                                           
        ['name' => 'configuration#publishToGitHub', 'url' => '/api/configurations/{id}/publish/github', 'verb' => 'POST'],

                                              
        ['name' => 'userSettings#getGitHubTokenStatus', 'url' => '/api/user-settings/github/status', 'verb' => 'GET'],
        ['name' => 'userSettings#setGitHubToken', 'url' => '/api/user-settings/github/token', 'verb' => 'POST'],
        ['name' => 'userSettings#removeGitHubToken', 'url' => '/api/user-settings/github/token', 'verb' => 'DELETE'],
                        
        ['name' => 'applications#page', 'url' => '/applications', 'verb' => 'GET'],
                                                                 
        ['name' => 'ui#applicationDetails', 'url' => '/applications/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
                                                                              
                                                                       
        ['name' => 'agents#stats', 'url' => '/api/agents/stats', 'verb' => 'GET'],
        ['name' => 'agents#tools', 'url' => '/api/agents/tools', 'verb' => 'GET'],
                  
        ['name' => 'search#search', 'url' => '/api/search', 'verb' => 'GET'],
                                                    
        ['name' => 'organisation#index', 'url' => '/api/organisations', 'verb' => 'GET'],
        ['name' => 'organisation#create', 'url' => '/api/organisations', 'verb' => 'POST'],
        ['name' => 'organisation#search', 'url' => '/api/organisations/search', 'verb' => 'GET'],
        ['name' => 'organisation#stats', 'url' => '/api/organisations/stats', 'verb' => 'GET'],
        ['name' => 'organisation#stats', 'url' => '/api/organisations/statistics', 'verb' => 'GET', 'postfix' => 'statistics'],
        ['name' => 'organisation#clearCache', 'url' => '/api/organisations/clear-cache', 'verb' => 'POST'],
        ['name' => 'organisation#getActive', 'url' => '/api/organisations/active', 'verb' => 'GET'],
        ['name' => 'organisation#show', 'url' => '/api/organisations/{uuid}', 'verb' => 'GET'],
        ['name' => 'organisation#update', 'url' => '/api/organisations/{uuid}', 'verb' => 'PUT'],
        ['name' => 'organisation#patch', 'url' => '/api/organisations/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'organisation#setActive', 'url' => '/api/organisations/{uuid}/set-active', 'verb' => 'POST'],
        ['name' => 'organisation#join', 'url' => '/api/organisations/{uuid}/join', 'verb' => 'POST'],
        ['name' => 'organisation#leave', 'url' => '/api/organisations/{uuid}/leave', 'verb' => 'POST'],

                                                       
        ['name' => 'organisation#suspend', 'url' => '/api/organisations/{uuid}/suspend', 'verb' => 'PUT'],
        ['name' => 'organisation#activate', 'url' => '/api/organisations/{uuid}/activate', 'verb' => 'PUT'],
        ['name' => 'organisation#deprovision', 'url' => '/api/organisations/{uuid}/deprovision', 'verb' => 'PUT'],
        ['name' => 'organisation#retain', 'url' => '/api/organisations/{uuid}/retain', 'verb' => 'PUT'],
        ['name' => 'organisation#usage', 'url' => '/api/organisations/{uuid}/usage', 'verb' => 'GET'],

                                                             
        ['name' => 'organisation#isolationVerify', 'url' => '/api/admin/isolation-verify', 'verb' => 'POST'],
        ['name' => 'organisation#isolationMetrics', 'url' => '/api/admin/isolation-metrics', 'verb' => 'GET'],
		        
		['name' => 'tags#getAllTags', 'url' => '/api/tags', 'verb' => 'GET'],
		['name' => 'tags#index',     'url' => '/api/objects/{register}/{schema}/{id}/tags',         'verb' => 'GET',    'requirements' => ['id' => '[^/]+']],
		['name' => 'tags#add',       'url' => '/api/objects/{register}/{schema}/{id}/tags',         'verb' => 'POST',   'requirements' => ['id' => '[^/]+']],
		['name' => 'tags#remove',    'url' => '/api/objects/{register}/{schema}/{id}/tags/{tag}',   'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+', 'tag' => '[^/]+']],

		                                       
		['name' => 'views#index', 'url' => '/api/views', 'verb' => 'GET'],
		['name' => 'views#show', 'url' => '/api/views/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#create', 'url' => '/api/views', 'verb' => 'POST'],
		['name' => 'views#update', 'url' => '/api/views/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#patch', 'url' => '/api/views/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#destroy', 'url' => '/api/views/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		                                                                       
		                                                                          
		                                                
		['name' => 'views#kanban', 'url' => '/api/views/{id}/kanban', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'views#calendar', 'url' => '/api/views/{id}/calendar', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],

		                                 
		['name' => 'chat#sendMessage', 'url' => '/api/chat/send', 'verb' => 'POST'],
		['name' => 'chat#getHistory', 'url' => '/api/chat/history', 'verb' => 'GET'],
		['name' => 'chat#clearHistory', 'url' => '/api/chat/history', 'verb' => 'DELETE'],
		['name' => 'chat#getChatStats', 'url' => '/api/chat/stats', 'verb' => 'GET'],
		['name' => 'chat#sendFeedback', 'url' => '/api/conversations/{conversationUuid}/messages/{messageId}/feedback', 'verb' => 'POST', 'requirements' => ['conversationUuid' => '[^/]+', 'messageId' => '\\d+']],

		                                                       
		['name' => 'chatHealth#health', 'url' => '/api/chat/health', 'verb' => 'GET'],

		                                                 
		['name' => 'chatStream#stream', 'url' => '/api/chat/stream', 'verb' => 'POST'],

		                                              
		['name' => 'conversation#index', 'url' => '/api/conversations', 'verb' => 'GET'],
		['name' => 'conversation#show', 'url' => '/api/conversations/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#messages', 'url' => '/api/conversations/{uuid}/messages', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#create', 'url' => '/api/conversations', 'verb' => 'POST'],
		['name' => 'conversation#update', 'url' => '/api/conversations/{uuid}', 'verb' => 'PATCH', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#destroy', 'url' => '/api/conversations/{uuid}', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#restore', 'url' => '/api/conversations/{uuid}/restore', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'conversation#destroyPermanent', 'url' => '/api/conversations/{uuid}/permanent', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+']],

		                                                             
		['name' => 'fileText#getFileText', 'url' => '/api/files/{fileId}/text', 'verb' => 'GET', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'fileText#extractFileText', 'url' => '/api/files/{fileId}/extract', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],
		['name' => 'fileText#bulkExtract', 'url' => '/api/files/extract/bulk', 'verb' => 'POST'],
		['name' => 'fileText#getStats', 'url' => '/api/files/extraction/stats', 'verb' => 'GET'],
		['name' => 'fileText#deleteFileText', 'url' => '/api/files/{fileId}/text', 'verb' => 'DELETE', 'requirements' => ['fileId' => '\\d+']],

		                                                                               

		                                                                    
		['name' => 'fileText#anonymizeFile', 'url' => '/api/files/{fileId}/anonymize', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],

		                                                                                                                 
		['name' => 'fileText#addManualEntity', 'url' => '/api/files/{fileId}/manual-entities', 'verb' => 'POST', 'requirements' => ['fileId' => '\\d+']],

		                                                                                                             
		['name' => 'entityRelations#update', 'url' => '/api/entity-relations/{id}', 'verb' => 'PATCH', 'requirements' => ['id' => '\\d+']],

		                                                
		['name' => 'gdprEntities#index', 'url' => '/api/entities', 'verb' => 'GET'],
		['name' => 'gdprEntities#show', 'url' => '/api/entities/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\\d+']],
		['name' => 'gdprEntities#destroy', 'url' => '/api/entities/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
		['name' => 'gdprEntities#getTypes', 'url' => '/api/entities/types', 'verb' => 'GET'],
		['name' => 'gdprEntities#getCategories', 'url' => '/api/entities/categories', 'verb' => 'GET'],
		['name' => 'gdprEntities#getStats', 'url' => '/api/entities/stats', 'verb' => 'GET'],

		                                                               
		['name' => 'fileSearch#semanticSearch', 'url' => '/api/search/files/semantic', 'verb' => 'POST'],
		['name' => 'fileSearch#hybridSearch', 'url' => '/api/search/files/hybrid', 'verb' => 'POST'],

		               
		['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],                                                                     
		['name' => 'ui#registers', 'url' => '/registers', 'verb' => 'GET'],
		['name' => 'ui#registersDetails', 'url' => '/registers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'ui#schemas', 'url' => '/schemas', 'verb' => 'GET'],
		['name' => 'ui#schemasDetails', 'url' => '/schemas/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'ui#sources', 'url' => '/sources', 'verb' => 'GET'],
		['name' => 'ui#organisation', 'url' => '/organisation', 'verb' => 'GET'],
		['name' => 'ui#objects', 'url' => '/objects', 'verb' => 'GET'],
		                                                              
		                                                            
		                                                                 
		                                            
		  
		                                                                     
		                                                                      
		                                                                  
		                                  
		['name' => 'ui#objectDetail', 'url' => '/objects/{register}/{schema}/{id}', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'id' => '[^/]+']],
		                                                                     
		                                                                       
		                                                                       
		                                          
		['name' => 'ui#integrationsView', 'url' => '/integrations/{register}/{schema}/{objectId}', 'verb' => 'GET', 'requirements' => ['register' => '[^/]+', 'schema' => '[^/]+', 'objectId' => '[^/]+']],
		['name' => 'ui#tables', 'url' => '/tables', 'verb' => 'GET'],
		['name' => 'ui#configurations', 'url' => '/configurations', 'verb' => 'GET'],
		['name' => 'ui#deleted', 'url' => '/deleted', 'verb' => 'GET'],
		['name' => 'ui#auditTrail', 'url' => '/audit-trails', 'verb' => 'GET'],
		['name' => 'ui#searchTrail', 'url' => '/search-trails', 'verb' => 'GET'],
		['name' => 'ui#webhooks', 'url' => '/webhooks', 'verb' => 'GET'],
		['name' => 'ui#webhooksLogs', 'url' => '/webhooks/logs', 'verb' => 'GET'],
		['name' => 'ui#endpoints', 'url' => '/endpoints', 'verb' => 'GET'],
		['name' => 'ui#endpointLogs', 'url' => '/endpoints/logs', 'verb' => 'GET'],
		['name' => 'ui#entities', 'url' => '/entities', 'verb' => 'GET'],
		['name' => 'ui#entitiesDetails', 'url' => '/entities/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'ui#avg', 'url' => '/avg', 'verb' => 'GET'],
		['name' => 'ui#reports', 'url' => '/reports', 'verb' => 'GET'],
		['name' => 'ui#reportView', 'url' => '/reports/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		                                                   
		['name' => 'reports#render',  'url' => '/api/reports/{id}/render',  'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'reports#preview', 'url' => '/api/reports/{id}/preview', 'verb' => 'GET',  'requirements' => ['id' => '[^/]+']],
		['name' => 'ui#templates', 'url' => '/templates', 'verb' => 'GET'],
		['name' => 'ui#featuresRoadmap', 'url' => '/features-roadmap', 'verb' => 'GET'],
		                                                             
		['name' => 'ui#myAccount', 'url' => '/mijn-account', 'verb' => 'GET'],
		['name' => 'files#page', 'url' => '/files', 'verb' => 'GET'],

		                                                
		['name' => 'user#me', 'url' => '/api/user/me', 'verb' => 'GET'],
		['name' => 'user#updateMe', 'url' => '/api/user/me', 'verb' => 'PUT'],
		['name' => 'user#login', 'url' => '/api/user/login', 'verb' => 'POST'],
		['name' => 'user#logout', 'url' => '/api/user/logout', 'verb' => 'POST'],

		                                                                                
		['name' => 'user#changePassword',                  'url' => '/api/user/me/password',             'verb' => 'PUT'],
		['name' => 'user#uploadAvatar',                    'url' => '/api/user/me/avatar',               'verb' => 'POST'],
		['name' => 'user#deleteAvatar',                    'url' => '/api/user/me/avatar',               'verb' => 'DELETE'],
		['name' => 'user#exportData',                      'url' => '/api/user/me/export',               'verb' => 'GET'],
		['name' => 'user#getNotificationPreferences',      'url' => '/api/user/me/notifications',        'verb' => 'GET'],
		['name' => 'user#updateNotificationPreferences',   'url' => '/api/user/me/notifications',        'verb' => 'PUT'],
		['name' => 'user#getActivity',                     'url' => '/api/user/me/activity',             'verb' => 'GET'],
		['name' => 'user#listTokens',                      'url' => '/api/user/me/tokens',               'verb' => 'GET'],
		['name' => 'user#createToken',                     'url' => '/api/user/me/tokens',               'verb' => 'POST'],
		['name' => 'user#revokeToken',                     'url' => '/api/user/me/tokens/{id}',          'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		['name' => 'user#requestDeactivation',             'url' => '/api/user/me/deactivate',           'verb' => 'POST'],
		['name' => 'user#getDeactivationStatus',           'url' => '/api/user/me/deactivation-status',  'verb' => 'GET'],
		['name' => 'user#cancelDeactivation',              'url' => '/api/user/me/deactivate',           'verb' => 'DELETE'],

		            
		['name' => 'webhooks#index', 'url' => '/api/webhooks', 'verb' => 'GET'],
		['name' => 'webhooks#show', 'url' => '/api/webhooks/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#create', 'url' => '/api/webhooks', 'verb' => 'POST'],
		['name' => 'webhooks#update', 'url' => '/api/webhooks/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#destroy', 'url' => '/api/webhooks/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#test', 'url' => '/api/webhooks/{id}/test', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#events', 'url' => '/api/webhooks/events', 'verb' => 'GET'],
		['name' => 'webhooks#logs', 'url' => '/api/webhooks/{id}/logs', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#logStats', 'url' => '/api/webhooks/{id}/logs/stats', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'webhooks#allLogs', 'url' => '/api/webhooks/logs', 'verb' => 'GET'],
		['name' => 'webhooks#retry', 'url' => '/api/webhooks/logs/{logId}/retry', 'verb' => 'POST', 'requirements' => ['logId' => '\d+']],

		                                                                        
		                                                                     
		                                                                   
		                                                                       
		                                                                       
		                                  
		                                                               
		                                                                  
		                                                             
		                                                                  
		['name' => 'exportRuns#index', 'url' => '/api/exports', 'verb' => 'GET'],
		['name' => 'exportProfiles#index', 'url' => '/api/export-profiles', 'verb' => 'GET'],
		['name' => 'exportProfiles#contract', 'url' => '/api/export-profiles/contract', 'verb' => 'GET'],
		['name' => 'exportProfiles#create', 'url' => '/api/export-profiles', 'verb' => 'POST'],
		['name' => 'exportProfiles#show', 'url' => '/api/export-profiles/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'exportProfiles#update', 'url' => '/api/export-profiles/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'exportProfiles#destroy', 'url' => '/api/export-profiles/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'exportProfiles#run', 'url' => '/api/export-profiles/{id}/run', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		                                                                    
		                                                                      
		                                                                      
		                                               
		['name' => 'scheduledReports#index', 'url' => '/api/scheduled-reports', 'verb' => 'GET'],
		['name' => 'scheduledReports#show', 'url' => '/api/scheduled-reports/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'scheduledReports#create', 'url' => '/api/scheduled-reports', 'verb' => 'POST'],
		['name' => 'scheduledReports#update', 'url' => '/api/scheduled-reports/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'scheduledReports#destroy', 'url' => '/api/scheduled-reports/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'scheduledReports#runNow', 'url' => '/api/scheduled-reports/{id}/run-now', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		                                                                 
		                                                                     
		                                                                 
		                                                                  
		                                                                
		                                                                   
		['name' => 'migrationPacks#index', 'url' => '/api/migration-packs', 'verb' => 'GET'],
		['name' => 'migrationPacks#create', 'url' => '/api/migration-packs', 'verb' => 'POST'],
		['name' => 'migrationPacks#import', 'url' => '/api/migration-packs/import', 'verb' => 'POST'],
		['name' => 'migrationPacks#show', 'url' => '/api/migration-packs/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'migrationPacks#update', 'url' => '/api/migration-packs/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\d+']],
		['name' => 'migrationPacks#destroy', 'url' => '/api/migration-packs/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'migrationPacks#export', 'url' => '/api/migration-packs/{id}/export', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],

		                                            

		                                                                            

		                                                                    


		                                                      
		                                                                             
		['name' => 'mcp#discover', 'url' => '/api/mcp/v1/discover', 'verb' => 'GET'],
		                                                                         
		                                                                       
		['name' => 'mcp#grantableRights', 'url' => '/api/mcp/v1/grantable-rights', 'verb' => 'GET'],
		['name' => 'mcp#discoverCapability', 'url' => '/api/mcp/v1/discover/{capability}', 'verb' => 'GET', 'requirements' => ['capability' => '[a-z-]+']],

		                                                                 
		['name' => 'mcpServer#handle', 'url' => '/api/mcp', 'verb' => 'POST'],

		               
		['name' => 'graphQL#execute', 'url' => '/api/graphql', 'verb' => 'POST'],
		['name' => 'graphQL#explorer', 'url' => '/api/graphql/explorer', 'verb' => 'GET'],

		                               
		['name' => 'graphQLSubscription#subscribe', 'url' => '/api/graphql/subscribe', 'verb' => 'GET'],

		                                           
		['name' => 'Settings\ConfigurationSettings#getArchivalSettings', 'url' => '/api/settings/archival', 'verb' => 'GET'],
		['name' => 'Settings\ConfigurationSettings#updateArchivalSettings', 'url' => '/api/settings/archival', 'verb' => 'PUT'],
		['name' => 'Settings\ConfigurationSettings#updateArchivalSettings', 'url' => '/api/settings/archival', 'verb' => 'PATCH', 'postfix' => 'patch'],

		                                                            
		['name' => 'retention#approveDestructionList', 'url' => '/api/retention/destruction-lists/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'retention#rejectDestructionList', 'url' => '/api/retention/destruction-lists/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

		                                     
		['name' => 'retention#placeLegalHold', 'url' => '/api/retention/legal-holds', 'verb' => 'POST'],
		['name' => 'retention#releaseLegalHold', 'url' => '/api/retention/legal-holds/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		['name' => 'retention#placeBulkLegalHold', 'url' => '/api/retention/legal-holds/bulk', 'verb' => 'POST'],

		                                                                                  
		['name' => 'archival#listDestructionLists', 'url' => '/api/archival/destruction-lists', 'verb' => 'GET'],
		['name' => 'archival#getDestructionList', 'url' => '/api/archival/destruction-lists/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#approveDestructionList', 'url' => '/api/archival/destruction-lists/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#rejectDestructionList', 'url' => '/api/archival/destruction-lists/{id}/reject', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#createLegalHold', 'url' => '/api/archival/legal-holds', 'verb' => 'POST'],
		['name' => 'archival#releaseLegalHold', 'url' => '/api/archival/legal-holds/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '[^/]+']],
		['name' => 'archival#listLegalHolds', 'url' => '/api/archival/legal-holds', 'verb' => 'GET'],
		['name' => 'archival#listCertificates', 'url' => '/api/archival/certificates', 'verb' => 'GET'],

		                                                                         
		                                                                         
		                                                                         
		['name' => 'archival#assignReviewer', 'url' => '/api/archival/destruction-lists/{id}/entries/{entryId}/reviewer', 'verb' => 'PUT', 'requirements' => ['id' => '[^/]+', 'entryId' => '[^/]+']],
		['name' => 'archival#decideEntry', 'url' => '/api/archival/destruction-lists/{id}/entries/{entryId}/decision', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+', 'entryId' => '[^/]+']],
		['name' => 'archival#myPendingReviews', 'url' => '/api/archival/reviews/pending', 'verb' => 'GET'],
		['name' => 'archival#recomputeNomination', 'url' => '/api/archival/objects/{id}/nomination/recompute', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

		                                                                  
		                                                                     
		['name' => 'selectielijst#import', 'url' => '/api/archival/selectielijst/import', 'verb' => 'POST'],
		['name' => 'selectielijst#versions', 'url' => '/api/archival/selectielijst/versions', 'verb' => 'GET'],
		['name' => 'selectielijst#diff', 'url' => '/api/archival/selectielijst/diff', 'verb' => 'GET'],

		                             
		['name' => 'Settings\EdepotSettings#getEdepotSettings', 'url' => '/api/settings/edepot', 'verb' => 'GET'],
		['name' => 'Settings\EdepotSettings#updateEdepotSettings', 'url' => '/api/settings/edepot', 'verb' => 'PUT'],
		['name' => 'Settings\EdepotSettings#updateEdepotSettings', 'url' => '/api/settings/edepot', 'verb' => 'PATCH', 'postfix' => 'patch'],
		['name' => 'Settings\EdepotSettings#testEdepotConnection', 'url' => '/api/settings/edepot/test', 'verb' => 'POST'],

		                               
		['name' => 'transfer#index', 'url' => '/api/transfers', 'verb' => 'GET'],
		['name' => 'transfer#show', 'url' => '/api/transfers/{id}', 'verb' => 'GET', 'requirements' => ['id' => '[^/]+']],
		['name' => 'transfer#create', 'url' => '/api/transfers', 'verb' => 'POST'],
		                                                                          
		                                                                      
		                                                                      
		                                                                      
		['name' => 'transfer#approve', 'url' => '/api/transfers/{id}/approve', 'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],
		['name' => 'transfer#reject',  'url' => '/api/transfers/{id}/reject',  'verb' => 'POST', 'requirements' => ['id' => '[^/]+']],

		                                                                             
		                                                                                 
		                                                                            
		                                                                                
		['name' => 'gitHubIssues#index', 'url' => '/api/github/issues', 'verb' => 'GET'],
		['name' => 'gitHubIssues#create', 'url' => '/api/github/issues', 'verb' => 'POST'],

		                                                                  
		['name' => 'flowRun#index', 'url' => '/api/flow-runs', 'verb' => 'GET'],
		                                                                           
		                                                                         
		                                                                       
		                                                                         
		                                                        
		['name' => 'flowRun#active', 'url' => '/api/flow-runs/active', 'verb' => 'GET'],
		                                                                          
		                                                                             
		                                                                            
		                                                                   
		['name' => 'flowRun#completedForSubject', 'url' => '/api/flow-runs/completed', 'verb' => 'GET'],
		['name' => 'flowRun#show', 'url' => '/api/flow-runs/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'flowRun#objects', 'url' => '/api/flow-runs/{uuid}/objects', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'flowRun#retry', 'url' => '/api/flow-runs/{uuid}/retry', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'flowRun#resume', 'url' => '/api/flow-runs/{uuid}/resume', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
			                                                          
			                                                                
			                                                                
			                                                                  
			                                                                  
			                                                         
		['name' => 'flowRunMigration#migrate', 'url' => '/api/flow-runs/{uuid}/migrate', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
			                                                                  
			                                                               
			                                                   
		['name' => 'flowRunMigration#migrateRuns', 'url' => '/api/flows/{flow}/migrate-runs', 'verb' => 'POST', 'requirements' => ['flow' => '[^/]+']],
		                                                                       
		                                                                     
		                                                                      
		                                                                   
		                         
		['name' => 'flowRun#signalByKey', 'url' => '/api/flow-run-signals/{key}', 'verb' => 'POST', 'requirements' => ['key' => '[^/]+']],
		                                                                                                     
		['name' => 'flowTestRun#test', 'url' => '/api/flow-runs/test', 'verb' => 'POST'],
		                                                               
		                                                                    
		                                                                    
		                                                                    
		                                                                     
		                                                                     
		                                                             
		                                                                         
		                                                                   
		                                                                       
		                                                                          
		                                                             
		                                                                    
		['name' => 'task#open', 'url' => '/flow-tasks/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#index', 'url' => '/api/flow-tasks', 'verb' => 'GET'],
		['name' => 'task#create', 'url' => '/api/flow-tasks', 'verb' => 'POST'],
		['name' => 'task#show', 'url' => '/api/flow-tasks/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#audit', 'url' => '/api/flow-tasks/{uuid}/audit', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#offer', 'url' => '/api/flow-tasks/{uuid}/offer', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#claim', 'url' => '/api/flow-tasks/{uuid}/claim', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#unclaim', 'url' => '/api/flow-tasks/{uuid}/unclaim', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#assign', 'url' => '/api/flow-tasks/{uuid}/assign', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#reassign', 'url' => '/api/flow-tasks/{uuid}/reassign', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#delegate', 'url' => '/api/flow-tasks/{uuid}/delegate', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#resolve', 'url' => '/api/flow-tasks/{uuid}/resolve', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#complete', 'url' => '/api/flow-tasks/{uuid}/complete', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#cancel', 'url' => '/api/flow-tasks/{uuid}/cancel', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'task#checkItem', 'url' => '/api/flow-tasks/{uuid}/checklist/{itemId}', 'verb' => 'PATCH', 'requirements' => ['uuid' => '[^/]+', 'itemId' => '[^/]+']],
		                                                                       
		                                                               
		                                                                    
		                                                                    
		                                                                  
		                                                                  
		                                                                      
		                                                                    
		                                                                  
		                                                                    
		                                                                     
		                                                                   
		                    

		                                                                   
		                                                                   
		                                                                    
		                                                                
		                                                             
		                                                                     
		                                                                     
		                                                                  
		                                                                     
		                                                                    
		                                                               
		                                               
		['name' => 'taskNotes#index', 'url' => '/api/flow-tasks/{uuid}/notes', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'taskNotes#create', 'url' => '/api/flow-tasks/{uuid}/notes', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'taskNotes#update', 'url' => '/api/flow-tasks/{uuid}/notes/{noteId}', 'verb' => 'PUT', 'requirements' => ['uuid' => '[^/]+', 'noteId' => '[^/]+']],
		['name' => 'taskNotes#destroy', 'url' => '/api/flow-tasks/{uuid}/notes/{noteId}', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+', 'noteId' => '[^/]+']],
		                                                                       
		['name' => 'taskEvents#index', 'url' => '/api/flow-tasks/{uuid}/events', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'taskEvents#create', 'url' => '/api/flow-tasks/{uuid}/events', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'taskEvents#link', 'url' => '/api/flow-tasks/{uuid}/events/link', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'taskEvents#unlink', 'url' => '/api/flow-tasks/{uuid}/events/{eventUid}/link', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+', 'eventUid' => '[^/]+']],
		['name' => 'taskEvents#destroy', 'url' => '/api/flow-tasks/{uuid}/events/{eventId}', 'verb' => 'DELETE', 'requirements' => ['uuid' => '[^/]+', 'eventId' => '[^/]+']],

		                                                                    
		                                                             
		                                                                     
		                                                                    
		                                                                     
		                                                                 
		                                                                  
		['name' => 'portalTask#index', 'url' => '/api/portal-tasks', 'verb' => 'GET'],
		['name' => 'portalTask#deliveries', 'url' => '/api/portal-tasks/deliveries', 'verb' => 'GET'],
		['name' => 'portalTask#deliveryDelivered', 'url' => '/api/portal-tasks/deliveries/{uuid}/delivered', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'portalTask#deliveryFailed', 'url' => '/api/portal-tasks/deliveries/{uuid}/failed', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'portalTask#show', 'url' => '/api/portal-tasks/{uuid}', 'verb' => 'GET', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'portalTask#complete', 'url' => '/api/portal-tasks/{uuid}/complete', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],

		                                                                     
		                                                                       
		                                                                         
		                                                                        
		                                                                  
		                                                                  
		                                                                    
		                                                                          
		['name' => 'case#items', 'url' => '/api/cases/items', 'verb' => 'GET'],
		['name' => 'case#skeletonFromZaaktype', 'url' => '/api/cases/skeleton-from-zaaktype', 'verb' => 'POST'],
		['name' => 'case#transition', 'url' => '/api/cases/items/{uuid}/transition', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'case#enable', 'url' => '/api/cases/items/{uuid}/enable', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'case#show', 'url' => '/api/cases/{objectUuid}', 'verb' => 'GET', 'requirements' => ['objectUuid' => '[^/]+']],
		['name' => 'case#create', 'url' => '/api/cases/{objectUuid}', 'verb' => 'POST', 'requirements' => ['objectUuid' => '[^/]+']],
		['name' => 'case#destroy', 'url' => '/api/cases/{objectUuid}', 'verb' => 'DELETE', 'requirements' => ['objectUuid' => '[^/]+']],
		['name' => 'case#evaluate', 'url' => '/api/cases/{objectUuid}/evaluate', 'verb' => 'POST', 'requirements' => ['objectUuid' => '[^/]+']],
		['name' => 'case#enableable', 'url' => '/api/cases/{objectUuid}/enableable', 'verb' => 'GET', 'requirements' => ['objectUuid' => '[^/]+']],
		['name' => 'case#attach', 'url' => '/api/cases/{objectUuid}/items', 'verb' => 'POST', 'requirements' => ['objectUuid' => '[^/]+']],
		['name' => 'case#complete', 'url' => '/api/cases/{objectUuid}/complete', 'verb' => 'POST', 'requirements' => ['objectUuid' => '[^/]+']],
		                                                                         
		                                                                          
		                                                      
		  
		                                                                    
		                                                                      
		                                                                         
		                                         
		['name' => 'delegation#index', 'url' => '/api/delegations', 'verb' => 'GET'],
		['name' => 'delegation#request', 'url' => '/api/delegations', 'verb' => 'POST'],
		['name' => 'delegation#answer', 'url' => '/api/delegations/{uuid}/answer', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		['name' => 'delegation#revoke', 'url' => '/api/delegations/{uuid}/revoke', 'verb' => 'POST', 'requirements' => ['uuid' => '[^/]+']],
		                                                                                                                                    
		['name' => 'federatedConfig#types', 'url' => '/api/federated-config/types', 'verb' => 'GET'],
		['name' => 'federatedConfig#bundle', 'url' => '/api/federated-config/bundle', 'verb' => 'POST'],
		['name' => 'federatedConfig#install', 'url' => '/api/federated-config/install', 'verb' => 'POST'],
		['name' => 'federatedConfig#publish', 'url' => '/api/federated-config/publish', 'verb' => 'POST'],
		['name' => 'federatedConfig#discover', 'url' => '/api/federated-config/discover', 'verb' => 'GET'],
		['name' => 'federatedConfig#fetch', 'url' => '/api/federated-config/fetch', 'verb' => 'GET'],
		['name' => 'federatedConfig#publicKey', 'url' => '/api/federated-config/public-key', 'verb' => 'GET'],
		['name' => 'federatedConfig#trust', 'url' => '/api/federated-config/trust', 'verb' => 'GET'],
		['name' => 'federatedConfig#setTrust', 'url' => '/api/federated-config/trust', 'verb' => 'PUT'],

		                                                                     
		                                                                     
		                                                                       
		                                                                         
		                                                    
		                                                                      
		                                                                       
		                                                                        
		                                                                       
		                                              
		                                                                      
		                                                                    
		                                                                 
		                                                       
		                                                                   
		                                                             
		['name' => 'dashboard#catchAll', 'url' => '/{path}', 'verb' => 'GET',
			'requirements' => ['path' => '(?!api/).+'], 'defaults' => ['path' => '']],
    ],
];
