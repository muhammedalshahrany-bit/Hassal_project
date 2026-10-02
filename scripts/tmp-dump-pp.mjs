/**
 * Dump the transcript events that mention PermissionPicker, so the source can be
 * read out of whichever tool result printed it.
 */
import { readFileSync, writeFileSync } from 'node:fs';

const targets = [
  ['C:/Users/alshh/.qoder/projects/D--hassal-app-flutter/01160dd6-f8b1-4ee2-a4e2-fcbc55a6730c.jsonl', [24627, 24683, 26125, 26132]],
  ['C:/Users/alshh/.qoder/projects/D--hassal-app-flutter/91058cfd-f9a7-4637-a5bb-275d9ccd9efd.jsonl', [8206, 8208, 8229, 8259, 8263, 8268, 8625]],
];

let out = '';
for (const [file, lines] of targets) {
  const all = readFileSync(file, 'utf8').split('\n');
  for (const number of lines) {
    const event = JSON.parse(all[number - 1]);
    out += `\n${'='.repeat(100)}\n${file.split('/').pop()}:${number}  type=${event.type}  ts=${event.timestamp}\n${'='.repeat(100)}\n`;
    const content = event.message?.content;
    if (typeof content === 'string') {
      out += content + '\n';
      continue;
    }
    for (const part of content ?? []) {
      if (part.type === 'text') out += `[text]\n${part.text}\n`;
      else if (part.type === 'tool_use') out += `[tool_use ${part.name} ${JSON.stringify(part.input).slice(0, 4000)}]\n`;
      else if (part.type === 'tool_result') {
        const body = typeof part.content === 'string' ? part.content : JSON.stringify(part.content);
        out += `[tool_result ${part.tool_use_id} isError=${part.is_error ?? false} len=${body.length}]\n${body.slice(0, 40000)}\n`;
      } else out += `[${part.type}]\n`;
    }
  }
}
writeFileSync('backend/storage/permissionpicker-events.txt', out, 'utf8');
console.log('written', out.length, 'chars');
