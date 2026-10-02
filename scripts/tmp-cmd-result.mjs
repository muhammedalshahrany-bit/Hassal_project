/** Print the answer a shell command got, so a splice can be verified not guessed. */
import { readFileSync, readdirSync, statSync } from 'node:fs';
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

const needle = process.argv[2];

const lines = [];
for (const transcript of transcripts) lines.push(...readFileSync(transcript, 'utf8').split('\n').filter(Boolean));

const results = new Map();
for (const line of lines) {
  let entry;
  try {
    entry = JSON.parse(line);
  } catch {
    continue;
  }
  const blocks = Array.isArray(entry?.message?.content) ? entry.message.content : [];
  for (const block of blocks) {
    if (block?.type === 'tool_result') results.set(block.tool_use_id, typeof block.content === 'string' ? block.content : JSON.stringify(block.content));
  }
}

for (const line of lines) {
  let entry;
  try {
    entry = JSON.parse(line);
  } catch {
    continue;
  }
  const blocks = Array.isArray(entry?.message?.content) ? entry.message.content : [];
  for (const block of blocks) {
    if (block?.type !== 'tool_use' || block.name !== 'Bash') continue;
    const command = String(block.input?.command ?? '');
    if (!command.includes(needle)) continue;
    console.log(`\n### ${entry.timestamp}\n$ ${command}\n--> ${String(results.get(block.id)).slice(0, 2500)}`);
  }
}
