// Mock of the external form tool API. No dependencies: node mock-api/server.mjs
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = Number(process.env.PORT ?? 8090);
const PER_PAGE = 25;
const SLOW_PAGE = 8;
const SLOW_DELAY_MS = 20_000;
const FLAKY_PAGE = 5;

const here = dirname(fileURLToPath(import.meta.url));
const load = (name) => JSON.parse(readFileSync(join(here, 'data', name), 'utf8'));
const revisions = [load('submissions-v1.json'), load('submissions-v2.json')];

let revision = 0;
const servedPages = new Set();
const callsPerPage = new Map();

const pageCount = (list) => Math.max(1, Math.ceil(list.length / PER_PAGE));

function send(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Content-Length': Buffer.byteLength(payload) });
  res.end(payload);
}

async function handleSubmissions(url, res) {
  const raw = url.searchParams.get('page') ?? '1';
  if (!/^\d+$/.test(raw) || Number(raw) < 1) {
    return send(res, 400, { error: 'invalid_parameter', message: 'page must be a positive integer' });
  }
  const page = Number(raw);
  const calls = (callsPerPage.get(page) ?? 0) + 1;
  callsPerPage.set(page, calls);

  if (page === FLAKY_PAGE && calls === 1) {
    return send(res, 500, { error: 'internal_error', message: 'An unexpected error occurred.' });
  }
  if (page === SLOW_PAGE && calls % 2 === 1) {
    await new Promise((r) => setTimeout(r, SLOW_DELAY_MS));
  }

  const { form, submissions } = revisions[revision];
  const totalPages = pageCount(submissions);
  const data = submissions.slice((page - 1) * PER_PAGE, page * PER_PAGE);
  send(res, 200, {
    form,
    data,
    meta: { page, per_page: PER_PAGE, total: submissions.length, total_pages: totalPages },
  });

  if (revision < revisions.length - 1 && page <= totalPages) {
    servedPages.add(page);
    if (servedPages.size === totalPages) {
      revision += 1;
      servedPages.clear();
    }
  }
}

const server = createServer((req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const started = Date.now();
  res.on('finish', () => console.log(`${req.method} ${url.pathname}${url.search} -> ${res.statusCode} (${Date.now() - started} ms)`));

  if (req.method === 'GET' && url.pathname === '/submissions') {
    handleSubmissions(url, res).catch((e) => send(res, 500, { error: 'internal_error', message: String(e) }));
  } else if (req.method === 'GET' && url.pathname === '/health') {
    send(res, 200, { status: 'ok' });
  } else {
    send(res, 404, { error: 'not_found' });
  }
});

server.listen(PORT, () => console.log(`Form API listening on :${PORT}`));
process.on('SIGTERM', () => server.close(() => process.exit(0)));
