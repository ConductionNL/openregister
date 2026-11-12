# A fragment may write Collectives pages

## Why
The Werkinstructie card on four dossiq case boards links to the Collectives page that the case type names (`knowledgeBasePage`, "<collective>/<page>"). The board shows that page's title and "Uit Collectives, team …". An example set could not write such a page, so the link pointed at nothing.

## What changes
- A new `collectivesPages` part. Each row names the `collective`, the page `title`, its Markdown `content` and the `owner` account. It may give `shareWith`: the Nextcloud groups added to the collective's team.
- The collective is found by name or created; creating one creates its team. The page is found by title or created with its content.
- A second install keeps both.
- Remove deletes the page the set wrote, and the collective (with its team) when the set created it. An existing collective and its other pages stay.
- Without Collectives, the row is reported as not written.
