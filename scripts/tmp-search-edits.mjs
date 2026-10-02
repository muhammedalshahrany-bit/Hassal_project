/**
 * Search every transcript for edit events that mention a phrase, across all
 * sessions, oldest first.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const projects = 'C:/Users/alshh/.qoder/projects';
const needle = process.argv[2];

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

const hits = [];
for (const file of transcripts(projects)) {
  for (const line of readFileSync(file, 'utf8').split('\n')) {
    if (!line.includes(needle)) continue;
    let event;
    try {
      event = JSON.parse(line);
    } catch {
      continue;
    }
    for (const block of event?.message?.content ?? []) {
      if (block?.type !== 'tool_use') continue;
      const input = block.input ?? {};
      const blob = JSON.stringify(input);
      if (!blob.includes(needle)) continue;
      hits.push({ ts: event.timestamp, file: file.split('/').pop(), name: block.name, path: String(input.file_path ?? '').split('/').pop() });
    }
  }
}
hits.sort((a, b) => String(a.ts).localeCompare(String(b.ts)));
for (const h of hits) console.log(`${h.ts}  ${h.name}  ${h.path}   (${h.file.slice(0, 8)})`);
console.log(`events mentioning "${needle}": ${hits.length}`);
