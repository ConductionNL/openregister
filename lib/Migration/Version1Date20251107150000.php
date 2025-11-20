<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create feedback table for storing user feedback on AI messages
 */
class Version1Date20251107150000 extends SimpleMigrationStep
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
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if (!$schema->hasTable('openregister_feedback')) {
            $table = $schema->createTable('openregister_feedback');

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
                        'notnull' => true,
                        'length'  => 36,
                    ]
                    );
            $table->addColumn(
                    'message_id',
                    Types::BIGINT,
                    [
                        'notnull'  => true,
                        'unsigned' => true,
                    ]
                    );
            $table->addColumn(
                    'conversation_id',
                    Types::BIGINT,
                    [
                        'notnull'  => true,
                        'unsigned' => true,
                    ]
                    );
            $table->addColumn(
                    'agent_id',
                    Types::BIGINT,
                    [
                        'notnull'  => true,
                        'unsigned' => true,
                    ]
                    );
            $table->addColumn(
                    'user_id',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 64,
                    ]
                    );
            $table->addColumn(
                    'organisation',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 36,
                    ]
                    );
            $table->addColumn(
                    'type',
                    Types::STRING,
                    [
                        'notnull' => true,
                        'length'  => 20,
                        'comment' => 'positive or negative',
                    ]
                    );
            $table->addColumn(
                    'comment',
                    Types::TEXT,
                    [
                        'notnull' => false,
                        'comment' => 'Optional user comment about the feedback',
                    ]
                    );
            $table->addColumn(
                    'created',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                    ]
                    );
            $table->addColumn(
                    'updated',
                    Types::DATETIME,
                    [
                        'notnull' => false,
                    ]
                    );

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['uuid'], 'openregister_feedback_uuid');
            $table->addIndex(['message_id'], 'openregister_feedback_message');
            $table->addIndex(['conversation_id'], 'openregister_feedback_conv');
            $table->addIndex(['agent_id'], 'openregister_feedback_agent');
            $table->addIndex(['user_id'], 'openregister_feedback_user');
            $table->addIndex(['organisation'], 'openregister_feedback_org');
            $table->addIndex(['type'], 'openregister_feedback_type');

            // Composite index for finding existing feedback by message and user.
            $table->addIndex(['message_id', 'user_id'], 'openregister_feedback_msg_user');

            return $schema;
        }//end if

        return null;

    }//end changeSchema()


}//end class
