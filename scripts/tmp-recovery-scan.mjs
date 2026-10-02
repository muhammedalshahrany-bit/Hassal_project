/**
 * Which transcripts wrote which file.
 *
 * A bulk move script deleted thirty-nine source files, and only part of them is
 * in git: the rest lives in the sessions that created and edited them. The list
 * below is read out of that script's own table, so the ledger covers exactly what
 * was lost, and every full-content write, every patch and every shell command that
 * named one of those files is recorded oldest first.
 */
import { readFileSync } from 'node:fs';
import { existsSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = 'C:/Users/alshh/.qoder/projects';

/** The lost files, straight from the table that moved them. */
const moves = readFileSync('scripts/tmp-stage3-move.mjs', 'utf8');
const targets = [...moves.matchAll(/\['(src\/[^']+)', '(src\/[^']+)'\]/g)].map((match) => match[1]).filter((target) => /\.(ts|tsx)$/.test(target));

const transcripts = [];
(function walk(dir) {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) walk(path);
    else if (entry.endsWith('.jsonl') && statSync(path).size > 200_000) transcripts.push(path);
  }
})(ROOT);

const ledger = new Map();
const record = (file, event) => {
  if (!ledger.has(file)) ledger.set(file, []);
  ledger.get(file).push(event);
};

for (const transcript of transcripts) {
  readFileSync(transcript, 'utf8')
    .split('\n')
    .filter(Boolean)
    .forEach((line, index) => {
      let entry;
      try {
        entry = JSON.parse(line);
      } catch {
        return;
      }
      const blocks = Array.isArray(entry?.message?.content) ? entry.message.content : [];
      for (const block of blocks) {
        if (block?.type !== 'tool_use') continue;
        const input = block.input ?? {};
        const kind = block.name;
        const file = String(input.file_path ?? input.notebook_path ?? '').replace(/\\/g, '/');
        if (kind === 'Bash') {
          /* A shell command can rewrite a file without naming its path, so the
             basename is what proves whether one of these ever went through perl,
             sed or a node one-liner. */
          const command = String(input.command ?? '');
          for (const target of targets) {
            const name = target.split('/').pop().replace(/\.(ts|tsx)$/, '');
            if (command.includes(target) || new RegExp(`\\b${name}\\b`).test(command)) {
              record(target, { at: entry.timestamp, transcript, index, kind, note: command.slice(0, 200).replace(/\s+/g, ' ') });
            }
          }
          continue;
        }
        const hit = targets.find((target) => file.endsWith(target));
        if (!hit) continue;
        let note = kind;
        if (kind === 'Write') note = `content=${input.content?.length ?? 0}`;
        else if (kind === 'Edit') note = `old=${input.old_string?.length ?? 0} new=${input.new_string?.length ?? 0}${input.allow_multiple ? ' ALL' : ''}`;
        else if (kind === 'Read') note = `offset=${input.offset ?? '-'} limit=${input.limit ?? '-'} pages=${input.pages ?? '-'}`;
        record(hit, { at: entry.timestamp, transcript, index, kind, note });
      }
    });
}

const sorted = [...ledger.keys()].sort();
for (const key of sorted) {
  const events = ledger.get(key).sort((a, b) => String(a.at).localeCompare(String(b.at)) || a.index - b.index);
  const tally = {};
  for (const event of events) tally[event.kind] = (tally[event.kind] ?? 0) + 1;
  console.log(`\n${key}  [${Object.entries(tally).map(([k, v]) => `${k}=${v}`).join(' ')}]  last ${events.at(-1).at} ${events.at(-1).kind}`);
  for (const event of events) {
    const short = event.transcript.split('/').slice(-2).join('/');
    console.log(`   ${event.at} ${event.kind.padEnd(5)} ${event.note.slice(0, 150)}  (${short}#${event.index})`);
  }
}
console.log(`\ntargets: ${targets.length}, files with events: ${sorted.length}`);
console.log('without events: ' + targets.filter((t) => !ledger.has(t)).join(', '));
