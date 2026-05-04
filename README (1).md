# Customer Feedback Portal

A lightweight customer-facing feedback portal that runs entirely on **GitHub Issues + Labels** as its database. Customers submit issues and view a public roadmap (kanban board) without ever needing a GitHub account, while your source code stays in a private repository.

No database. No auth system. No admin panel. Your team works in GitHub like normal; the portal reads and writes through a thin serverless proxy.

## Contents

- [How it works](#how-it-works)
- [Prerequisites](#prerequisites)
- [Setup](#setup)
- [Label reference](#label-reference)
- [Triage workflow](#triage-workflow)
- [Customization](#customization)
- [Security notes](#security-notes)
- [Troubleshooting](#troubleshooting)
- [Future extensions](#future-extensions)

## How it works

```
  ┌──────────────┐         ┌──────────────────┐         ┌──────────────────┐
  │  Customer    │  HTTPS  │  Cloudflare      │   API   │  GitHub Issues   │
  │  (browser)   │ ──────▶ │  Worker          │ ──────▶ │  (private repo)  │
  │  portal.html │ ◀────── │  worker.js       │ ◀────── │                  │
  └──────────────┘         └──────────────────┘         └──────────────────┘
        ▲                          │
        │ token never               │ holds GITHUB_TOKEN
        │ leaves server             │ as encrypted secret
```

**Two endpoints:**

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `/api/submit` | POST | Customer submits a new issue |
| `/api/board` | GET | Returns issues with the `public` label, grouped into kanban columns |

**Five label namespaces** do the work:

- `public` — gates visibility. Without this label, an issue is invisible to customers.
- `status:*` — defines kanban columns (`status:planned`, `status:in-progress`, `status:shipped`).
- `type:*` — categorizes issues (`type:bug`, `type:feature`, `type:improvement`).
- `severity:*` — captures urgency (`severity:low`, `severity:medium`, `severity:high`, `severity:critical`).
- `area:*` / `env:*` — captures affected area and environment for routing and automation.

## Prerequisites

- A GitHub repository (public or private) where issues will live
- A [Cloudflare account](https://dash.cloudflare.com/sign-up) (free tier is sufficient: 100k Worker requests/day)
- [Node.js](https://nodejs.org) and `wrangler` CLI installed locally
- A static hosting destination for `portal.html` (your existing site, GitHub Pages, Netlify, etc.)

## Setup

### 1. Create the GitHub labels

In your repo, go to **Issues → Labels → New label** and create:

| Label | Suggested color | Purpose |
| --- | --- | --- |
| `public` | `#0E8A16` (green) | Gates customer visibility |
| `status:planned` | `#C5DEF5` (light blue) | Column 1 |
| `status:in-progress` | `#FBCA04` (yellow) | Column 2 |
| `status:shipped` | `#0E8A16` (green) | Column 3 |
| `type:bug` | `#D73A4A` (red) | Bug reports |
| `type:feature` | `#0969DA` (blue) | Feature requests |
| `type:improvement` | `#1F883D` (green) | Improvements |
| `severity:low` | `#BFDADC` (soft teal) | Low urgency |
| `severity:medium` | `#FBCA04` (yellow) | Medium urgency |
| `severity:high` | `#D93F0B` (orange) | High urgency |
| `severity:critical` | `#B60205` (red) | Critical urgency |
| `area:auth` | `#5319E7` (purple) | Authentication |
| `area:billing` | `#1D76DB` (blue) | Billing |
| `area:dashboard` | `#0E8A16` (green) | Dashboard |
| `area:integrations` | `#006B75` (teal) | Integrations |
| `area:notifications` | `#C2E0C6` (mint) | Notifications |
| `area:api` | `#D4C5F9` (lavender) | API |
| `area:export` | `#F9D0C4` (peach) | Export / reporting |
| `area:mobile` | `#F7C6C7` (pink) | Mobile |
| `area:performance` | `#F9D0C4` (peach) | Performance |
| `area:other` | `#EDEDED` (gray) | Other |
| `env:production` | `#B60205` (red) | Production |
| `env:staging` | `#FBCA04` (yellow) | Staging |
| `env:sandbox` | `#0E8A16` (green) | Sandbox / test |
| `env:local` | `#5319E7` (purple) | Local / development |
| `env:unknown` | `#EDEDED` (gray) | Unknown |
| `from-customer` | `#FBCA04` (yellow) | Auto-applied to submissions |

### 2. Create a GitHub token

1. Go to **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**
2. **Repository access:** select the specific repo (don't use "All repositories")
3. **Repository permissions:** set `Issues` to **Read and write**
4. Set an expiration (recommend 90 days, with a calendar reminder to rotate)
5. Generate and **copy the token immediately** — you won't see it again

> **Note:** Classic PATs work too, but fine-grained tokens are scoped to a single repo and are strongly preferred.

### 3. Deploy the Cloudflare Worker

```bash
# Install wrangler if you haven't
npm install -g wrangler

# Authenticate
wrangler login

# Initialize a new Worker project
wrangler init customer-portal-worker
cd customer-portal-worker

# Replace the generated src/index.js with worker.js (from this repo)
cp /path/to/worker.js src/index.js

# Set your secrets (you'll be prompted for each value)
wrangler secret put GITHUB_TOKEN
wrangler secret put GITHUB_OWNER     # e.g. "acme-corp"
wrangler secret put GITHUB_REPO      # e.g. "feedback"
wrangler secret put ALLOWED_ORIGIN   # e.g. "https://acme.com"

# Deploy
wrangler deploy
```

Wrangler will print a URL like `https://customer-portal-worker.your-subdomain.workers.dev`. Save it — you need it in step 4.

### 4. Configure the frontend

Open `portal.html` and replace the placeholder near the top of the `<script>` block:

```js
const ENDPOINT = 'https://YOUR-WORKER.workers.dev'; // <-- replace
```

with your actual Worker URL. Upload `portal.html` to your site (or embed its contents into an existing page).

### 5. Test it

1. Open `portal.html` in your browser. The roadmap tab should show empty columns.
2. Submit a test feedback item. It should land in the GitHub repo as a new issue with `from-customer`, `type:*`, `severity:*`, `area:*`, and `env:*` labels plus a structured Markdown intake body.
3. In GitHub, add `public` and `status:planned` to the issue. Wait up to 60 seconds (the cache TTL), refresh, and the card should appear.

## Label reference

### Required for visibility

| Label | Effect |
| --- | --- |
| `public` | Issue appears on the board. **No `public` label = invisible.** |

### Status (kanban columns)

| Label | Column |
| --- | --- |
| `status:planned` | Planned |
| `status:in-progress` | In Progress |
| `status:shipped` | Shipped |
| _(none)_ | Defaults to Planned |

### Type (filter + color coding)

| Label | UI treatment |
| --- | --- |
| `type:bug` | Red pill |
| `type:feature` | Blue pill |
| `type:improvement` | Green pill |

### Hidden from customers

These labels are stripped from card display:

- `public`
- `from-customer`
- Any `status:*` label (used internally for column placement)
- Any `type:*` label (rendered as a colored pill instead)

Other labels (e.g., `priority:high`, `area:billing`) **will be shown** as small pills on cards. If you don't want a label visible to customers, either don't apply it to public issues or extend the filter list in `worker.js` (`transformIssue` function).

## Triage workflow

When a customer submits feedback:

1. A new issue appears in your repo with:
   - Labels: `from-customer`, `type:*`, `severity:*`, `area:*`, `env:*`
   - A structured intake body with requester details, business context, type-specific evidence, technical context, and hidden machine-readable metadata comments

2. **Triage:**
   - **Spam or duplicate?** Close the issue. It never gets `public`, never appears on the board.
   - **Legitimate?** Add:
     - `public`
     - A `status:*` label
     - Any internal labels (`priority:*`, etc.)

3. **Working on it?** Swap `status:planned` for `status:in-progress`. Card moves columns within 60 seconds.

4. **Shipped?** Swap to `status:shipped` and close the issue.

> **Tip:** Set up a saved filter in GitHub for `is:open label:from-customer -label:public` to see your triage queue.

## Customization

### Add more columns

In `worker.js`, edit the `STATUS_COLUMNS` array:

```js
const STATUS_COLUMNS = [
  { key: 'backlog',     name: 'Backlog' },
  { key: 'planned',     name: 'Planned' },
  { key: 'in-progress', name: 'In Progress' },
  { key: 'shipped',     name: 'Shipped' },
];
```

Then create matching `status:backlog` etc. labels in GitHub. The frontend's `.kanban` grid in `portal.html` adapts automatically, but you may want to adjust `grid-template-columns` for the new count.

### Add more types

In `worker.js`, edit `ALLOWED_TYPES`:

```js
const ALLOWED_TYPES = ['bug', 'feature', 'improvement', 'question'];
```

In `portal.html`, add matching `<option>` to the type `<select>` in the form, the filter dropdown, and a CSS rule for the new pill color (look for `.pill.bug`, `.pill.feature`, etc.).

### Change cache duration

In `worker.js`, find the `Cache-Control` header in `handleBoard`:

```js
'Cache-Control': 'max-age=60'
```

Increase to reduce GitHub API calls; decrease for fresher data. 60 seconds is a reasonable default.

### Restrict which origins can call the Worker

Already enforced via `ALLOWED_ORIGIN`. Make sure it matches your site's origin exactly (including `https://`, no trailing slash). Browsers will block CORS-mismatched requests.

## Security notes

- **The GitHub token must never appear in client code.** It's stored as a Worker secret, encrypted at rest, and only read at runtime. Don't log it, don't echo it in error responses, don't commit it.
- **Use fine-grained PATs scoped to a single repo.** If a token leaks, blast radius is limited to one repo's issues.
- **Rotate tokens regularly.** Set a 90-day expiration and a calendar reminder.
- **Rate-limit if abuse appears.** The honeypot field catches lazy bots. For real spam, add [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) (free) — one extra script tag in the form and a verify call in the Worker.
- **Sanitize what's exposed.** The Worker's `transformIssue` function controls exactly what fields are returned to customers. Internal labels, comment threads, and GitHub URLs are not exposed by default.
- **Watch for content in submissions.** Customer-submitted content is rendered as text in the GitHub UI and (via the modal) in the portal with HTML escaping plus minimal markdown. If you extend the renderer, sanitize carefully.

## Troubleshooting

**Cards don't appear after I add `public`.**
The board response is cached for 60 seconds. Wait, then hard-refresh.

**Form submission returns "Could not submit."**
Check the Worker logs (`wrangler tail`). Most often: token expired, token lacks `Issues: Write` permission, or `GITHUB_OWNER` / `GITHUB_REPO` are misspelled.

**`Access-Control-Allow-Origin` errors in the browser console.**
`ALLOWED_ORIGIN` must match your site's origin exactly. `https://acme.com` and `https://www.acme.com` are different origins.

**Submitted issues appear immediately on the board.**
Check that you didn't accidentally include `public` in the `labels` array in `handleSubmit`. By default, the Worker only applies `from-customer` and the chosen type — `public` should be added manually during triage.

**The `+1` upvote count never updates.**
Reactions are pulled from the GitHub API on every (cache-missed) board fetch. Wait up to 60 seconds for the cache to expire.

**Pull requests show up on the board.**
GitHub's `/issues` endpoint returns PRs too; the Worker filters them with `if (!i.pull_request)`. If you see PRs, check that filter is intact.

## Future extensions

The architecture leaves room to grow without adding a database:

- **Permalinks:** `?issue=42` in the URL opens that card's modal directly. Useful for sharing.
- **Closed-as-shipped styling:** fade or strike through closed cards in the Shipped column.
- **Public comments:** surface comments from team members (or those marked with a `public-comment` marker) in the modal.
- **"Me too" upvoting:** add an endpoint that posts a `+1` reaction via the Worker. Tracking who voted requires storage; tracking just totals does not.
- **Changelog feed:** generate an RSS or JSON feed of recently-shipped issues for customers to subscribe to.
- **GitHub Projects v2 integration:** if you'd rather use a Project board as the source of truth instead of `status:*` labels, swap `handleBoard` to use the GraphQL API and read from project field values. More code, but native to Projects.

---

**Stack:** GitHub Issues + Labels (data) · Cloudflare Workers (proxy) · Vanilla HTML/CSS/JS (frontend)
**Files:** `portal.html`, `worker.js`
