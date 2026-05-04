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
const ALLOWED_AREAS = ['auth', 'billing', 'dashboard', 'integrations', 'notifications', 'api', 'export', 'mobile', 'performance', 'other'];
const ALLOWED_ENVIRONMENTS = ['production', 'staging', 'sandbox', 'local', 'unknown'];
const ALLOWED_SEVERITIES = ['low', 'medium', 'high', 'critical'];
const ALLOWED_FREQUENCIES = ['always', 'often', 'sometimes', 'once'];
const ALLOWED_CONTACT_CONSENT = ['yes', 'no'];

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

  const ticket = parseSubmission(data);
  const validationError = validateSubmission(ticket);
  if (validationError) {
    return jsonRes({ error: validationError }, 400, cors);
  }

  // Note: we do NOT auto-add 'public'. Team triages first.
  const labels = [
    'from-customer',
    `type:${ticket.type}`,
    `severity:${ticket.severity}`,
    `area:${ticket.area}`,
  ];
  if (ticket.environment) labels.push(`env:${ticket.environment}`);

  const issueBody = buildIssueBody(ticket);

  const ghRes = await fetch(
    `https://api.github.com/repos/${env.GITHUB_OWNER}/${env.GITHUB_REPO}/issues`,
    {
      method: 'POST',
      headers: { ...ghHeaders(env), 'Content-Type': 'application/json' },
      body: JSON.stringify({
        title: ticket.title,
        body: issueBody,
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

function parseSubmission(data) {
  return {
    name: text(data.name, 100),
    email: text(data.email, 200),
    company: text(data.company, 120),
    role: text(data.role, 120),
    contactConsent: pick(data.contactConsent, ALLOWED_CONTACT_CONSENT),
    type: pick(data.type, ALLOWED_TYPES),
    area: pick(data.area, ALLOWED_AREAS),
    severity: pick(data.severity, ALLOWED_SEVERITIES),
    environment: pick(data.environment, ALLOWED_ENVIRONMENTS),
    title: text(data.title, 200),
    businessContext: text(data.businessContext, 1500),
    actualResult: text(data.actualResult, 2000),
    expectedResult: text(data.expectedResult, 1500),
    reproductionSteps: text(data.reproductionSteps, 2500),
    frequency: pick(data.frequency, ALLOWED_FREQUENCIES),
    startedAt: text(data.startedAt, 120),
    workaround: text(data.workaround, 1500),
    currentWorkflow: text(data.currentWorkflow, 2000),
    featureUsers: text(data.featureUsers, 1500),
    featureUseCase: text(data.featureUseCase, 2000),
    requestedOutcome: text(data.requestedOutcome, 2000),
    improvementChange: text(data.improvementChange, 2000),
    successCriteria: text(data.successCriteria, 2000),
    alternatives: text(data.alternatives, 1500),
    references: text(data.references, 1500),
    additionalNotes: text(data.additionalNotes, 1500),
    pageUrl: text(data.pageUrl, 500),
    userAgent: text(data.userAgent, 500),
    language: text(data.language, 80),
    screenSize: text(data.screenSize, 80),
    timeZone: text(data.timeZone, 80),
    submittedAt: text(data.submittedAt, 80) || new Date().toISOString(),
  };
}

function validateSubmission(ticket) {
  if (!ticket.name) return 'Name is required.';
  if (!ticket.contactConsent) return 'Contact preference is required.';
  if (ticket.contactConsent === 'yes' && !ticket.email) return 'Email is required if follow-up is allowed.';
  if (ticket.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(ticket.email)) return 'Please provide a valid email address.';
  if (!ticket.type) return 'Type is required.';
  if (!ticket.area) return 'Product area is required.';
  if (!ticket.severity) return 'Severity is required.';
  if (!ticket.title) return 'Short summary is required.';
  if (!ticket.businessContext) return 'Business context is required.';

  if (ticket.type === 'bug') {
    if (!ticket.environment) return 'Environment is required for bug reports.';
    if (!ticket.actualResult) return 'What happened is required for bug reports.';
    if (!ticket.expectedResult) return 'Expected result is required for bug reports.';
    if (!ticket.reproductionSteps) return 'Steps to reproduce are required for bug reports.';
    if (!ticket.frequency) return 'Frequency is required for bug reports.';
  }

  if (ticket.type === 'feature') {
    if (!ticket.featureUsers) return 'Who needs this feature is required.';
    if (!ticket.featureUseCase) return 'The use case is required.';
    if (!ticket.requestedOutcome) return 'Requested outcome is required.';
    if (!ticket.successCriteria) return 'Success criteria are required.';
  }

  if (ticket.type === 'improvement') {
    if (!ticket.currentWorkflow) return 'Current workflow or pain point is required.';
    if (!ticket.improvementChange) return 'What should change is required.';
    if (!ticket.successCriteria) return 'What would improve is required.';
  }

  return '';
}

function buildIssueBody(ticket) {
  const lines = [
    '## Submission',
    bullet('Source', 'customer-portal'),
    bullet('Submitted at', ticket.submittedAt),
    bullet('Requester', ticket.name),
    bullet('Email', ticket.email || 'Not provided'),
    bullet('Contact consent', ticket.contactConsent === 'yes' ? 'Yes' : 'No'),
    bullet('Company', ticket.company || 'Not provided'),
    bullet('Role', ticket.role || 'Not provided'),
    bullet('Type', ticket.type),
    bullet('Product area', ticket.area),
    bullet('Severity', ticket.severity),
    bullet('Environment', ticket.environment || 'Not applicable'),
    '',
    '## Business Context',
    ticket.businessContext,
  ];

  if (ticket.type === 'bug') {
    lines.push(
      '',
      '## Actual Result',
      ticket.actualResult,
      '',
      '## Expected Result',
      ticket.expectedResult,
      '',
      '## Steps To Reproduce',
      numberedBlock(ticket.reproductionSteps),
      '',
      '## Repro Metadata',
      bullet('Frequency', ticket.frequency),
      bullet('First noticed', ticket.startedAt || 'Not provided'),
      bullet('Workaround', ticket.workaround || 'None provided'),
    );
  }

  if (ticket.type === 'feature') {
    lines.push(
      '',
      '## Who Needs This',
      ticket.featureUsers,
      '',
      '## Use Case',
      ticket.featureUseCase,
      '',
      '## Requested Capability',
      ticket.requestedOutcome,
      '',
      '## Success Criteria',
      ticket.successCriteria,
      '',
      '## Alternatives Considered',
      ticket.alternatives || 'None provided',
    );
  }

  if (ticket.type === 'improvement') {
    lines.push(
      '',
      '## Current Workflow / Pain Point',
      ticket.currentWorkflow,
      '',
      '## Proposed Improvement',
      ticket.improvementChange,
      '',
      '## Expected Improvement',
      ticket.successCriteria,
      '',
      '## Current Workaround',
      ticket.alternatives || 'None provided',
    );
  }

  lines.push(
    '',
    '## References',
    ticket.references || 'None provided',
    '',
    '## Additional Notes',
    ticket.additionalNotes || 'None provided',
    '',
    '## Technical Context',
    bullet('Page URL', ticket.pageUrl || 'Not captured'),
    bullet('User agent', ticket.userAgent || 'Not captured'),
    bullet('Language', ticket.language || 'Not captured'),
    bullet('Screen size', ticket.screenSize || 'Not captured'),
    bullet('Time zone', ticket.timeZone || 'Not captured'),
    '',
    '## Internal Metadata',
    '<!--',
    meta('source', 'customer-portal'),
    meta('submitted_at', ticket.submittedAt),
    meta('submitter_name', ticket.name),
    meta('submitter_email', ticket.email),
    meta('contact_consent', ticket.contactConsent),
    meta('company', ticket.company),
    meta('role', ticket.role),
    meta('type', ticket.type),
    meta('area', ticket.area),
    meta('severity', ticket.severity),
    meta('environment', ticket.environment),
    meta('frequency', ticket.frequency),
    meta('feature_users', ticket.featureUsers),
    meta('feature_use_case', ticket.featureUseCase),
    meta('improvement_change', ticket.improvementChange),
    meta('page_url', ticket.pageUrl),
    meta('user_agent', ticket.userAgent),
    meta('language', ticket.language),
    meta('screen_size', ticket.screenSize),
    meta('time_zone', ticket.timeZone),
    '-->',
  );

  return lines.join('\n');
}

function numberedBlock(text) {
  return text
    .split('\n')
    .map(line => line.trim())
    .filter(Boolean)
    .map((line, index) => `${index + 1}. ${line.replace(/^\d+\.\s*/, '')}`)
    .join('\n');
}

function bullet(label, value) {
  return `- ${label}: ${value}`;
}

function meta(key, value) {
  return `${key}=${sanitizeMeta(value)}`;
}

function sanitizeMeta(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function text(value, max) {
  return String(value || '').trim().slice(0, max);
}

function pick(value, allowed) {
  return allowed.includes(value) ? value : '';
}
