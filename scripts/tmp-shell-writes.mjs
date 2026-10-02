/**
 * Shell commands that rewrote the web sources.
 *
 * The transcript replay only sees `Write` and `Edit`. A `perl -pi`, a `sed -i`, a
 * redirect or a one-shot node script changes a file without leaving either trace,
 * so those commands are the blind spot of the reconstruction and are listed here,
 * oldest first, for manual replay. Package-manager noise and read-only greps are
 * dropped: what matters is a command that writes.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = 'C:/Users/alshh/.qoder/projects';

const transcripts = [];
(function walk(dir) {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) walk(path);
    else if (entry.endsWith('.jsonl') && statSync(path).size > 200_000) transcripts.push(path);
  }
})(ROOT);
transcripts.sort();

/** Something that writes into a web source file. */
const WRITES = /(perl\s+-\s?p?i[^|]*-e|perl\s+-pi|sed\s+-i|writeFileSync|readdirSync\('src|cat\s*>|tee\s+|mv\s+src\/|rm\s+src\/|>\s*src\/|>\s*"src\/|>\s*'src\/|>\s*\$root|tmp-[a-z-]+\.mjs)/;
const NAMES = /(src\/(components|lib|app|shared|core|features)\/|src\/[A-Za-z]+\.(tsx|ts)|\bApp\.tsx\b|\.tsx\b)/;

const seen = new Set();
const found = [];
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
        if (block?.type !== 'tool_use' || block.name !== 'Bash') continue;
        const command = String(block.input?.command ?? '');
        if (!WRITES.test(command) || !NAMES.test(command)) continue;
        const key = command.replace(/\s+/g, ' ').trim();
        if (seen.has(key)) continue;
        seen.add(key);
        found.push({ at: entry.timestamp, transcript: transcript.split('/').pop(), index, command: key });
      }
    });
}

found.sort((a, b) => String(a.at).localeCompare(String(b.at)));
for (const event of found) {
  console.log(`\n### ${event.at} ${event.transcript.slice(0, 8)}#${event.index}`);
  console.log(event.command.slice(0, 700));
}
console.log(`\ncommands: ${found.length}`);
