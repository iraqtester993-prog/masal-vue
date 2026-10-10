import {businessDay, difference} from './finance-model.js';

export function matchesPrice(row, filters) {
  const query=String(filters.query || '').trim().toLocaleLowerCase('ar');
  const state=filters.status;
  const priced=row.price !== null && row.price !== undefined;
  const day=row.price_updated_at ? businessDay(new Date(row.price_updated_at)) : '';
  return (!query || `${row.name} ${row.product_id} ${row.face_value ?? ''}`.toLocaleLowerCase('ar').includes(query))
    && (!filters.product || String(row.product_id)===String(filters.product))
    && (!filters.provider || String(row.provider_id)===String(filters.provider))
    && (!filters.currency || row.currency===filters.currency)
    && (!state || (state==='priced' ? priced : state==='unpriced' ? !priced : row.status===state))
    && (!filters.from || (!!day && day>=filters.from))
    && (!filters.to || (!!day && day<=filters.to));
}

export function priceDifference(next, previous) {
  return previous === null || previous === undefined ? null : difference(next, previous);
}
