import { useRouter } from 'vue-router';
import { usePortal } from '../auth/session.js';
export function useAccountAccess() {
  const { session } = usePortal();
  const router = useRouter();
  async function handleFailure(failure) {
    if (failure.status === 401) session.clear();
    else if (failure.status === 403) await session.refresh();
    if ((failure.status === 401 || failure.status === 403) && !session.state.identity) await router.replace({ name: 'login' });
    return failure.status === 409 ? 'تغير السجل منذ فتحه. أغلق النافذة وحدّث القائمة قبل المحاولة.'
      : failure.status === 419 ? 'انتهت صلاحية الطلب. أعد المحاولة.'
      : failure.status === 403 ? 'ليست لديك صلاحية تنفيذ هذا الإجراء.'
      : failure.status === 404 ? 'السجل غير متاح ضمن نطاق حسابك.'
      : failure.status === 422 ? 'تحقق من الحقول ثم أعد المحاولة.' : failure.message;
  }
  return { session, router, handleFailure };
}
