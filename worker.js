// Cloudflare Worker: customer feedback portal backend.
// Proxies between your site and GitHub. Token never leaves the server.
//
// Required Worker secrets (`wrangler secret put <NAME>`):
//   GITHUB_TOKEN     - fine-grained PAT, scope: Issues read/write on the repo
//   GITHUB_OWNER     - "your-org"
//   GITHUB_REPO      - "your-repo"
//   ALLOWED_ORIGIN   - "https://yoursite.com"
//
// Label conventions (set these up in your repo first):
//   public                       - required for customer visibility
//   status:planned               - column 1
//   status:in-progress           - column 2
//   status:shipped               - column 3
//   type:bug / type:feature / type:improvement
//   from-customer                - auto-added to submissions

const STATUS_COLUMNS = [
  { key: 'planned',     name: 'Planned' },
  { key: 'in-progress', name: 'In Progress' },
  { key: 'shipped',     name: 'Shipped' },
];

const ALLOWED_TYPES = ['bug', 'feature', 'improvement'];

const ghHeaders = (env) => ({
  'Authorization': `Bearer ${env.GITHUB_TOKEN}`,
  'Accept': 'application/vnd.github+json',
  'User-Agent': 'customer-portal',
});

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const cors = {
      'Access-Control-Allow-Origin':  env.ALLOWED_ORIGIN || '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
    };

    if (request.method === 'OPTIONS') return new Response(null, { headers: cors });

    try {
      if (url.pathname === '/api/submit' && request.method === 'POST') {
        return await handleSubmit(request, env, cors);
      }
      if (url.pathname === '/api/board' && request.method === 'GET') {
        return await handleBoard(request, env, cors);
      }
    } catch (e) {
      console.error('Worker error:', e);
      return jsonRes({ error: 'Server error' }, 500, cors);
    }

    return jsonRes({ error: 'Not found' }, 404, cors);
  },
};

function jsonRes(data, status, cors) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { ...cors, 'Content-Type': 'application/json' },
  });
}

// ─── BOARD ───────────────────────────────────────────────────────────────

async function handleBoard(request, env, cors) {
  // 60-second cache so we don't hammer the GitHub API
  const cache = caches.default;
  const cacheReq = new Request(`https://cache/board/${env.GITHUB_OWNER}/${env.GITHUB_REPO}`);
  const cached = await cache.match(cacheReq);
  if (cached) {
    const data = await cached.json();
    return jsonRes(data, 200, cors);
  }

  const apiUrl =
    `https://api.github.com/repos/${env.GITHUB_OWNER}/${env.GITHUB_REPO}/issues` +
    `?labels=public&state=all&per_page=100&sort=updated&direction=desc`;

  const ghRes = await fetch(apiUrl, { headers: ghHeaders(env) });
  if (!ghRes.ok) {
    console.error('GitHub board fetch error:', ghRes.status, await ghRes.text());
    return jsonRes({ error: 'Could not load board.' }, 502, cors);
  }

  const raw = await ghRes.json();
  const issues = raw
    .filter(i => !i.pull_request) // GitHub's issues endpoint includes PRs; exclude them
    .map(transformIssue);

  const columns = STATUS_COLUMNS.map(col => ({
    key:    col.key,
    name:   col.name,
    issues: issues.filter(i => i.status === col.key),
  }));

  const result = { columns, totalCount: issues.length, fetchedAt: new Date().toISOString() };

  // Cache for 60s
  await cache.put(cacheReq, new Response(JSON.stringify(result), {
    headers: { 'Content-Type': 'application/json', 'Cache-Control': 'max-age=60' },
  }));

  return jsonRes(result, 200, cors);
}

function transformIssue(issue) {
  const labels = issue.labels || [];
  const statusLabel = labels.find(l => l.name.startsWith('status:'));
  const typeLabel   = labels.find(l => l.name.startsWith('type:'));

  // Strip internal labels from what we expose to customers
  const visibleLabels = labels
    .filter(l =>
      !l.name.startsWith('status:') &&
      !l.name.startsWith('type:') &&
      l.name !== 'public' &&
      l.name !== 'from-customer'
    )
    .map(l => ({ name: l.name, color: l.color }));

  return {
    number:    issue.number,
    title:     issue.title,
    body:      issue.body || '',
    status:    statusLabel ? statusLabel.name.slice('status:'.length) : 'planned',
    type:      typeLabel   ? typeLabel.name.slice('type:'.length)     : null,
    labels:    visibleLabels,
    upvotes:   issue.reactions?.['+1'] || 0,
    comments:  issue.comments || 0,
    state:     issue.state, // "open" | "closed"
    createdAt: issue.created_at,
    updatedAt: issue.updated_at,
  };
}

// ─── SUBMIT ──────────────────────────────────────────────────────────────

async function handleSubmit(request, env, cors) {
  let data;
  try { data = await request.json(); }
  catch { return jsonRes({ error: 'Invalid JSON' }, 400, cors); }

  // Honeypot
  if (data.website) return jsonRes({ ok: true }, 200, cors);

  const title       = (data.title || '').trim();
  const description = (data.description || '').trim();
  if (!title || !description) {
    return jsonRes({ error: 'Title and description are required.' }, 400, cors);
  }
  if (title.length > 200 || description.length > 5000) {
    return jsonRes({ error: 'Input too long.' }, 400, cors);
  }

  const name  = (data.name  || 'Anonymous').trim().slice(0, 100);
  const email = (data.email || '').trim().slice(0, 200);
  const type  = ALLOWED_TYPES.includes(data.type) ? data.type : null;

  const footer = email
    ? `\n\n---\n*Submitted via customer portal by **${name}** (${email})*`
    : `\n\n---\n*Submitted via customer portal by **${name}***`;

  // Note: we do NOT auto-add 'public'. Team triages first.
  const labels = ['from-customer'];
  if (type) labels.push(`type:${type}`);

  const ghRes = await fetch(
    `https://api.github.com/repos/${env.GITHUB_OWNER}/${env.GITHUB_REPO}/issues`,
    {
      method: 'POST',
      headers: { ...ghHeaders(env), 'Content-Type': 'application/json' },
      body: JSON.stringify({
        title,
        body: description + footer,
        labels,
      }),
    }
  );

  if (!ghRes.ok) {
    console.error('GitHub submit error:', ghRes.status, await ghRes.text());
    return jsonRes({ error: 'Could not submit. Please try again later.' }, 502, cors);
  }

  const issue = await ghRes.json();
  return jsonRes({ ok: true, number: issue.number }, 200, cors);
}
