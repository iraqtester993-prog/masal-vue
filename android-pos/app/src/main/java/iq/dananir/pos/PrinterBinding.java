package iq.dananir.pos;

import android.content.*;
import android.os.*;
import java.util.concurrent.*;
import java.util.concurrent.atomic.*;

/** One context owns the bind and unbind, including failures and late connections. */
final class PrinterBinding implements AutoCloseable {
    private final Context context;
    private final Handler main = new Handler(Looper.getMainLooper());
    private final CountDownLatch ready = new CountDownLatch(1);
    private final AtomicReference<IBinder> binder = new AtomicReference<>();
    private final AtomicReference<Exception> error = new AtomicReference<>();
    private boolean bound, closed;
    private final ServiceConnection connection = new ServiceConnection() {
        public void onServiceConnected(ComponentName name, IBinder service) {
            synchronized (PrinterBinding.this) { if (!closed) binder.set(service); }
            ready.countDown();
        }
        public void onServiceDisconnected(ComponentName name) { binder.set(null); }
        public void onBindingDied(ComponentName name) { binder.set(null); ready.countDown(); }
        public void onNullBinding(ComponentName name) { ready.countDown(); }
    };

    PrinterBinding(Context context) { this.context = context.getApplicationContext(); }

    IBinder connect(Intent intent, String descriptor) throws Exception {
        if (Looper.myLooper() == Looper.getMainLooper()) throw new IllegalStateException("Printer binding must run off the UI thread");
        main.post(() -> {
            synchronized (this) {
                if (closed) { ready.countDown(); return; }
                try { bound = context.bindService(intent, connection, Context.BIND_AUTO_CREATE); }
                catch (Exception failure) { error.set(failure); }
                if (!bound) ready.countDown();
            }
        });
        if (!ready.await(10, TimeUnit.SECONDS)) throw new Exception("انتهت مهلة الاتصال بخدمة الطابعة.");
        if (error.get() != null) throw error.get();
        IBinder result = binder.get();
        if (result == null || !result.isBinderAlive()) throw new Exception("خدمة الطابعة غير متصلة أو موقوفة على الجهاز.");
        if (!descriptor.equals(result.getInterfaceDescriptor())) throw new Exception("واجهة خدمة الجهاز تختلف عن SDK المحدد؛ افتح فحص الجهاز.");
        return result;
    }

    public void close() {
        synchronized (this) { closed = true; binder.set(null); }
        main.post(() -> {
            synchronized (this) {
                if (bound) {
                    try { context.unbindService(connection); }
                    catch (Exception failure) { PrinterDiagnostics.record(context, "unbind", failure); }
                    bound = false;
                }
            }
        });
    }
}
