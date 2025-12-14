<?php

/**
 * OpenRegister Agents Table Migration
 *
 * This migration creates the agents table for storing AI agent configurations
 * and settings.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to create agents table
 *
 * Creates table for AI agents with support for:
 * - Multiple LLM providers (OpenAI, Ollama, Fireworks, Azure)
 * - RAG (Retrieval-Augmented Generation) configuration
 * - Agent types (chat, automation, analysis, assistant)
 * - Organisation and user ownership
 */
class Version1Date20251102160000 extends SimpleMigrationStep
{


    /**
     * Create agents table for AI agent configurations.
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        $output->info(message: '🤖 Creating agents table...');

        if ($schema->hasTable('openregister_agents') === false) {
            $table = $schema->createTable('openregister_agents');

            // Primary key.
            $table->addColumn(
                    'id',
                    Types::BIGINT,
                    [
                        'autoincrement' => true,
                        'notnull'       => true,
                        'unsigned'      => true,
                    ]
                    );
            $table->setPrimaryKey(['id']);

            // UUID for external reference.
            $table->addColumn(
                    'uuid',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 255,
                        'comment' => 'Unique identifier for the agent',
                    ]
                    );
            $table->addUniqueIndex(['uuid'], 'agents_uuid_index');

            // Basic information.
            $table->addColumn(
                    'name',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 255,
                        'comment' => 'Agent name',
                    ]
                    );

            $table->addColumn(
                    'description',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Agent description',
                    ]
                    );

            $table->addColumn(
                    'type',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 50,
                        'default' => 'chat',
                        'comment' => 'Agent type: chat, automation, analysis, assistant',
                    ]
                    );

            // LLM Configuration.
            $table->addColumn(
                    'provider',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 50,
                        'default' => null,
                        'comment' => 'LLM provider: openai, ollama, fireworks, azure',
                    ]
                    );

            $table->addColumn(
                    'model',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => null,
                        'comment' => 'Model identifier (e.g., gpt-4o-mini, llama3)',
                    ]
                    );

            $table->addColumn(
                    'prompt',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'System prompt for the agent',
                    ]
                    );

            $table->addColumn(
                    'temperature',
                    Types::FLOAT,
                    [
                        'notnull' => false,
                        'default' => 0.7,
                        'comment' => 'Temperature setting (0.0-2.0)',
                    ]
                    );

            $table->addColumn(
                    'max_tokens',
                    Types::INTEGER,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Maximum tokens to generate',
                    ]
                    );

            $table->addColumn(
                    'configuration',
                    Types::JSON,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Additional configuration settings',
                    ]
                    );

            // Ownership.
            $table->addColumn(
                    'organisation',
                    Types::BIGINT,
                    [
                        'notnull'  => false,
                        'default'  => null,
                        'unsigned' => true,
                        'comment'  => 'Organisation ID that owns this agent',
                    ]
                    );
            $table->addIndex(['organisation'], 'agents_organisation_index');

            $table->addColumn(
                    'owner',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                        'default' => null,
                        'comment' => 'Owner user ID',
                    ]
                    );
            $table->addIndex(['owner'], 'agents_owner_index');

            // Status.
            $table->addColumn(
                    'active',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => true,
                        'comment' => 'Whether the agent is active',
                    ]
                    );

            // RAG Configuration.
            $table->addColumn(
                    'enable_rag',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => false,
                        'comment' => 'Enable Retrieval-Augmented Generation',
                    ]
                    );

            $table->addColumn(
                    'rag_search_mode',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 20,
                        'default' => 'hybrid',
                        'comment' => 'RAG search mode: hybrid, semantic, keyword',
                    ]
                    );

            $table->addColumn(
                    'rag_num_sources',
                    Types::INTEGER,
                    [
                        'notnull' => false,
                        'default' => 5,
                        'comment' => 'Number of sources to retrieve for RAG',
                    ]
                    );

            $table->addColumn(
                    'rag_include_files',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => false,
                        'comment' => 'Include files in RAG search',
                    ]
                    );

            $table->addColumn(
                    'rag_include_objects',
                    Types::BOOLEAN,
                    [
                        'notnull' => true,
                        'default' => false,
                        'comment' => 'Include objects in RAG search',
                    ]
                    );

            // Resource Quotas.
            $table->addColumn(
                    'request_quota',
                    Types::INTEGER,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'API request quota per day (0 = unlimited)',
                    ]
                    );

            $table->addColumn(
                    'token_quota',
                    Types::INTEGER,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Token quota per request (0 = unlimited)',
                    ]
                    );

            // Access Control.
            $table->addColumn(
                    'groups',
                    Types::JSON,
                    [
                        'notnull' => false,
                        'default' => null,
                        'comment' => 'Nextcloud group IDs with access to this agent',
                    ]
                    );

            // Timestamps.
            $table->addColumn(
                    'created',
                    Types::DATETIME,
                    [
                        'notnull' => true,
                        'comment' => 'Creation timestamp',
                    ]
                    );

            $table->addColumn(
                    'updated',
                    Types::DATETIME,
                    [
                        'notnull' => true,
                        'comment' => 'Last update timestamp',
                    ]
                    );

            $output->info(message: '   ✓ Created agents table structure');
            $output->info(message: '   ✓ Added indexes for uuid, organisation, and owner');
            $output->info(message: '✅ Agents table created successfully');
            $output->info('🎯 Agents table supports:');
            $output->info(message: '   • Multiple LLM providers (OpenAI, Ollama, Fireworks, Azure)');
            $output->info(message: '   • RAG (Retrieval-Augmented Generation) configuration');
            $output->info(message: '   • Agent types (chat, automation, analysis, assistant)');
            $output->info(message: '   • Organisation and user ownership');

            return $schema;
        } else {
            $output->info(message: '⚠️  Agents table already exists!');
        }//end if

        return null;

    }//end changeSchema()


}//end class
