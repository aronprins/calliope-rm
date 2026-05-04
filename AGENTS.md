# Agents guide — working on Calliope RM issues

You're an AI/dev agent working on a repo that uses **Calliope RM** as its public roadmap. Calliope RM is a thin proxy: GitHub Issues + Labels are the source of truth, and a frontend renders the issues labeled `public` as a kanban board for customers.

This guide tells you how to handle issues so the public board stays accurate, customer PII stays private, and triage queues stay clean.

## TL;DR cheat sheet

| You're doing… | Do this |
| --- | --- |
| Picking up a triaged issue | Add `status:in-progress`, leave `public` and `type:*` alone |
| Finished and merged | Add `status:shipped`, remove `status:in-progress`, **close** the issue |
| Triaging a fresh customer submission | Decide visibility → if public, add `public` + `status:planned` + any `priority:*` |
| Declining a request | Just close it. Don't add `public`. The submitter's "Your submissions" tracker will show "Closed" |
| Posting an update customers should see | Comment normally from a team account (`OWNER`/`MEMBER`/`COLLABORATOR`) |
| Adding an internal note inside a public comment | Wrap it in `<!-- ... -->` |
| Anything inside a customer-submitted issue body | **Never remove the `<!-- CUSTOMER_HIDE_START/END -->` markers** — they're what keep submitter PII off the public board |

## The label model

Four label namespaces drive everything. Use them; don't invent new structural labels.

### `public` — the visibility gate
**No `public` label = invisible to customers.** Period. This is the single switch that decides whether an issue appears on the roadmap.

- Internal-only work? Don't add `public`.
- Customer-facing roadmap item? Add `public`.
- Want to "soft-close" something without telling customers? Just close it without `public` — the submitter sees it as "Closed" via the `/api/status` endpoint, but the issue body never appears publicly.

### `status:*` — kanban column
| Label | Column shown to customers |
| --- | --- |
| `status:planned` | Planned |
| `status:in-progress` | In Progress |
| `status:shipped` | Shipped |
| _(none)_ | Defaults to Planned |

**One status label at a time.** Swap, don't accumulate. When you start work, remove `status:planned` and add `status:in-progress`. When you ship, swap to `status:shipped` and close the issue.

### `type:*` — pill color on the card
| Label | Treatment |
| --- | --- |
| `type:bug` | Red pill |
| `type:feature` | Blue pill |
| `type:improvement` | Green pill |

Customer submissions arrive with one of these already applied based on their selection. **Don't change a customer's `type:*` without good reason** — it's how they categorized their own report. If they got it wrong, fine, but think twice.

### `area:*` and `env:*` — routing context
- `area:*` — which part of the product (e.g. `area:auth`, `area:billing`)
- `env:*` — environment where the issue surfaced (e.g. `env:production`, `env:staging`)

These show on the public card as small pills (good — context is useful). Add them generously during triage if the customer didn't.

### Hidden from the public card
These never show as visible pills, even when present:
- `public`
- `from-customer`
- Any `status:*` (it sets the column instead)
- Any `type:*` (it sets the pill color instead)

Anything else you add (`priority:high`, `team:platform`, `epic:foo`) **will be visible** on the public card. If you want a label hidden, either prefix it with one of the namespaces above, or extend the strip list in `transform_issue` (server-side).

## Triage flow

When a fresh customer submission lands, it has labels `from-customer` + `type:*` (and optionally `area:*` / `env:*`). The body has a `<!-- CUSTOMER_HIDE_START -->...<!-- CUSTOMER_HIDE_END -->` block at the bottom containing submitter name, email, page URL, user agent, etc.

1. **Read the issue.** Look past the customer-supplied summary; check screenshots, repro steps, environment.
2. **Spam or duplicate?** Close it. Don't add `public`. Optionally comment with the duplicate link before closing.
3. **Legitimate?**
   - Add `public` (this puts it on the roadmap).
   - Add `status:planned` (or whatever column you want it in).
   - Add `area:*` / `env:*` if missing.
   - Add internal labels (`priority:*`, milestones, assignee) as you'd normally.
4. **Triage queue:** the standard filter is `is:open label:from-customer -label:public`.

> **Never add `public` to an issue whose body still contains raw PII outside the `CUSTOMER_HIDE` markers.** The server-side strip relies on the markers being intact. Customer submissions arrive with the markers in place — leave them alone. If you're labeling a hand-created issue as `public`, make sure no email/name appears unwrapped in the body.

## Working an issue (the agent loop)

When you pick up a triaged issue:

1. **Move it to In Progress.**
   ```bash
   gh issue edit N --remove-label status:planned --add-label status:in-progress
   ```
   The card moves columns within ~60s (board cache TTL).

2. **Do the work.** Reference the issue number in commits and PRs (`Fixes #N`).

