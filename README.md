# NexaBoard

A threaded discussion board for MediaWiki 1.45+.

## Features

- `Special:NexaBoard/Username` — visit any user's board
- Threaded posts with titled subjects
- Nested replies, aimed at a specific message rather than the thread
- Permalinks and visible IDs for every thread and message
- Editing of posts and replies, with an "edited" marker
- Close/reopen (a closed thread accepts no replies) and follow/unfollow
- Soft-delete with restore, for whole threads or individual messages
- Bulk delete of selected threads, or of every reply in them
- Merge threads and move messages between them, within one board
- Every moderation action is written to `Special:Log/nexaboard`
- Echo notifications for posts, replies and @mentions, deep-linked to the message
- UserProfileV2 avatar integration with letter-fallback

## Installation

1. Copy the `NexaBoard/` directory to `extensions/NexaBoard/`
2. Add to `LocalSettings.php`:
   ```php
   wfLoadExtension( 'NexaBoard' );
   ```
3. Run the database updater:
   ```bash
   php maintenance/update.php
   ```
   https://www.mediawiki.org/wiki/Extension:NexaBoard

## Permissions

| Right | Default | Description |
|---|---|---|
| `nexaboard-post` | logged-in users | Post messages and replies |
| `nexaboard-edit-own` | logged-in users | Edit and delete own messages |
| `nexaboard-edit-others` | sysop | Edit anyone's messages |
| `nexaboard-close` | sysop | Close any thread, and reopen one closed by anyone |
| `nexaboard-delete` | sysop | Soft-delete and restore threads and messages |
| `nexaboard-merge` | sysop | Merge threads |
| `nexaboard-move` | sysop | Move messages between threads |

Board owners can close threads on their own board without holding
`nexaboard-close`. Reopening is narrower: you may reopen a thread you closed
yourself, but undoing someone else's close needs the right, so a board owner
cannot reverse a moderator's decision.

Deletion is deliberately **not** granted to board owners. Closing is an owner's
tool — "this conversation is finished" — while deleting hides a thread from
everyone without `nexaboard-delete`. The threads most worth hiding are warnings,
complaints and evidence, and those land on the board of the person they concern;
letting that person remove them is a conflict of interest that logging does not
resolve. Authors may still delete their own individual messages.

Anonymous posting is controlled by granting `nexaboard-post` to `*`; it is off
by default.

## Configuration

| Variable | Default | Description |
|---|---|---|
| `$wgNexaBoardThreadsPerPage` | `20` | Threads per page |
| `$wgNexaBoardMaxTitleLength` | `200` | Max subject length |
| `$wgNexaBoardMaxBodyLength` | `65535` | Max message length |
| `$wgNexaBoardRedirectUserTalk` | `true` | Redirect plain `User talk:` views to the board |

## UserProfileV2 Integration

If UserProfileV2 is installed, avatars are pulled automatically via
`Telepedia\UserProfileV2\Avatar\UserProfileV2Avatar`. When not installed or
when a user has no custom avatar, a colored circle with their initial is shown.

## User talk pages

By default the extension takes over `User talk:` as the entry point to a board.
A plain view of `User talk:Example` redirects to `Special:NexaBoard/Example`, and talk
links elsewhere on the wiki are rewritten to point at the board.

The redirect is deliberately narrow, so existing talk content stays reachable:

- Talk **subpages** (archives) are never redirected.
- `?action=history`, diffs and `?oldid=` are never redirected.
- Any non-view action (`edit`, `raw`, `delete`, …) is never redirected.
- `?redirect=no` bypasses it, as with any other redirect.

Set `$wgNexaBoardRedirectUserTalk = false;` to turn the takeover off entirely and
leave talk pages alone.

## Notes

- The extension works fully without UserProfileV2.
- All deletion is soft: rows are flagged, never removed, and everything the UI
  deletes can be restored.
