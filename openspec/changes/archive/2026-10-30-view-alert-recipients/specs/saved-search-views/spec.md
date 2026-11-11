## ADDED Requirements

### Requirement: Alert recipients must be able to read the view

Saving a view's alert SHALL refuse a new recipient the caller may not name.
The owner and an administrator MAY name a user the view reaches (owner,
administrator, member of a shared group, or anyone when the view is public)
and a group the view is shared with (or any group when public). A member with
write access MAY name only themselves. Recipients already on the stored alert
SHALL NOT be judged again. A refusal SHALL answer 403 with
`field: alert.recipients` and the refused recipients, and SHALL write nothing.

#### Scenario: the owner names someone who cannot read the view
- GIVEN a private view shared with group finance
- WHEN the owner saves an alert naming `user:reader` (in finance) and `user:stranger` (not)
- THEN the save is refused with 403 naming `user:stranger`

#### Scenario: a write member names somebody else
- GIVEN a member with write access through group sales
- WHEN they save an alert naming anyone other than themselves
- THEN the save is refused with 403 naming those recipients

#### Scenario: a write member changes the owner's threshold
- GIVEN an alert the owner set up for `user:owner` and `finance`
- WHEN a write member saves it with a new threshold and the same recipients
- THEN the save succeeds
