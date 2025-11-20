<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create table for AI chat conversation history
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */
class Version002004000Date20251013000000 extends SimpleMigrationStep
{


    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         *
         *
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Create openregister_chat_history table for conversation storage
        if (!$schema->hasTable('openregister_chat_history')) {
            $table = $schema->createTable('openregister_chat_history');

            // Primary key
            $table->addColumn(
            'id',
            'bigint',
            [
                'autoincrement' => true,
                'notnull'       => true,
                'length'        => 20,
            ]
            );

            // User who sent the message
            $table->addColumn(
            'user_id',
            'string',
            [
                'notnull' => true,
                'length'  => 64,
            ]
            );

            // User message
            $table->addColumn(
            'user_message',
            'text',
            [
                'notnull' => true,
            ]
            );

            // AI response
            $table->addColumn(
            'ai_response',
            'text',
            [
                'notnull' => true,
            ]
            );

            // Context sources used for the response (JSON array)
            $table->addColumn(
            'context_sources',
            'text',
            [
                'notnull' => false,
                'default' => null,
            ]
            );

            // User feedback (positive, negative, or null)
            $table->addColumn(
            'feedback',
            'string',
            [
                'notnull' => false,
                'length'  => 20,
                'default' => null,
            ]
            );

            // Timestamp
            $table->addColumn(
            'created_at',
            'bigint',
            [
                'notnull' => true,
                'default' => 0,
            ]
            );

            // Set primary key
            $table->setPrimaryKey(['id']);

            // Add indexes for common queries
            $table->addIndex(['user_id'], 'idx_chat_user_id');
            $table->addIndex(['created_at'], 'idx_chat_created_at');
            $table->addIndex(['user_id', 'created_at'], 'idx_chat_user_created');
        }//end if

        return $schema;

    }//end changeSchema()


    /**
     * Rollback migration
     *
     * @param IOutput $output
     * @param Closure $schemaClosure
     * @param array   $options
     *
     * @return null|ISchemaWrapper
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        return null;

    }//end postSchemaChange()


}//end class
