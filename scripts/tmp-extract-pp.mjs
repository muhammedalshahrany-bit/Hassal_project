/**
 * Extract every transcript payload that could be the PermissionPicker source:
 * a Write of it, or a terminal result that printed all of it.
 */
import { readFileSync, writeFileSync } from 'node:fs';

const files = [
  'C:/Users/alshh/.qoder/projects/D--hassal-app-flutter/01160dd6-f8b1-4ee2-a4e2-fcbc55a6730c.jsonl',
  'C:/Users/alshh/.qoder/projects/D--hassal-app-flutter/91058cfd-f9a7-4637-a5bb-275d9ccd9efd.jsonl',
];

const bodies = [];
for (const file of files) {
  for (const line of readFileSync(file, 'utf8').split('\n')) {
    if (!line.trim()) continue;
    let event;
    try {
      event = JSON.parse(line);
    } catch {
      continue;
    }
    for (const part of event.message?.content ?? []) {
      if (part.type === 'tool_use' && typeof part.input?.content === 'string' && part.input.content.includes('OverrideState')) {
        bodies.push({ kind: 'write', file: file.split('/').pop(), ts: event.timestamp, path: part.input.file_path, body: part.input.content });
      }
      if (part.type === 'tool_use' && part.name === 'Edit' && JSON.stringify(part.input).includes('PermissionPicker')) {
        bodies.push({ kind: 'edit', file: file.split('/').pop(), ts: event.timestamp, path: part.input.file_path, body: JSON.stringify(part.input, null, 2) });
      }
      if (part.type === 'tool_result') {
        const body = typeof part.content === 'string' ? part.content : JSON.stringify(part.content);
        if (body.includes('export function statesFrom') && body.includes('OverrideState')) {
          bodies.push({ kind: 'result', file: file.split('/').pop(), ts: event.timestamp, path: part.tool_use_id, body });
        }
      }
    }
  }
}

bodies.sort((a, b) => String(a.ts).localeCompare(String(b.ts)));
let out = '';
for (const entry of bodies) out += `\n${'#'.repeat(90)}\n${entry.kind} ${entry.file} ${entry.ts} ${entry.path} len=${entry.body.length}\n${'#'.repeat(90)}\n${entry.body}\n`;
writeFileSync('backend/storage/permissionpicker-full.txt', out, 'utf8');
console.log(bodies.map((b) => `${b.kind} ${b.ts} ${b.path} len=${b.body.length}`).join('\n'));