3. **Post public progress as a comment.** From a team account, just write normally — it's customer-visible by default:
   ```bash
   gh issue comment N --body "Reproduced on staging — fix targeted for v1.42."
   ```

4. **Mix an internal note into a public comment** with HTML comments:
   ```markdown
   Fix shipped in v1.42 — see release notes for setup.

   <!-- triage: monitor error rate dashboards for 7d before closing the parent epic -->
   ```
   The `<!-- ... -->` part is stripped server-side before the comment reaches the customer. They see only the first line.

5. **Whole-block internal aside** (multiple paragraphs, headings, lists): wrap with the body markers:
   ```markdown
   Public-facing update here.

   <!-- CUSTOMER_HIDE_START -->
   ## Internal follow-up
   - confirm legal sign-off
   - schedule retro
   <!-- CUSTOMER_HIDE_END -->
   ```

6. **Ship and close.**
   ```bash
   gh issue edit N --remove-label status:in-progress --add-label status:shipped
   gh issue close N
   ```
   The card moves to Shipped with a ✓ marker. The submitter's "Your submissions" tracker shows it as Shipped/Closed.

## Common mistakes to avoid

- **Don't strip `<!-- CUSTOMER_HIDE_START/END -->` markers from customer-submitted issue bodies.** That's what keeps name/email off the public board. If you edit an issue body, preserve these markers around the original submitter/metadata sections.
- **Don't add `public` to an internal-only issue.** Even if the description is benign, customers will see it. If you're not sure, leave `public` off — the safe default.
- **Don't accumulate `status:*` labels.** Swap, don't add. Two status labels = undefined column.
- **Don't echo customer email/name in public comments.** Reply with "Hi there!" or use first names, but don't quote the full email/identifying info — the comment is public.
- **Don't include the GitHub token in any code path that touches the client.** It lives only in env (php-fpm pool config or Worker secrets). Never log it, never echo it in error responses.
- **Don't bypass the `transform_issue` strip on the server.** If you add a new endpoint, run customer-bound bodies through the same `<!-- CUSTOMER_HIDE -->` strip and HTML-comment strip before returning JSON.
- **Don't expect instant updates on the public board.** GitHub → board has a 60-second cache. Hard-refresh after the TTL, or clear the cache file (`rm /tmp/calliope-board-*.json`) if you need to see a change immediately during testing.

## Public comments — quick reference

| You want… | Put this in the comment |
| --- | --- |
| Public update from a team account | Just write it normally. |
| Public update from a non-team account or to surface a customer's reply | Include `<!-- public -->` anywhere in the body. |
| Short internal note alongside a public one | `<!-- internal: ... -->` |
| Whole multi-line internal section | Wrap in `<!-- CUSTOMER_HIDE_START --> ... <!-- CUSTOMER_HIDE_END -->` |
| Fully internal comment | Either wrap the whole thing in `<!-- ... -->`, or post from a non-team account without `<!-- public -->` (non-team comments default to hidden). |

To verify your filtering worked, hit the endpoint directly:
```bash
curl -s https://yourdomain.com/api/comments?issue=N | jq '.comments[].body'
```
If you see internal text in that output, the strip didn't work — fix the markers and clear the comments cache (`rm /tmp/calliope-comments-*.json`).

## When closing without shipping

Some issues won't ship — wontfix, can't reproduce, declined, etc. The pattern depends on whether the issue is `public`:

- **Not `public`** (e.g. fresh submission you're declining): just `gh issue close N`. The submitter sees "Closed" in their per-device tracker via `/api/status`. They never see your reasoning unless you make it public.
- **Already `public`** (on the roadmap, decided not to ship): comment with the reasoning ("declined — too narrow a use case"), close the issue. The card disappears from Planned/In Progress columns but remains accessible by direct permalink with a "Closed" indicator.

You can also remove `public` to take a card off the board entirely — but the submitter's tracker will then show "Awaiting triage" for it, which is misleading. Prefer: keep `public`, close the issue, leave a brief public comment.

## Files map (where the rules actually live)

If you're modifying Calliope RM itself, these are the touch points:

| File | Holds |
| --- | --- |
| `submit.php` / `worker.js` | Submission validation, allowed types, image processing, label assignment on creation |
| `board.php` / `worker.js` (`handleBoard`) | What `/api/board` returns; `transform_issue` controls which labels are stripped from public cards |
| `comments.php` | Comment visibility logic — team-author rule + `<!-- public -->` marker, plus the `<!-- ... -->` and `<!-- CUSTOMER_HIDE -->` strip |
| `status.php` | The HMAC-signed lookup powering "Your submissions" |
| `index.html` | The frontend; `STATUS_COLUMNS`, type pills, allowed types, modal rendering |

When changing label semantics, change all three places (server proxy + frontend + this guide). The label namespace conventions (`public`, `status:*`, `type:*`, `area:*`, `env:*`) are coupled across them.
