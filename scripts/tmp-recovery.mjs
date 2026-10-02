/**
 * Rebuild the deleted files from the sessions that wrote them.
 *
 * A bulk move script deleted thirty-nine source files. Thirty-two are in git, but
 * the commit is from 2026-09-24 and every one of them changed after that, so a
 * plain checkout would hand back an older app. This replays what the transcripts
 * hold instead: the last full write as the base, then every successful edit after
 * it, in order. An edit whose text does not match the reconstruction is reported
 * rather than guessed at, and the result lands in a staging directory for review —
 * nothing here writes into `src/`.
 */
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

const ROOT = 'C:/Users/alshh/.qoder/projects';
const STAGE = 'backend/storage/web-recovery/stage';
/** The initial commit, in the same UTC form the transcripts use. */
const COMMIT_AT = '2026-09-24T13:29:59.000Z';

/** Files read from the table that moved them, plus one that never had a write. */
const targets = [...readFileSync('scripts/tmp-stage3-move.mjs', 'utf8').matchAll(/\['(src\/[^']+)', '(src\/[^']+)'\]/g)]
  .map((match) => match[1])
  .filter((target) => /\.(ts|tsx)$/.test(target));

const transcripts = [];
(function walk(dir) {
  for (const entry of readdirSync(dir)) {
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) walk(path);
    else if (entry.endsWith('.jsonl') && statSync(path).size > 200_000) transcripts.push(path);
  }
})(ROOT);
transcripts.sort();

/** tool_use_id -> the tool answered with an error. */
const failures = new Map();
for (const transcript of transcripts) {
  for (const line of readFileSync(transcript, 'utf8').split('\n').filter(Boolean)) {
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
}

const events = new Map();
for (const [order, transcript] of transcripts.entries()) {
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
        if (block?.type !== 'tool_use' || (block.name !== 'Write' && block.name !== 'Edit')) continue;
        const file = String(block.input?.file_path ?? '').replace(/\\/g, '/');
        const target = targets.find((candidate) => file.endsWith(candidate));
        if (!target) continue;
        if (!events.has(target)) events.set(target, []);
        events.get(target).push({
          target,
          at: entry.timestamp,
          order,
          index,
          transcript: transcript.split('/').slice(-1)[0].slice(0, 8),
          sidechain: entry.isSidechain === true,
          kind: block.name,
          input: block.input,
          failed: failures.get(block.id) === true,
        });
      }
    });
}

const inOrder = (list) =>
  [...list].sort((a, b) => String(a.at).localeCompare(String(b.at)) || a.order - b.order || a.index - b.index);

const report = [];
for (const target of targets) {
  const all = inOrder(events.get(target) ?? []);
  const good = all.filter((event) => !event.failed);
  const writes = good.filter((event) => event.kind === 'Write' && typeof event.input.content === 'string');

  let base;
  let baseFrom;
  let startAt;
  const headAvailable = (() => {
    try {
      return execFileSync('git', ['show', `HEAD:${target}`], { encoding: 'utf8', cwd: process.cwd(), stdio: ['ignore', 'pipe', 'ignore'] });
    } catch {
      return null;
    }
  })();

  const lastWrite = writes.at(-1);
  if (lastWrite && lastWrite.at > COMMIT_AT) {
    base = lastWrite.input.content;
    baseFrom = `write@${lastWrite.at}`;
    startAt = lastWrite.at;
  } else if (headAvailable !== null) {
    base = headAvailable;
    baseFrom = 'git HEAD';
    startAt = COMMIT_AT;
  } else if (lastWrite) {
    base = lastWrite.input.content;
    baseFrom = `write@${lastWrite.at} (before the commit)`;
    startAt = lastWrite.at;
  } else {
    report.push({ target, status: 'NO BASE', writes: writes.length, edits: good.filter((e) => e.kind === 'Edit').length });
    continue;
  }

  let text = base;
  const applied = [];
  const missed = [];
  for (const event of good.filter((entry) => entry.kind === 'Edit' && entry.at > startAt)) {
    const { old_string: oldText = '', new_string: newText = '', allow_multiple: all_ = false } = event.input;
    if (!text.includes(oldText)) {
      missed.push({ at: event.at, transcript: event.transcript, old: oldText.slice(0, 100) });
      continue;
    }
    text = all_ ? text.replaceAll(oldText, newText) : text.replace(oldText, () => newText);
    applied.push(event.at);
  }

  const interleaved = new Set(all.map((event) => event.transcript)).size > 1;
  mkdirSync(join(STAGE, dirname(target)), { recursive: true });
  writeFileSync(join(STAGE, target), text, 'utf8');
  report.push({
    target,
    status: 'ok',
    baseFrom,
    lines: text.split('\n').length,
    applied: applied.length,
    missed: missed.length,
    skippedFailed: all.length - good.length,
    sessions: [...new Set(all.map((event) => event.transcript))].join('+'),
    sidechain: all.some((event) => event.sidechain),
    detail: missed.length ? JSON.stringify(missed[0]) : '',
  });
}

for (const entry of report) {
  console.log(
    `${entry.target.padEnd(42)} ${entry.status.padEnd(8)} ${entry.baseFrom ?? ''} ` +
      `lines=${entry.lines ?? '-'} applied=${entry.applied ?? '-'} missed=${entry.missed ?? '-'} failed-skipped=${entry.skippedFailed ?? '-'} ` +
      `sessions=${entry.sessions ?? '-'}${entry.sidechain ? ' SIDECHAIN' : ''} ${entry.detail}`,
  );
}
