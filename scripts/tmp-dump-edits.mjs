/** Dump the edits one file received, oldest first, with their match text. */
import { readFileSync } from 'node:fs';

const P = 'C:/Users/alshh/.qoder/projects/D--hassal-app-flutter/91058cfd-f9a7-4637-a5bb-275d9ccd9efd.jsonl';
const needle = process.argv[2];
const lines = readFileSync(P, 'utf8').split('\n').filter(Boolean);

const errors = new Map();
for (const line of lines) {
  let entry;
  try {
    entry = JSON.parse(line);
  } catch {
    continue;
  }
  const blocks = Array.isArray(entry?.message?.content) ? entry.message.content : [];
  for (const block of blocks) if (block?.type === 'tool_result') errors.set(block.tool_use_id, block.is_error === true);
}

lines.forEach((line, index) => {
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
    const file = String(input.file_path ?? '').split('/').pop();
    if (file !== needle) continue;
    const oldText = JSON.stringify(input.old_string ?? '').slice(0, 260);
    const newText = JSON.stringify(input.new_string ?? '').slice(0, 260);
    console.log(`#${index} ${entry.timestamp} ${block.name} failed=${errors.get(block.id) === true} all=${!!input.allow_multiple}`);
    if (block.name === 'Write') console.log(`   content=${String(input.content).length} chars`);
    else console.log(`   OLD ${oldText}\n   NEW ${newText}`);
  }
});
