import {minor} from './finance-model.js';

export function posWalletValues(summary, ownVisible = true) {
  const value = field => {
    if (!ownVisible || summary?.[field] === null || summary?.[field] === undefined || summary?.[field] === '') return null;
    try { minor(summary[field]); return summary[field]; } catch { return null; }
  };
  return {available:value('own_available'),current:value('own_balance'),held:value('own_held')};
}
