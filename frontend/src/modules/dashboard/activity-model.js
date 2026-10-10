const completedStates = new Set(['recorded', 'succeeded', 'Printed', 'Reprinted', 'Delivered', 'Issued']);
const pendingStates = new Set(['pending', 'processing', 'unknown', 'Print Requested', 'Reprint Requested', 'Reserved']);
export function activityTone(status) { return completedStates.has(status) ? 'success' : pendingStates.has(status) ? 'pending' : 'other'; }
