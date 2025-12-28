<?php

/**
 * Migration to create object text, chunk, and GDPR entity tables.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Builds the schema required for enhanced text extraction and GDPR tracking.
 */
class Version1Date20251116000000 extends SimpleMigrationStep
{
    /**
     * Apply schema changes.
     *
     * @param IOutput $output        Output helper.
     * @param Closure $schemaClosure Schema factory.
     * @param array   $options       Migration options.
     *
     * @return ISchemaWrapper Updated schema.
     *
     * @SuppressWarnings (PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        $this->createObjectTextTable(output: $output, schema: $schema);
        $this->createChunksTable(output: $output, schema: $schema);
        $this->createEntitiesTable(output: $output, schema: $schema);
        $this->createEntityRelationsTable(output: $output, schema: $schema);

        return $schema;
    }//end changeSchema()

    /**
     * Create the object text table.
     *
     * @param IOutput        $output Output helper.
     * @param ISchemaWrapper $schema Database schema.
     *
     * @return void
     */
    private function createObjectTextTable(IOutput $output, ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('openregister_object_texts') === true) {
            $output->info(message: 'ℹ️  Table openregister_object_texts already exists, skipping.');
            return;
        }

        $table = $schema->createTable('openregister_object_texts');
        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                    'autoincrement' => true,
                    'notnull'       => true,
                    'unsigned'      => true,
                ]
        );
        $table->addColumn(
            'uuid',
            Types::STRING,
            [
                    'length'  => 64,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'object_id',
            Types::BIGINT,
            [
                    'notnull'  => true,
                    'unsigned' => true,
                ]
        );
        $table->addColumn(
            'register',
            Types::STRING,
            [
                    'length'  => 255,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'schema',
            Types::STRING,
            [
                    'length'  => 255,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'text_blob',
            Types::TEXT,
            [
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'text_length',
            Types::INTEGER,
            [
                    'notnull' => true,
                    'default' => 0,
                ]
        );
        $table->addColumn(
            'property_map',
            Types::JSON,
            [
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'extraction_status',
            Types::STRING,
            [
                    'length'  => 32,
                    'default' => 'completed',
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'chunked',
            Types::BOOLEAN,
            [
                    'default' => false,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'chunk_count',
            Types::INTEGER,
            [
                    'default' => 0,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'owner',
            Types::STRING,
            [
                    'length'  => 255,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'organisation',
            Types::STRING,
            [
                    'length'  => 255,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'created_at',
            Types::DATETIME,
            [
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'updated_at',
            Types::DATETIME,
            [
                    'notnull' => true,
                ]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'object_texts_uuid_idx');
        $table->addIndex(['object_id'], 'object_texts_object_idx');
        $table->addIndex(['register'], 'object_texts_register_idx');
        $table->addIndex(['schema'], 'object_texts_schema_idx');
        $table->addIndex(['owner'], 'object_texts_owner_idx');
        $table->addIndex(['organisation'], 'object_texts_org_idx');

        $output->info(message: '✅ Created openregister_object_texts table.');
    }//end createObjectTextTable()

    /**
     * Create the chunk table.
     *
     * @param IOutput        $output Output helper.
     * @param ISchemaWrapper $schema Database schema.
     *
     * @return void
     */
    private function createChunksTable(IOutput $output, ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('openregister_chunks') === true) {
            $output->info(message: 'ℹ️  Table openregister_chunks already exists, skipping.');
            return;
        }

        $table = $schema->createTable('openregister_chunks');
        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                    'autoincrement' => true,
                    'notnull'       => true,
                    'unsigned'      => true,
                ]
        );
        $table->addColumn(
            'uuid',
            Types::STRING,
            [
                    'length'  => 64,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'source_type',
            Types::STRING,
            [
                    'length'  => 50,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'source_id',
            Types::BIGINT,
            [
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'text_content',
            Types::TEXT,
            [
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'start_offset',
            Types::INTEGER,
            [
                    'notnull' => true,
                    'default' => 0,
                ]
        );
        $table->addColumn(
            'end_offset',
            Types::INTEGER,
            [
                    'notnull' => true,
                    'default' => 0,
                ]
        );
        $table->addColumn(
            'chunk_index',
            Types::INTEGER,
            [
                    'notnull' => true,
                    'default' => 0,
                ]
        );
        $table->addColumn(
            'position_reference',
            Types::JSON,
            [
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'language',
            Types::STRING,
            [
                    'length'  => 10,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'language_level',
            Types::STRING,
            [
                    'length'  => 20,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'language_confidence',
            Types::DECIMAL,
            [
                    'precision' => 3,
                    'scale'     => 2,
                    'default'   => 0,
                    'notnull'   => false,
                ]
        );
        $table->addColumn(
            'detection_method',
            Types::STRING,
            [
                    'length'  => 50,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'indexed',
            Types::BOOLEAN,
            [
                    'default' => false,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'embedding_provider',
            Types::STRING,
            [
                    'length'  => 100,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'overlap_size',
            Types::INTEGER,
            [
                    'default' => 0,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'vectorized',
            Types::BOOLEAN,
            [
                    'default' => false,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'owner',
            Types::STRING,
            [
                    'length'  => 255,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'organisation',
            Types::STRING,
            [
                    'length'  => 255,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'created_at',
            Types::DATETIME,
            [
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'updated_at',
            Types::DATETIME,
            [
                    'notnull' => true,
                ]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'chunks_uuid_idx');
        $table->addIndex(['source_type', 'source_id'], 'chunks_source_idx');
        $table->addIndex(['language'], 'chunks_language_idx');
        $table->addIndex(['language_level'], 'chunks_level_idx');
        $table->addIndex(['indexed'], 'chunks_indexed_idx');
        $table->addIndex(['vectorized'], 'chunks_vector_idx');
        $table->addIndex(['owner'], 'chunks_owner_idx');
        $table->addIndex(['organisation'], 'chunks_org_idx');

        $output->info(message: '✅ Created openregister_chunks table.');
    }//end createChunksTable()

    /**
     * Create the entities table.
     *
     * @param IOutput        $output Output helper.
     * @param ISchemaWrapper $schema Database schema.
     *
     * @return void
     */
    private function createEntitiesTable(IOutput $output, ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('openregister_entities') === true) {
            $output->info(message: 'ℹ️  Table openregister_entities already exists, skipping.');
            return;
        }

        $table = $schema->createTable('openregister_entities');
        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                    'autoincrement' => true,
                    'notnull'       => true,
                    'unsigned'      => true,
                ]
        );
        $table->addColumn(
            'uuid',
            Types::STRING,
            [
                    'length'  => 64,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'type',
            Types::STRING,
            [
                    'length'  => 50,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'value',
            Types::TEXT,
            [
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'category',
            Types::STRING,
            [
                    'length'  => 50,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'belongs_to_entity_id',
            Types::BIGINT,
            [
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'metadata',
            Types::JSON,
            [
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'owner',
            Types::STRING,
            [
                    'length'  => 255,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'organisation',
            Types::STRING,
            [
                    'length'  => 255,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'detected_at',
            Types::DATETIME,
            [
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'updated_at',
            Types::DATETIME,
            [
                    'notnull' => true,
                ]
        );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['uuid'], 'entities_uuid_idx');
        $table->addIndex(['type'], 'entities_type_idx');
        $table->addIndex(['category'], 'entities_category_idx');
        $table->addIndex(['belongs_to_entity_id'], 'entities_parent_idx');
        $table->addIndex(['owner'], 'entities_owner_idx');
        $table->addIndex(['organisation'], 'entities_org_idx');

        $output->info(message: '✅ Created openregister_entities table.');
    }//end createEntitiesTable()

    /**
     * Create the entity relations table.
     *
     * @param IOutput        $output Output helper.
     * @param ISchemaWrapper $schema Database schema.
     *
     * @return void
     */
    private function createEntityRelationsTable(IOutput $output, ISchemaWrapper $schema): void
    {
        if ($schema->hasTable('openregister_entity_relations') === true) {
            $output->info(message: 'ℹ️  Table openregister_entity_relations already exists, skipping.');
            return;
        }

        $table = $schema->createTable('openregister_entity_relations');
        $table->addColumn(
            'id',
            Types::BIGINT,
            [
                    'autoincrement' => true,
                    'notnull'       => true,
                    'unsigned'      => true,
                ]
        );
        $table->addColumn(
            'entity_id',
            Types::BIGINT,
            [
                    'notnull'  => true,
                    'unsigned' => true,
                ]
        );
        $table->addColumn(
            'chunk_id',
            Types::BIGINT,
            [
                    'notnull'  => true,
                    'unsigned' => true,
                ]
        );
        $table->addColumn(
            'role',
            Types::STRING,
            [
                    'length'  => 50,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'file_id',
            Types::BIGINT,
            [
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'object_id',
            Types::BIGINT,
            [
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'email_id',
            Types::BIGINT,
            [
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'position_start',
            Types::INTEGER,
            [
                    'notnull' => true,
                    'default' => 0,
                ]
        );
        $table->addColumn(
            'position_end',
            Types::INTEGER,
            [
                    'notnull' => true,
                    'default' => 0,
                ]
        );
        $table->addColumn(
            'confidence',
            Types::DECIMAL,
            [
                    'precision' => 3,
                    'scale'     => 2,
                    'default'   => 0,
                    'notnull'   => true,
                ]
        );
        $table->addColumn(
            'detection_method',
            Types::STRING,
            [
                    'length'  => 50,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'context',
            Types::TEXT,
            [
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'anonymized',
            Types::BOOLEAN,
            [
                    'default' => false,
                    'notnull' => true,
                ]
        );
        $table->addColumn(
            'anonymized_value',
            Types::STRING,
            [
                    'length'  => 255,
                    'notnull' => false,
                ]
        );
        $table->addColumn(
            'created_at',
            Types::DATETIME,
            [
                    'notnull' => true,
                ]
        );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['entity_id'], 'entity_relations_entity_idx');
        $table->addIndex(['chunk_id'], 'entity_relations_chunk_idx');
        $table->addIndex(['role'], 'entity_relations_role_idx');
        $table->addIndex(['file_id'], 'entity_relations_file_idx');
        $table->addIndex(['object_id'], 'entity_relations_object_idx');
        $table->addIndex(['email_id'], 'entity_relations_email_idx');
        $table->addIndex(['anonymized'], 'entity_relations_anon_idx');

        // NOTE: Foreign key constraints removed to avoid migration issues.
        // The indexes above provide query performance benefits.
        // Foreign key constraints can be added in a separate migration if needed.
        // Referential integrity is maintained at the application level.
        $output->info(message: '✅ Created openregister_entity_relations table.');
    }//end createEntityRelationsTable()
}//end class
