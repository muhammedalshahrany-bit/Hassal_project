/**
 * Pull the PermissionPicker source back out of the transcripts.
 *
 * The file was never committed and never written through the editor, so the only
 * copy that exists is the terminal output that printed it before it was deleted.
 * Find every tool result that contains its export signature and dump them.
 */
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const projects = 'C:/Users/alshh/.qoder/projects';
const found = [];

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

for (const file of transcripts(projects)) {
  const lines = readFileSync(file, 'utf8').split('\n');
  for (let i = 0; i < lines.length; i += 1) {
    if (!lines[i].trim()) continue;
    let event;
    try {
      event = JSON.parse(lines[i]);
    } catch {
      continue;
    }
    const text = JSON.stringify(event);
    if (!text.includes('PermissionPicker')) continue;
    if (!text.includes('OverrideState')) continue;
    found.push({ file, line: i + 1, size: text.length, sample: text.slice(0, 160) });
  }
}

console.log(`candidates: ${found.length}`);
for (const c of found) console.log(`${c.file}:${c.line} len=${c.size}\n    ${c.sample}`);
writeFileSync('backend/storage/permissionpicker-candidates.json', JSON.stringify(found, null, 2), 'utf8');
