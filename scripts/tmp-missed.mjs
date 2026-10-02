/**
 * The edits the replay could not place.
 *
 * Reconstructing a deleted file from its transcript is only honest if the edits
 * that did not match are looked at one by one. This replays the same way the
 * recovery script does, then prints every edit whose text did not appear in the
 * reconstruction at that moment — with the surrounding lines the reconstruction
 * holds — so the gap can be closed by hand instead of assumed away.
 */
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = 'C:/Users/alshh/.qoder/projects';
const COMMIT_AT = '2026-09-24T13:29:59.000Z';
const wanted = process.argv.slice(2);

const targets = [...readFileSync('scripts/tmp-stage3-move.mjs', 'utf8').matchAll(/\['(src\/[^']+)', '(src\/[^']+)'\]/g)]
  .map((match) => match[1])
  .filter((target) => /\.(ts|tsx)$/.test(target) && (!wanted.length || wanted.includes(target)));

const transcripts = [];
(function walk(dir) {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) walk(path);
    else if (entry.endsWith('.jsonl') && statSync(path).size > 200_000) transcripts.push(path);
  }
})(ROOT);
transcripts.sort();

const failures = new Map();
const events = new Map();
for (const transcript of transcripts) {
  const lines = readFileSync(transcript, 'utf8').split('\n').filter(Boolean);
  for (const line of lines) {
    let entry;
    try {
      entry = JSON.parse(line);
    } catch {
      continue;
    }
    const blocks = Array.isArray(entry?.message?.content) ? entry.message.content : [];
    for (const block of blocks) {
      if (block?.type === 'tool_result') failures.set(block.tool_use_id, block.is_error === true);
    }
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
      if (block?.type !== 'tool_use' || (block.name !== 'Write' && block.name !== 'Edit')) continue;
      const file = String(block.input?.file_path ?? '').replace(/\\/g, '/');
      const target = targets.find((candidate) => file.endsWith(candidate));
      if (!target) continue;
      if (!events.has(target)) events.set(target, []);
      events.get(target).push({
        at: entry.timestamp,
        index,
        transcript: transcript.slice(ROOT.length + 1),
        kind: block.name,
        input: block.input,
        failed: failures.get(block.id) === true,
      });
    }
  });
}

for (const target of targets) {
  const all = (events.get(target) ?? []).sort((a, b) => String(a.at).localeCompare(String(b.at)) || a.index - b.index);
  const good = all.filter((event) => !event.failed);
  const writes = good.filter((event) => event.kind === 'Write');
  const lastWrite = writes.at(-1);
  let head = null;
  try {
    head = execFileSync('git', ['show', `HEAD:${target}`], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  } catch {
    head = null;
  }
  let text;
  let startAt;
  if (lastWrite && lastWrite.at > COMMIT_AT) {
    text = lastWrite.input.content;
    startAt = lastWrite.at;
  } else if (head !== null) {
    text = head;
    startAt = COMMIT_AT;
  } else if (lastWrite) {
    text = lastWrite.input.content;
    startAt = lastWrite.at;
  } else {
    console.log(`${target}: no base`);
    continue;
  }

  for (const event of good.filter((entry) => entry.kind === 'Edit' && entry.at > startAt)) {
    const oldText = event.input.old_string ?? '';
    const newText = event.input.new_string ?? '';
    if (text.includes(oldText)) {
      text = event.input.allow_multiple ? text.replaceAll(oldText, newText) : text.replace(oldText, () => newText);
      continue;
    }
    const anchor = newText.split('\n').filter((line) => line.trim()).slice(0, 2).join('\n');
    const where = anchor ? text.indexOf(anchor.split('\n')[0].trim().slice(0, 40)) : -1;
    console.log(`\n===== ${target}  ${event.at}  ${event.transcript.split('/').pop().slice(0, 8)}#${event.index}`);
    console.log('--- OLD (not found):');
    console.log(oldText);
    console.log('--- NEW (what it should have become):');
    console.log(newText);
    console.log('--- reconstruction around the first line of NEW:');
    const needle = newText.split('\n').map((line) => line.trim()).filter(Boolean)[0] ?? '';
    const at = needle ? text.indexOf(needle.slice(0, 40)) : -1;
    if (at < 0) console.log('  (no trace of the new text either)');
    else console.log(text.slice(Math.max(0, at - 400), at + 400));
  }
}
