# Design: flow-task-search-provider

No new screen: results appear in Nextcloud's search bar and open the existing task page. OpenRegister is not one of the canvas apps.

## D-1: the inbox query is the access rule

The provider builds a `TaskInboxCriteria` with scope `all` for the current user (uid, groups, admin flag) and the term. `all` already means "assigned to me, offered to my groups, or watched by me" for a non-admin; an admin's `all` is narrowed to the same three for search, so the search bar does not list the whole instance's work to an administrator.

## D-2: matching

`term` matches title and description with the same accent-insensitive comparison the object search uses (`search-accent-insensitive`). Terminal tasks are included after open ones, so a finished task stays findable.

## D-3: the link

The link is `/apps/openregister/flow-tasks/{uuid}`. When the task's subject schema's owning app registered a task route in the deep link registry (`DeepLinkRegistryService`), that route wins. dossiq registers its own when it has one.

## D-4: subline

`{state} · due {date} · {subject title}`; overdue in the state word ("overdue"). The subject title comes from the inbox row's subject context, which the inbox already resolves under the user's rights; an unreadable subject shows no title.
