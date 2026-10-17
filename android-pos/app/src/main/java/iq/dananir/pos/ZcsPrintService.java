package iq.dananir.pos;

import android.app.Service;
import android.content.Intent;
import android.graphics.*;
import android.os.*;
import android.text.Layout;
import com.zcs.sdk.*;

/** ZCS native libraries run outside the WebView process; no sales or network calls here. */
public final class ZcsPrintService extends Service {
    private HandlerThread worker;
    private Messenger incoming;
    private Printer printer;
    @Override public void onCreate() {
        super.onCreate(); PrinterDiagnostics.install(getApplicationContext());
        worker = new HandlerThread("zcs-print"); worker.start();
        incoming = new Messenger(new Handler(worker.getLooper(), this::command));
    }
    @Override public IBinder onBind(Intent intent) { return incoming.getBinder(); }
    private boolean command(Message message) {
        Bundle response = new Bundle(); int code = -1; String detail = "طلب غير صالح";
        Bitmap strip = null;
        try {
            if (message.what == 1) {
                if (!PrinterDiscovery.supportsZcsCpu()) throw new IllegalStateException("معالج الجهاز غير مدعوم بمكتبة ZCS المرفقة");
                DriverManager manager = DriverManager.getInstance(); Sys system = manager.getBaseSysDevice();
                system.showLog(false);
                code = system.sdkInit();
                if (code != SdkResult.SDK_OK) {
                    system.sysPowerOn(); Thread.sleep(1000); code = system.sdkInit();
                }
                if (code == SdkResult.SDK_OK) { printer = manager.getPrinter(); code = printer.getPrinterStatus(); }
                detail = "فحص التهيئة/الورق/الحرارة";
            } else if (printer != null && (message.what == 2 || message.what == 3)) {
                code = awaitReady(); detail = "انتظار انتهاء الطباعة السابقة وفحص الحالة";
                if (code == SdkResult.SDK_OK) {
                    if (message.what == 2) {
                        message.getData().setClassLoader(Bitmap.class.getClassLoader());
                        strip = message.getData().getParcelable("strip");
                        if (strip == null || strip.getWidth() > 576 || strip.getHeight() > 192) throw new IllegalArgumentException("شريط طباعة غير صالح");
                    } else {
                        strip = Bitmap.createBitmap(384, 192, Bitmap.Config.ARGB_8888); new Canvas(strip).drawColor(Color.WHITE);
                    }
                    printer.setPrintAppendBitmap(strip, Layout.Alignment.ALIGN_CENTER);
                    code = printer.setPrintStart(strip.getWidth(), false); detail = "إرسال الصورة إلى الطابعة";
                    if (code == SdkResult.SDK_OK) {
                        Thread.sleep(150);
                        code = awaitReady(); detail = "انتظار اكتمال جزء الوصل";
                    }
                }
            }
        } catch (Exception | LinkageError failure) {
            PrinterDiagnostics.record(this, "zcs-command-" + message.what, failure);
            code = -1; detail = failure.getClass().getSimpleName();
        } finally { if (strip != null && !strip.isRecycled()) strip.recycle(); }
        response.putInt("code", code); response.putString("detail", detail);
        if (message.replyTo != null) {
            try { Message reply = Message.obtain(); reply.setData(response); message.replyTo.send(reply); }
            catch (RemoteException failure) { PrinterDiagnostics.record(this, "zcs-reply", failure); }
        }
        return true;
    }
    private int awaitReady() throws InterruptedException {
        return PrinterReadyWait.await(printer::getPrinterStatus, SdkResult.SDK_PRN_STATUS_PRINTING, 12000, SystemClock::elapsedRealtime, Thread::sleep);
    }
    @Override public void onDestroy() {
        if (worker != null) worker.quitSafely();
        super.onDestroy();
        // This class is declared in :zcs_printer. Terminate its native SDK/serial
        // state after unbinding, including a stuck JNI call, without touching WebView.
        android.os.Process.killProcess(android.os.Process.myPid());
    }
}
