import { api, login, CODE } from './lib/api.mjs';

const who = ['amal.owner', 'sultan.owner', 'amal.staff', 'nakhil.staff'];

for (const slug of who) {
  try {
    const s = await login(`${slug}@hassal.test`, CODE);
    const orgs = s.organizations.map(o => `${o.id}:${o.name_ar}:${o.type}:${o.role}`).join(' | ');
    console.log(`\n== ${slug}  user#${s.user?.id}  orgs -> ${orgs}`);
    console.log(`   active=${s.active?.id ?? 'none'}  perms(${(s.user?.permissions ?? []).length}) = ${(s.user?.permissions ?? []).join(',')}`);
  } catch (e) { console.log(`\n!! ${slug}: ${e.message}`); }
}

const offers = await api('GET', '/marketplace/offers?per_page=100');
const rows = offers.json?.data ?? [];
console.log(`\n== offers ${offers.status} n=${rows.length}`);
for (const o of rows.slice(0, 40)) {
  console.log(`   #${o.id} seller=${o.seller_organization?.id ?? o.seller_organization_id} avail=${o.available_quantity} min=${o.min_order_quantity} price=${o.price} cur=${o.currency_code} status=${o.status}`);
}
