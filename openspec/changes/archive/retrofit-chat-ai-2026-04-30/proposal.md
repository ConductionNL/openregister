# Retrofit — chat-ai

Describes observed behavior of 20 methods across 4 files under `chat-ai` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units
- lib/Controller/ChatController.php::sendMessage
- lib/Controller/ChatController.php::getHistory
- lib/Controller/ChatController.php::clearHistory
- lib/Controller/ChatController.php::getChatStats
- lib/Controller/ChatController.php::sendFeedback
- lib/Service/ChatService.php::processMessage
- lib/Controller/ConversationController.php::index
- lib/Controller/ConversationController.php::show
- lib/Controller/ConversationController.php::create
- lib/Controller/ConversationController.php::update
- lib/Controller/ConversationController.php::destroy
- lib/Controller/ConversationController.php::restore
- lib/Controller/ConversationController.php::destroyPermanent
- lib/Controller/ConversationController.php::messages
- lib/Controller/AgentsController.php::index
- lib/Controller/AgentsController.php::show
- lib/Controller/AgentsController.php::create
- lib/Controller/AgentsController.php::update
- lib/Controller/AgentsController.php::patch
- lib/Controller/AgentsController.php::destroy
- lib/Controller/AgentsController.php::stats
- lib/Controller/AgentsController.php::tools

## Approach
- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces any observed-but-suspicious behavior

Source: openspec/coverage-report.md generated 2026-04-30. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
