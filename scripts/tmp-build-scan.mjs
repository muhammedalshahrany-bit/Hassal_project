// Scratch: build one UNION that looks for stored class-name strings anywhere in
// the live database, so a namespace move can be checked against data, not hope.
import { readFileSync, writeFileSync } from 'node:fs';

const parts = ['Models', 'Auth', 'Trading', 'Catalog', 'Inventory', 'Platform', 'Identity', 'Organizations', 'Core', 'Support', 'Console', 'Notifications', 'SupportDesk', 'Marketplace', 'Providers'];

const rows = readFileSync('scripts/tmp-strcols.txt', 'utf8')
  .split('\n')
  .map((line) => line.split('\t'))
  .filter(([table, column]) => table && column);

const selects = rows.map(([table, column]) => {
  const where = parts
    .map((part) => `instr(cast(\`${column}\` as char), concat('App', char(92), '${part}'))`)
    .join(' > 0 or ');
  return `select '${table}.${column}' src, left(cast(\`${column}\` as char), 160) val, count(*) n from \`${table}\` where ${where} > 0 group by val`;
});

writeFileSync('scripts/tmp-scan.sql', selects.join('\nunion all\n') + '\n;\n');
console.log(`columns=${rows.length}`);
