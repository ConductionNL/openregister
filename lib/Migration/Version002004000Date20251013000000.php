<?php

/**
 * OpenRegister Chat Conversation History Table Migration
 *
 * This migration creates the openregister_conversations table to store
 * AI chat conversation history.
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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version002004000Date20251013000000 extends SimpleMigrationStep
{
    /**
     * Create conversations table for AI chat history.
     *
     * @param IOutput $output        Output interface for logging
     * @param Closure $schemaClosure Schema retrieval closure
     * @param array   $options       Migration options
     *
     * @return null|ISchemaWrapper Modified schema or null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Create openregister_chat_history table for conversation storage.
        if ($schema->hasTable('openregister_chat_history') === false) {
            $table = $schema->createTable('openregister_chat_history');

            // Primary key.
            $table->addColumn(
                'id',
                'bigint',
                [
                    'autoincrement' => true,
                    'notnull'       => true,
                    'length'        => 20,
                ]
            );

            // User who sent the message.
            $table->addColumn(
                'user_id',
                'string',
                [
                    'notnull' => true,
                    'length'  => 64,
                ]
            );

            // User message.
            $table->addColumn(
                'user_message',
                'text',
                [
                    'notnull' => true,
                ]
            );

            // AI response.
            $table->addColumn(
                'ai_response',
                'text',
                [
                    'notnull' => true,
                ]
            );

            // Context sources used for the response (JSON array).
            $table->addColumn(
                'context_sources',
                'text',
                [
                    'notnull' => false,
                    'default' => null,
                ]
            );

            // User feedback (positive, negative, or null).
            $table->addColumn(
                'feedback',
                'string',
                [
                    'notnull' => false,
                    'length'  => 20,
                    'default' => null,
                ]
            );

            // Timestamp.
            $table->addColumn(
                'created_at',
                'bigint',
                [
                    'notnull' => true,
                    'default' => 0,
                ]
            );

            // Set primary key.
            $table->setPrimaryKey(['id']);

            // Add indexes for common queries.
            $table->addIndex(['user_id'], 'idx_chat_user_id');
            $table->addIndex(['created_at'], 'idx_chat_created_at');
            $table->addIndex(['user_id', 'created_at'], 'idx_chat_user_created');
        }//end if

        return $schema;
    }//end changeSchema()

    /**
     * Rollback migration.
     *
     * @param IOutput $output        Output interface for logging
     * @param Closure $schemaClosure Schema retrieval closure
     * @param array   $options       Migration options
     *
     * @return null Modified schema or null
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        return null;
    }//end postSchemaChange()
}//end class
