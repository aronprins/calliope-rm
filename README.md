# Calliope RM

> Named after **Calliope** — the Greek Muse of eloquence and epic poetry, eldest of the nine Muses. She was the voice that made stories worth following from beginning to end. Calliope RM does the same for your product roadmap.

Calliope RM is a customer-facing roadmap and feedback portal that runs entirely on **GitHub Issues + Labels**: customers submit issues, see what's planned, in progress, and shipped, and your team works in GitHub like normal.

No database. No auth system. No admin panel. The portal reads and writes through a thin proxy — drop it in front of any repo and you have a public roadmap in an afternoon.

<img width="1162" height="1162" alt="Screenshot 2026-05-04 at 10 55 53" src="https://github.com/user-attachments/assets/d70a04d0-7b8f-41c5-9970-c3d1bf5c104e" />

## Contents

- [How it works](#how-it-works)
- [Deployment options](#deployment-options)
- [The submission form](#the-submission-form)
- [Image uploads](#image-uploads)
- [Submitter PII handling](#submitter-pii-handling)
- [Setup — Cloudflare Worker](#setup--cloudflare-worker)
- [Setup — VPS with nginx + PHP-FPM](#setup--vps-with-nginx--php-fpm)
- [Label reference](#label-reference)
- [Triage workflow](#triage-workflow)
- [Customization](#customization)
- [Security notes](#security-notes)
- [Troubleshooting](#troubleshooting)
- [License](#license)

## How it works

```
  ┌──────────────┐         ┌──────────────────┐         ┌──────────────────┐
  │  Customer    │  HTTPS  │  Backend proxy   │   API   │  GitHub Issues   │
  │  (browser)   │ ──────▶ │  (Worker or PHP) │ ──────▶ │  (private repo)  │
  │  index.html  │ ◀────── │                  │ ◀────── │                  │
  └──────────────┘         └──────────────────┘         └──────────────────┘
        ▲                          │
        │ credentials never         │ holds GitHub App key
        │ leave server              │ or PAT as env / secret
```

**Two endpoints:**

| Endpoint | Method | Purpose |
| --- | --- | --- |
| `/api/submit` | POST | Customer submits a new issue (multipart, supports image uploads) |
| `/api/board` | GET | Returns issues with the `public` label, grouped into kanban columns |
| `/api/comments?issue=N` | GET | Returns customer-visible comments on issue N (team comments + any comment with the `<!-- public -->` marker) |
| `/api/status?numbers=N,N` | GET | Returns `{ number, state, reason }` for up to 20 issue numbers — used by the "Your submissions" tracker so customers can see when an issue was declined or closed without ever being made public |

**Four label namespaces** do the work:

- `public` — gates visibility. Without this label, an issue is invisible to customers.
- `status:*` — defines kanban columns (`status:planned`, `status:in-progress`, `status:shipped`).
- `type:*` — categorizes issues (`type:bug`, `type:feature`, `type:improvement`).
- `area:*` / `env:*` — capture affected area and environment for routing.

## Deployment options

Two backends are provided. Pick one based on where you'd rather run it.

| | **Cloudflare Worker** (`worker.js`) | **VPS, nginx + PHP-FPM** (`submit.php` + `board.php`) |
| --- | --- | --- |
| Hosting | Cloudflare's edge | Your VPS |
| Image uploads | Not supported | Supported (WebP, on-disk) |
| Cost | Free tier covers 100k req/day | VPS only |
| Setup | `wrangler` CLI + secrets | Drop in two PHP files + env vars |
| Storage | Stateless | Uploaded images live on the VPS |
| Caching | Cloudflare cache API (60s) | File cache in temp dir (60s) |

The PHP path is the one to choose if you want screenshots embedded in your issues.

## The submission form

`index.html` ships with a 3-step submission flow optimized for response rate:

1. **The basics** — type (bug / feature / improvement), one-sentence summary, freeform description, **submitter name and email**, and optional screenshots (drag-and-drop, click, or paste from clipboard). Required: type, summary, description, name, email.
2. **Add detail** *(optional)* — type-specific fields (repro steps and "is this blocking you?" for bugs; use case and success criteria for features/improvements). Routing context (area, environment, links) and "About you" (company, role) live behind disclosure blocks.
3. **Review & send** — a summary of everything captured before submitting.

After submission, the customer sees a clear "Thanks — got it." success state with a copyable permalink (`#issue=N` URL). Once the team triages and adds the `public` label, that exact link opens the card on the roadmap. Pre-triage, it's a dead anchor that just loads the page.

There is no severity field. Triage decides priority — letting users self-assign severity is an anti-pattern (everyone picks "High").

### "Your submissions" tracker

The Submit tab also renders a small section listing every issue this browser has submitted, stored in `localStorage` only:

- Items already on the public board show their current column (Planned / In Progress / Shipped, with a ✓ for closed) and click through to open the modal.
- Items not yet visible show "Awaiting triage" in amber.

This is **per-device** (clears with browser data, doesn't sync across devices) and entirely client-side — no auth, no server tracking. The localStorage key is `calliope-submissions` and stores at most 12 entries: `[{ number, title, submittedAt }]`. Customers can clear it with the inline "Clear" button.

**Visual:** the portal uses the Clio KB design system (Geist font, zinc-tone palette, soft borders) and ships with a light/dark mode toggle in the header. Theme persists in `localStorage` and follows `prefers-color-scheme` on first visit.

## Submitter PII handling

Name and email are required so triage always has a way to follow up — but the data should never appear on the public roadmap. The flow:

- `submit.php` writes a `## Submitter` section (name, email, company, role) and a `## Submission metadata` section (page URL, user agent, language, screen size, time zone) into the GitHub issue body, wrapped in `<!-- CUSTOMER_HIDE_START --> ... <!-- CUSTOMER_HIDE_END -->` markers.
- **Team view (GitHub):** GitHub's renderer hides the HTML comments themselves but still renders the markdown sections between them. You see the full submitter info in the issue UI, in `gh issue view`, and via the API.
- **Customer view (`/api/board` and `/api/comments`):** `board.php` and `comments.php` strip everything between the markers (and any other HTML comments) **server-side** before returning the JSON. The `body` field on the wire never contains PII — anyone inspecting the network response in browser devtools sees only the public sections.
- **Defense in depth:** the modal's `renderBody()` also strips the marker block before inserting into the DOM, so even if a body containing markers somehow reached the client (e.g. via a future endpoint that didn't apply the strip), the rendered output would still be clean.

To migrate older issues that pre-date this scheme, wrap the submission metadata section in markers via `gh issue edit`:

```bash
gh issue view N --json body --jq .body > body.md
# insert <!-- CUSTOMER_HIDE_START --> before "## Submission metadata"
# append <!-- CUSTOMER_HIDE_END --> at end
gh issue edit N --body-file body.md
```

## Image uploads

Available with the PHP backend.

**On the client:**
- Drag and drop, click to browse, or paste from clipboard
- Up to 4 images, 10MB each
- Allowed: PNG, JPEG, GIF, WebP
- Thumbnail previews with per-image remove buttons

**On the server (`submit.php`):**
- Validates mime via magic bytes (not the `Content-Type` header)
- Decodes with GD, scales down to 2000px on the longest edge
- Re-encodes to **WebP at quality 82** — typically 5–10× smaller than the source PNG, EXIF stripped as a side effect
- Saves to `<UPLOADS_DIR>/YYYY/MM/DD/<random-token>.webp`
- Embeds image URLs in the GitHub issue body under a `## Screenshots` section
- If issue creation fails, freshly written files are deleted so orphans don't accumulate

**Storage trade-off:** images live on your VPS permanently. GitHub renders them via its [camo](https://github.com/atmos/camo) image proxy, which caches but eventually re-fetches from origin — so if you delete a file, the image will eventually break in the issue. Plan backups for `UPLOADS_DIR` accordingly.

## Setup — Cloudflare Worker

Use this path if you don't need image uploads.

### 1. Create the GitHub labels (see [reference](#label-reference))

### 2. Create GitHub credentials

Recommended: use a GitHub App instead of a PAT. The app mints short-lived installation tokens automatically, so there is no long-lived user token to rotate.

1. **Settings → Developer settings → GitHub Apps → New GitHub App**
2. Repository permissions: `Issues` → **Read and write**
3. Subscribe to no events — Calliope only calls the REST API
4. Create the app, then **Generate a private key**
5. Install the app on the specific roadmap repo
6. Note the app ID and installation ID

PAT fallback is still supported:

1. **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**
2. Repository access: select the specific repo
3. Repository permissions: `Issues` → **Read and write**
4. Set a 90-day expiration with a calendar reminder to rotate
5. Copy the token — you won't see it again

### 3. Deploy the Worker

```bash
npm install -g wrangler
wrangler login
wrangler init customer-portal-worker
cd customer-portal-worker
cp /path/to/worker.js src/index.js

wrangler secret put GITHUB_OWNER     # e.g. "acme-corp"
wrangler secret put GITHUB_REPO      # e.g. "feedback"
wrangler secret put ALLOWED_ORIGIN   # e.g. "https://acme.com"

# Recommended: GitHub App auth
wrangler secret put GITHUB_APP_ID
wrangler secret put GITHUB_APP_INSTALLATION_ID
wrangler secret put GITHUB_APP_PRIVATE_KEY

# Legacy fallback: PAT auth
# wrangler secret put GITHUB_TOKEN

wrangler deploy
```

### 4. Configure the frontend

In `index.html`:

```js
const ENDPOINT = 'https://YOUR-WORKER.workers.dev'; // <-- replace
```

Upload `index.html` to your site.

> **Note:** The Worker doesn't currently handle image uploads. Submitting a form with attachments will succeed but the images will be ignored. Use the PHP backend if you need screenshots in your issues.

## Setup — VPS with nginx + PHP-FPM

Use this path if you want image uploads or already host on a VPS.

### 1. Create the GitHub labels (see [reference](#label-reference))

### 2. Create GitHub credentials (same as Worker setup, step 2)

### 3. Install dependencies on the VPS

```bash
sudo apt update
sudo apt install nginx php-fpm php-gd php-curl
```

`php-gd` provides `imagewebp()`. `php-curl` is used to talk to the GitHub API.

### 4. Drop in `submit.php` and create the uploads directory

```bash
sudo mkdir -p /var/www/calliope
sudo cp submit.php board.php comments.php status.php github_auth.php /var/www/calliope/
sudo mkdir -p /var/lib/calliope/uploads
sudo chown -R www-data:www-data /var/lib/calliope/uploads
```

### 5. Configure environment variables

Edit your php-fpm pool config (e.g. `/etc/php/8.2/fpm/pool.d/www.conf`) and add:

```ini
env[GITHUB_OWNER]                = acme-corp
env[GITHUB_REPO]                 = feedback
env[GITHUB_APP_ID]               = 123456
env[GITHUB_APP_INSTALLATION_ID]  = 98765432
env[GITHUB_APP_PRIVATE_KEY_B64]  = base64-encoded-private-key-pem
env[ALLOWED_ORIGIN]              = https://acme.com
env[UPLOADS_DIR]                 = /var/lib/calliope/uploads
env[UPLOADS_URL]                 = https://acme.com/uploads
env[STATUS_SIGN_KEY]             = a-long-random-secret-string-rotated-occasionally
```

To create `GITHUB_APP_PRIVATE_KEY_B64` from the downloaded `.pem` file:

```bash
base64 -w0 calliope-rm.private-key.pem
```

On macOS, use `base64 -i calliope-rm.private-key.pem | tr -d '\n'`.

If you keep using PAT auth instead, set `env[GITHUB_TOKEN] = github_pat_...` and omit the three `GITHUB_APP_*` vars.

When any `GITHUB_APP_*` variable is set, Calliope treats GitHub App auth as intentional and will not silently fall back to `GITHUB_TOKEN`. This keeps a broken app setup from accidentally continuing to use an older user PAT.

`STATUS_SIGN_KEY` is required for `/api/status` and the per-submission HMAC token returned by `/api/submit`. Generate with `openssl rand -hex 32` or similar. **Don't** commit it. Rotating it invalidates all currently-saved submission tokens, which downgrades older entries in customers' "Your submissions" lists to silent "Awaiting triage" (the row still shows, just no live status lookup) — non-destructive but noisy, so rotate sparingly.

Reload php-fpm: `sudo systemctl reload php8.2-fpm`.

### 6. Configure nginx

```nginx
server {
  listen 443 ssl http2;
  server_name acme.com;

  client_max_body_size 50m;

  # Frontend
  location / {
    root /var/www/calliope;
    try_files $uri $uri/ =404;
  }

  # Submission endpoint
  location = /api/submit {
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/calliope/submit.php;
    include fastcgi_params;
  }

  # Board endpoint
  location = /api/board {
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/calliope/board.php;
    include fastcgi_params;
  }

  # Comments endpoint
  location = /api/comments {
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/calliope/comments.php;
    include fastcgi_params;
  }

  # Status endpoint
  location = /api/status {
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/calliope/status.php;
    include fastcgi_params;
  }

  # Public uploads
  location /uploads/ {
    alias /var/lib/calliope/uploads/;
    expires 1y;
    add_header Cache-Control "public, immutable";
    add_header X-Content-Type-Options nosniff;
  }
}
```

Reload: `sudo nginx -t && sudo systemctl reload nginx`.

### 7. Configure the frontend

In `index.html`:

```js
const ENDPOINT = 'https://acme.com'; // your domain — submit.php is at /api/submit
```

### 8. Test

1. Open `index.html` in your browser.
2. Submit a test issue with a screenshot. It should appear in your GitHub repo with `from-customer` and `type:*` labels, a structured Markdown body, and the screenshot embedded under a `## Screenshots` heading.
3. Add `public` and `status:planned` to the issue. After the cache expires, the card appears on the board.

## Label reference

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
- Any `status:*` label
- Any `type:*` label (rendered as a colored pill instead)

Other labels (e.g., `priority:high`, `area:billing`) **will be shown** as small pills on cards. To hide additional labels, extend the filter list in `worker.js` (`transformIssue` function).

## Public comments

The card modal fetches `/api/comments?issue=N` when opened and renders comments below the body. By default, customers see:

- **All comments from team members** — anyone whose `author_association` on GitHub is `OWNER`, `MEMBER`, or `COLLABORATOR`.
- **Any comment containing the marker `<!-- public -->`** — useful for surfacing a customer's own follow-up reply, or for a team member commenting from an account without write access.

The `<!-- public -->` marker is stripped from the rendered output, so you can drop it anywhere in the comment body.

Responses are cached per issue for 60 seconds (configurable via `COMMENTS_CACHE_TTL`).

### Internal triage notes inside customer-visible comments

Team comments are automatically customer-visible, but you'll often want to mix a public update with a private aside ("here's the ETA, and internally we should also check X"). Two ways to keep the private part hidden:

**Inline notes — wrap them in `<!-- ... -->` HTML comments.** Anything inside the comment is stripped server-side by `comments.php` before the API ever returns the body, and GitHub's web UI hides the comment from rendered view but still shows it in the comment editor / raw view / `gh issue view`.

```markdown
Reproduced on staging. Will ship the fix in v1.42.

<!-- triage: revert the URL state refactor in #87 if this regresses again -->
```

The customer sees only "Reproduced on staging. Will ship the fix in v1.42." The team sees the triage note when editing the comment or via the API.

**Whole-block notes — wrap a multi-section aside in the same `<!-- CUSTOMER_HIDE_START --> ... <!-- CUSTOMER_HIDE_END -->` markers used in issue bodies.** Useful when the internal aside has its own structure (multiple paragraphs, lists, headings):

```markdown
Shipped in v1.42 — see the [docs](https://example.com/docs/slack) for setup.

<!-- CUSTOMER_HIDE_START -->
## Internal follow-up
- Confirm with legal whether two-way sync needs a DPA addendum
- Schedule a metrics check after 7 days of usage
<!-- CUSTOMER_HIDE_END -->
```

Both forms are stripped server-side, so they never appear in the network response — not just hidden in the rendered DOM.

### Quick reference

| You want… | Put this in the comment |
| --- | --- |
| Public update from a team account | Just write it. Comments from `OWNER`, `MEMBER`, or `COLLABORATOR` are visible by default. |
| Public update from a non-team account (or a customer reply you want to surface) | Include `<!-- public -->` anywhere in the body. |
| A short internal note alongside a public one | `<!-- internal: don't escalate, customer was on a stale build -->` |
| A whole internal section (multi-line, headings, lists) | Wrap it in `<!-- CUSTOMER_HIDE_START --> ... <!-- CUSTOMER_HIDE_END -->` |
| A fully internal comment (no public part) | Either wrap the whole thing in `<!-- ... -->`, or just write it without any `<!-- public -->` marker from a non-team account — non-team comments default to hidden. |

To verify a comment was filtered correctly, hit the endpoint directly and check the JSON:

```bash
curl -s https://yourdomain.com/api/comments?issue=42 | jq '.comments[] | .body'
```

If you can see the internal text in that output, the strip didn't work — review the markers and check that the cache is busted (delete `calliope-comments-*.json` from `BOARD_CACHE_DIR` / `sys_get_temp_dir()`).

## Triage workflow

When a customer submits feedback:

1. A new issue appears in your repo with:
   - Labels: `from-customer`, `type:*`, plus `area:*` and `env:*` if the user filled them in
   - A structured Markdown body with description, type-specific fields, screenshots (if uploaded), and submission metadata

2. **Triage:**
   - **Spam or duplicate?** Close the issue.
   - **Legitimate?** Add `public`, a `status:*` label, and any internal labels (`priority:*`, etc.).

3. **Working on it?** Swap `status:planned` for `status:in-progress`. Card moves columns within 60 seconds.

4. **Shipped?** Swap to `status:shipped` and close the issue.

> **Tip:** Save a GitHub filter for `is:open label:from-customer -label:public` — that's your triage queue.

## Customization

### Add more columns

In `worker.js`, edit `STATUS_COLUMNS`:

```js
const STATUS_COLUMNS = [
  { key: 'backlog',     name: 'Backlog' },
  { key: 'planned',     name: 'Planned' },
  { key: 'in-progress', name: 'In Progress' },
  { key: 'shipped',     name: 'Shipped' },
];
```

Create matching `status:backlog` labels in GitHub. The frontend's `.kanban` grid adapts automatically; consider adjusting `grid-template-columns` for the new count.

### Add more types

In `worker.js`, edit `ALLOWED_TYPES`. In `submit.php`, edit the `ALLOWED_TYPES` constant. In `index.html`, add a matching type card and a `.pill.<newtype>` CSS rule.

### Change cache duration

In `worker.js`, find `'Cache-Control': 'max-age=60'` in `handleBoard`.

### Restrict origins

`ALLOWED_ORIGIN` enforces this. It must match your site's origin exactly — `https://acme.com` and `https://www.acme.com` are different origins.

### Tune image quality / size limits

In `submit.php`, edit the constants at the top:

- `MAX_FILES` — how many images per submission (default 4)
- `MAX_FILE_SIZE` — per-file cap in bytes (default 10MB)
- `MAX_DIMENSION` — longest edge in pixels (default 2000)
- `WEBP_QUALITY` — 0–100 (default 82, sweet spot for screenshots)

The matching client-side caps live near the top of the `<script>` in `index.html` (`MAX_FILES`, `MAX_SIZE`, `ALLOWED_MIMES`). Keep them in sync.

## Security notes

- **GitHub credentials must never appear in client code.** Store the GitHub App private key or fallback PAT as a Worker secret or php-fpm env var. Don't log it, don't echo it in error responses, don't commit it.
- **Prefer GitHub App auth.** Install the app on only the roadmap repo and grant only `Issues: Read and write`. The app private key stays server-side and is used to mint short-lived installation tokens.
- **If you use a PAT, scope it to a single repo and rotate it regularly.** Some org policies can still block or expire no-expiration PATs.
- **Image uploads are re-encoded server-side.** GD decoding + WebP re-encoding strips EXIF metadata and neutralizes any payload embedded in the original file. Don't skip this step if you accept user uploads.
- **Magic-byte mime validation.** `submit.php` uses `finfo` to check actual file content, not the client-supplied `Content-Type`. Clients lie.
- **Random filenames.** Uploaded images are stored under `<random-token>.webp` so URLs aren't enumerable.
- **Honeypot.** Both backends silently accept (and discard) submissions where the hidden `website` field is filled — catches lazy bots.
- **Rate-limiting.** For real spam, add [Cloudflare Turnstile](https://www.cloudflare.com/products/turnstile/) (free) — one script tag in the form and a verify call in the backend. nginx's `limit_req_zone` is also a fine first line of defense for the PHP path.
- **Sanitize what's exposed.** The Worker's `transformIssue` and `board.php`'s `transform_issue` control what the board endpoint returns. Internal labels and comment threads are not exposed by default.
- **PII never leaves the server in the read APIs.** `board.php` and `comments.php` strip the `<!-- CUSTOMER_HIDE_START --> ... <!-- CUSTOMER_HIDE_END -->` block from each issue/comment body before returning it. Verify with browser devtools → Network → check the raw `/api/board` response after submitting a test ticket.

## Troubleshooting

**Cards don't appear after I add `public`.**
The board response is cached for 60 seconds. Wait, then hard-refresh.

**Form submission returns "Could not submit."**
- *Worker:* `wrangler tail` for logs. Usually missing GitHub App env vars, an invalid private key, missing `Issues: Write` permission, or wrong owner/repo.
- *PHP:* check `/var/log/nginx/error.log` and your php-fpm error log. Usually the same root causes, plus `UPLOADS_DIR` permissions.

**"Server upload path not configured."**
PHP backend: `UPLOADS_DIR` or `UPLOADS_URL` env var is missing. Verify your php-fpm pool config and that you reloaded php-fpm.

**Images upload but don't appear in the issue.**
Check that the `UPLOADS_URL` you set is publicly reachable. Open one of the URLs in an incognito window — if you can't see the image, GitHub can't either.

**`Access-Control-Allow-Origin` errors in the browser console.**
`ALLOWED_ORIGIN` must match your site's origin exactly (including `https://`, no trailing slash).

**Submitted issues appear immediately on the board.**
Check that you didn't include `public` in the labels array on the backend. Submissions get `from-customer` + `type:*` only — `public` is added during triage.

**Pull requests show up on the board.**
GitHub's `/issues` endpoint returns PRs too; the Worker filters them with `if (!i.pull_request)`. Make sure that filter is intact.

**`imagewebp(): WebP support not enabled` in PHP error log.**
`php-gd` was installed without WebP support. On Debian/Ubuntu, `apt install php-gd` includes it by default; on minimal images you may need to recompile or use `php-imagick` as an alternative.

---

**Stack:** GitHub Issues + Labels (data) · Cloudflare Workers *or* nginx + PHP-FPM (proxy) · Vanilla HTML/CSS/JS (frontend)
**Files:** `index.html`, `worker.js`, `submit.php`, `board.php`, `comments.php`

## License

MIT — see [LICENSE](LICENSE).

*Calliope was the eldest daughter of Zeus and Mnemosyne — goddess of memory — and the patron of epic narrative. Every roadmap is a story your customers want to follow from beginning to end.*
