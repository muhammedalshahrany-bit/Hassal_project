/**
 * Print, in full, every edit one file received inside a time window.
 *
 * The replay reports a miss but not what surrounded it; deciding what the final
 * text should say needs the whole old and new pair, in order.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const [target, from, to] = process.argv.slice(2);
const projects = 'C:/Users/alshh/.qoder/projects';

function transcripts(dir) {
  const out = [];
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    const st = statSync(path);
    if (st.isDirectory()) out.push(...transcripts(path));
    else if (entry.endsWith('.jsonl') && st.size > 200_000) out.push(path);
  }
  return out;
}

const events = [];
for (const file of transcripts(projects)) {
  const lines = readFileSync(file, 'utf8').split('\n');
  const errors = new Map();
  for (const line of lines) {
    let e;
    try {
      e = JSON.parse(line);
    } catch {
      continue;
    }
    for (const b of e?.message?.content ?? []) if (b?.type === 'tool_result') errors.set(b.tool_use_id, b.is_error === true);
  }
  lines.forEach((line, index) => {
    let e;
    try {
      e = JSON.parse(line);
    } catch {
      return;
    }
    for (const b of e?.message?.content ?? []) {
      if (b?.type !== 'tool_use' || b.name !== 'Edit') continue;
      const path = String(b.input?.file_path ?? '');
      if (!path.endsWith(target)) continue;
      if (from && String(e.timestamp) < from) continue;
      if (to && String(e.timestamp) > to) continue;
      events.push({ ts: e.timestamp, failed: errors.get(b.id) === true, old: b.input.old_string, new: b.input.new_string, all: !!b.input.allow_multiple });
    }
  });
}

events.sort((a, b) => String(a.ts).localeCompare(String(b.ts)));
for (const ev of events) {
  console.log(`\n${'#'.repeat(80)}\n${ev.ts}  failed=${ev.failed}  all=${ev.all}\n${'#'.repeat(80)}`);
  console.log(`--- OLD ---\n${ev.old}`);
  console.log(`--- NEW ---\n${ev.new}`);
}
console.log(`\nedits: ${events.length}`);
