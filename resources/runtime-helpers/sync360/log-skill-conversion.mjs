import { DatabaseSync } from 'node:sqlite';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import path from 'node:path';

function parseArgs(argv) {
  const parsed = {};

  for (let index = 0; index < argv.length; index += 1) {
    const token = argv[index];

    if (!token.startsWith('--')) {
      continue;
    }

    const key = token.slice(2);
    parsed[key] = argv[index + 1] ?? null;
    index += 1;
  }

  return parsed;
}

function requireValue(value, label) {
  if (typeof value !== 'string' || value.trim() === '') {
    throw new Error(`${label} is required.`);
  }

  return value.trim();
}

function lookupPath(payload, dottedPath) {
  return dottedPath.split('.').reduce((carry, segment) => {
    if (carry === null || typeof carry !== 'object' || !(segment in carry)) {
      return undefined;
    }

    return carry[segment];
  }, payload);
}

const args = parseArgs(process.argv.slice(2));
const skillKey = requireValue(args.skill, 'skill');
const conversionId = requireValue(args['conversion-id'], 'conversion-id');
const payloadJson = requireValue(args['payload-json'], 'payload-json');

const payload = JSON.parse(payloadJson);
const scriptDir = path.dirname(new URL(import.meta.url).pathname);
const registryPath = path.resolve(scriptDir, '..', 'skill-analytics-registry.json');

if (!existsSync(registryPath)) {
  throw new Error('Skill analytics registry is missing.');
}

const registry = JSON.parse(readFileSync(registryPath, 'utf8'));
const skill = registry?.skills?.[skillKey];

if (!skill) {
  throw new Error(`Analytics is not enabled for skill [${skillKey}].`);
}

for (const requiredField of skill.required_success_fields ?? []) {
  if (lookupPath(payload, requiredField) === undefined) {
    throw new Error(`Missing required payload field [${requiredField}].`);
  }
}

const workspaceRoot = path.resolve(scriptDir, '..', '..');
const analyticsDir = path.join(workspaceRoot, '..', 'data', 'analytics');
mkdirSync(analyticsDir, { recursive: true });

const dbPath = path.join(analyticsDir, 'skill-events.sqlite');
const db = new DatabaseSync(dbPath);
db.exec(`
  PRAGMA journal_mode=WAL;
  CREATE TABLE IF NOT EXISTS skill_conversion_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL UNIQUE,
    skill_key TEXT NOT NULL,
    skill_version TEXT NOT NULL,
    event_type TEXT NOT NULL,
    conversion_type TEXT NOT NULL,
    conversion_id TEXT NOT NULL,
    occurred_at TEXT NOT NULL,
    session_id TEXT NULL,
    customer_label TEXT NOT NULL,
    contact_masked TEXT NULL,
    estimated_value_amount REAL NULL,
    currency TEXT NULL,
    effort_override_json TEXT NULL,
    outcome_json TEXT NOT NULL,
    created_at TEXT NOT NULL
  );
`);

const statement = db.prepare(`
  INSERT OR IGNORE INTO skill_conversion_events (
    event_id, skill_key, skill_version, event_type, conversion_type, conversion_id, occurred_at,
    session_id, customer_label, contact_masked, estimated_value_amount, currency, effort_override_json,
    outcome_json, created_at
  ) VALUES (
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
  )
`);

statement.run(
  requireValue(payload.event_id, 'payload.event_id'),
  skill.skill_key,
  skill.skill_version,
  skill.success_event_type,
  skill.conversion_type,
  conversionId,
  typeof payload.occurred_at === 'string' && payload.occurred_at.trim() !== '' ? payload.occurred_at.trim() : new Date().toISOString(),
  typeof payload.session_id === 'string' && payload.session_id.trim() !== '' ? payload.session_id.trim() : null,
  requireValue(payload.customer_label, 'payload.customer_label'),
  typeof payload.contact_masked === 'string' && payload.contact_masked.trim() !== '' ? payload.contact_masked.trim() : null,
  payload.estimated_value_amount ?? null,
  typeof payload.currency === 'string' && payload.currency.trim() !== '' ? payload.currency.trim() : null,
  payload.effort_override ? JSON.stringify(payload.effort_override) : null,
  JSON.stringify(payload.outcome ?? {}),
  new Date().toISOString(),
);

console.log(`Logged conversion event for ${skillKey}.`);
