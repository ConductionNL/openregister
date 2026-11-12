<?php

/**
 * Recorded outcomes for MergeHandlerMergeObjectsTest.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

return array (
  'no target' => 
  array (
    'calls' => 
    array (
    ),
    'exception' => 'InvalidArgumentException: Target object ID is required',
  ),
  'empty target' => 
  array (
    'calls' => 
    array (
    ),
    'exception' => 'InvalidArgumentException: Target object ID is required',
  ),
  'source not found' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
    ),
    'exception' => 'OCP\\AppFramework\\Db\\DoesNotExistException: Source object not found',
  ),
  'target not found' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
    ),
    'exception' => 'OCP\\AppFramework\\Db\\DoesNotExistException: Target object not found',
  ),
  'both missing' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
    ),
    'exception' => 'OCP\\AppFramework\\Db\\DoesNotExistException: Source object not found',
  ),
  'other register' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
    ),
    'exception' => 'InvalidArgumentException: Objects must be in the same register',
  ),
  'other schema' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
    ),
    'exception' => 'InvalidArgumentException: Objects must conform to the same schema',
  ),
  'defaults' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'properties merged' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"same","count":2,"keep":1,"new":["x"]},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'same',
        'count' => '2',
        'keep' => 1,
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'same',
        'count' => 2,
        'keep' => 1,
        'new' => 
        array (
          0 => 'x',
        ),
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
          0 => 
          array (
            'property' => 'count',
            'oldValue' => '2',
            'newValue' => 2,
          ),
          1 => 
          array (
            'property' => 'new',
            'oldValue' => NULL,
            'newValue' => 
            array (
              0 => 'x',
            ),
          ),
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 2,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'transfer without folder' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'transfer files' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'getFiles src',
      3 => 'addFile ["tgt","a.pdf","bytes of a.pdf",false,[],null,null,null]',
      4 => 'deleteFile a.pdf from src',
      5 => 'addFile ["tgt","b.pdf","bytes of b.pdf",false,[],null,null,null]',
      6 => 'deleteFile b.pdf from src',
      7 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      8 => 'findByRelation ["src","src",true]',
      9 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => '17',
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
          0 => 
          array (
            'name' => 'a.pdf',
            'action' => 'transferred',
            'success' => true,
          ),
          1 => 
          array (
            'name' => 'b.pdf',
            'action' => 'transferred',
            'success' => true,
          ),
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 2,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'transfer a file that stays' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'getFiles src',
      3 => 'addFile ["tgt","a.pdf","bytes of a.pdf",false,[],null,null,null]',
      4 => 'deleteFile a.pdf from src',
      5 => 'deleteFile copy of a.pdf from tgt',
      6 => 'addFile ["tgt","b.pdf","bytes of b.pdf",false,[],null,null,null]',
      7 => 'deleteFile b.pdf from src',
      8 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      9 => 'findByRelation ["src","src",true]',
      10 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => '17',
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
          0 => 
          array (
            'name' => 'a.pdf',
            'action' => 'transfer_failed',
            'success' => false,
            'error' => 'The file could not be removed from the source object',
          ),
          1 => 
          array (
            'name' => 'b.pdf',
            'action' => 'transferred',
            'success' => true,
          ),
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 1,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
        0 => 'Failed to transfer file a.pdf: The file could not be removed from the source object',
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'transfer copy fails' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'getFiles src',
      3 => 'addFile ["tgt","a.pdf","bytes of a.pdf",false,[],null,null,null]',
      4 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      5 => 'findByRelation ["src","src",true]',
      6 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => '17',
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
          0 => 
          array (
            'name' => 'a.pdf',
            'action' => 'transfer_failed',
            'success' => false,
            'error' => 'copy failed',
          ),
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
        0 => 'Failed to transfer file a.pdf: copy failed',
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'transfer skips folders' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'getFiles src',
      3 => 'addFile ["tgt","a.pdf","bytes of a.pdf",false,[],null,null,null]',
      4 => 'deleteFile a.pdf from src',
      5 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      6 => 'findByRelation ["src","src",true]',
      7 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => '17',
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
          0 => 
          array (
            'name' => 'a.pdf',
            'action' => 'transferred',
            'success' => true,
          ),
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 1,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'transfer listing fails' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'getFiles src',
      3 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      4 => 'findByRelation ["src","src",true]',
      5 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => '17',
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
        0 => 'Failed to access source files: listing failed',
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'delete files' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'getFiles src',
      3 => 'deleteFile a.pdf from src',
      4 => 'deleteFile b.pdf from src',
      5 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      6 => 'findByRelation ["src","src",true]',
      7 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => '17',
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
          0 => 
          array (
            'name' => 'a.pdf',
            'action' => 'deleted',
            'success' => true,
          ),
          1 => 
          array (
            'name' => 'b.pdf',
            'action' => 'deleted',
            'success' => true,
          ),
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 2,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'delete a file that stays' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'getFiles src',
      3 => 'deleteFile a.pdf from src',
      4 => 'deleteFile b.pdf from src',
      5 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      6 => 'findByRelation ["src","src",true]',
      7 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => '17',
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
          0 => 
          array (
            'name' => 'a.pdf',
            'action' => 'delete_failed',
            'success' => false,
            'error' => 'The file could not be deleted',
          ),
          1 => 
          array (
            'name' => 'b.pdf',
            'action' => 'deleted',
            'success' => true,
          ),
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 1,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
        0 => 'Failed to delete file a.pdf: The file could not be deleted',
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'delete listing fails' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'getFiles src',
      3 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      4 => 'findByRelation ["src","src",true]',
      5 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => '17',
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
        0 => 'Failed to access source files: listing failed',
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'delete without folder' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'other file action' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => '17',
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'relations transferred' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r2","r9","r1",{"nested":"r3"}],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r1","r2",{"nested":"r3"}],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r1',
            1 => 'r2',
            2 => 
            array (
              'nested' => 'r3',
            ),
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r2',
            1 => 'r9',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r2',
            1 => 'r9',
            2 => 'r1',
            3 => 
            array (
              'nested' => 'r3',
            ),
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
            0 => 'r1',
            1 => 
            array (
              'nested' => 'r3',
            ),
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 2,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'relations transferred from none' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r9"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":[],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r9',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r9',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'relations dropped' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r9"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r1","r2"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r1',
            1 => 'r2',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r9',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r9',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'dropped',
          'relations' => 
          array (
            0 => 'r1',
            1 => 'r2',
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 2,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'relations other action' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r1"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r1',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'dropped',
          'relations' => 
          array (
            0 => 'r1',
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 1,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'references updated' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'findAcrossAllSources ["ref-1",false,false,false,null]',
      5 => 'update {"uuid":"ref-1","object":{"id":"ref-1","link":"tgt"},"relations":{"a":"tgt","b":["tgt","z"]},"deleted":[],"register":3,"schema":5}',
      6 => 'findAcrossAllSources ["ref-2",false,false,false,null]',
      7 => 'update {"uuid":"ref-2","object":{"id":"ref-2","link":"other"},"relations":["tgt"],"deleted":[],"register":3,"schema":5}',
      8 => 'findAcrossAllSources ["ref-3",false,false,false,null]',
      9 => 'update {"uuid":"ref-3","object":{"id":"ref-3","deep":{"list":["tgt","y"]}},"relations":["x"],"deleted":[],"register":3,"schema":5}',
      10 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
          0 => 
          array (
            'objectId' => 'ref-1',
            'title' => 'One',
          ),
          1 => 
          array (
            'objectId' => 'ref-2',
            'title' => 'ref-2',
          ),
          2 => 
          array (
            'objectId' => 'ref-3',
            'title' => 'Three',
          ),
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 3,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'references not transferred' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'tgt',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r0',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
        ),
        'files' => 
        array (
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
          ),
        ),
        'references' => 
        array (
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 0,
        'filesTransferred' => 0,
        'filesDeleted' => 0,
        'relationsTransferred' => 0,
        'relationsDropped' => 0,
        'referencesUpdated' => 0,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
  'no user to delete as' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
    ),
    'exception' => 'Exception: No user logged in',
  ),
  'target update fails' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
    ),
    'exception' => 'Exception: update failed: tgt',
  ),
  'source update fails' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r0"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'exception' => 'Exception: update failed: src',
  ),
  'reference update fails' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"tgt"},"relations":["r0"],"deleted":[],"register":3,"schema":5}',
      3 => 'findByRelation ["src","src",true]',
      4 => 'findAcrossAllSources ["ref-1",false,false,false,null]',
      5 => 'update {"uuid":"ref-1","object":{"id":"ref-1"},"relations":["tgt"],"deleted":[],"register":3,"schema":5}',
    ),
    'exception' => 'Exception: update failed: ref-1',
  ),
  'everything' => 
  array (
    'calls' => 
    array (
      0 => 'findAcrossAllSources ["src",false,false,false,null]',
      1 => 'findAcrossAllSources ["tgt",false,false,false,null]',
      2 => 'getFiles src',
      3 => 'addFile ["tgt","a.pdf","bytes of a.pdf",false,[],null,null,null]',
      4 => 'deleteFile a.pdf from src',
      5 => 'update {"uuid":"tgt","object":{"id":"tgt","title":"merged"},"relations":["r1"],"deleted":[],"register":3,"schema":5}',
      6 => 'findByRelation ["src","src",true]',
      7 => 'findAcrossAllSources ["ref-1",false,false,false,null]',
      8 => 'update {"uuid":"ref-1","object":{"id":"ref-1","link":"tgt"},"relations":[],"deleted":[],"register":3,"schema":5}',
      9 => 'update {"uuid":"src","object":{"id":"src","title":"src"},"relations":["r1"],"deleted":{"deleted":"<time>","deletedAt":"<time>","deletedBy":"merger","deletedReason":"Merged into object tgt","retentionPeriod":30,"purgeDate":"<time>","destroyableFrom":"<time>"},"register":3,"schema":5}',
    ),
    'report' => 
    array (
      'success' => true,
      'sourceObject' => 
      array (
        'title' => 'src',
        '@self' => 
        array (
          'id' => 'src',
          'slug' => NULL,
          'name' => 'src',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r1',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => '17',
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'src',
      ),
      'targetObject' => 
      array (
        'title' => 'old',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
        'id' => 'tgt',
      ),
      'mergedObject' => 
      array (
        'id' => 'tgt',
        'title' => 'merged',
        '@self' => 
        array (
          'id' => 'tgt',
          'slug' => NULL,
          'name' => 'tgt',
          'description' => NULL,
          'summary' => NULL,
          'image' => NULL,
          'uri' => NULL,
          'version' => NULL,
          'register' => '3',
          'schema' => '5',
          'schemaVersion' => NULL,
          'files' => 
          array (
          ),
          'relations' => 
          array (
            0 => 'r1',
          ),
          'locked' => NULL,
          'owner' => NULL,
          'organisation' => NULL,
          'groups' => 
          array (
          ),
          'authorization' => 
          array (
          ),
          'folder' => NULL,
          'application' => NULL,
          'validation' => 
          array (
          ),
          'quality' => 
          array (
          ),
          'geo' => 
          array (
          ),
          'retention' => 
          array (
          ),
          'tmlo' => 
          array (
          ),
          'size' => NULL,
          'updated' => NULL,
          'lastUserActivity' => NULL,
          'created' => NULL,
          'deleted' => 
          array (
          ),
          'archived' => 
          array (
          ),
          'frozen' => 
          array (
          ),
          'status' => NULL,
          'source' => NULL,
          'mail' => NULL,
          'contacts' => NULL,
          'notes' => NULL,
          'todos' => NULL,
          'calendar' => NULL,
          'talk' => NULL,
          'deck' => NULL,
        ),
      ),
      'actions' => 
      array (
        'properties' => 
        array (
          0 => 
          array (
            'property' => 'title',
            'oldValue' => 'old',
            'newValue' => 'merged',
          ),
        ),
        'files' => 
        array (
          0 => 
          array (
            'name' => 'a.pdf',
            'action' => 'transferred',
            'success' => true,
          ),
        ),
        'relations' => 
        array (
          'action' => 'transferred',
          'relations' => 
          array (
            0 => 'r1',
          ),
        ),
        'references' => 
        array (
          0 => 
          array (
            'objectId' => 'ref-1',
            'title' => 'One',
          ),
        ),
      ),
      'statistics' => 
      array (
        'propertiesChanged' => 1,
        'filesTransferred' => 1,
        'filesDeleted' => 0,
        'relationsTransferred' => 1,
        'relationsDropped' => 0,
        'referencesUpdated' => 1,
      ),
      'warnings' => 
      array (
      ),
      'errors' => 
      array (
      ),
    ),
  ),
);
