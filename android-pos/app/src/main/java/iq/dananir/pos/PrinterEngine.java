package iq.dananir.pos;

import android.app.Activity;
import android.Manifest;
import android.content.pm.PackageManager;
import android.bluetooth.*;
import android.content.*;
import android.graphics.Bitmap;
import android.os.*;
import java.io.*;
import java.util.*;
import java.util.concurrent.*;
import java.util.concurrent.atomic.*;
import com.sunmi.peripheral.printer.*;
import com.pos.sdk.*;
import net.nyx.printerservice.print.IPrinterService;
import recieptservice.com.recieptservice.PrinterInterface;

final class PrinterEngine {
    private final Activity activity;
    private long deadline;
    PrinterEngine(Activity activity) { this.activity = activity; }
    void print(String mode, String address, List<Bitmap> strips, Runnable started) throws Exception {
        deadline = SystemClock.elapsedRealtime() + (mode.equals("bluetooth") ? 180000 : 70000);
        PrinterTransferProfile bluetoothProfile = null;
        boolean feasycom = false;
        if(mode.equals("bluetooth")) {
            android.content.SharedPreferences config=activity.getSharedPreferences("printer",Context.MODE_PRIVATE);
            int rows=0; for(Bitmap strip:strips)rows+=strip.getHeight();
            String printerType=config.getString("bluetooth_type", "");
            feasycom="Feasycom".equals(printerType);
            bluetoothProfile=PrinterTransferProfile.forPrinter(printerType,config.getBoolean("conservative",false));
            long timeout=bluetoothProfile.timeout(strips.get(0).getWidth(),rows,config.getString("image_mode","raster").equals("columns"));
            if(feasycom)timeout=Math.min(450000,timeout+BluetoothTailCompletion.MAX_DRAIN_MS+5000);
            deadline=SystemClock.elapsedRealtime()+timeout;
        }
        if (mode.equals("bluetooth")) { bluetooth(address, strips, started, bluetoothProfile, feasycom); return; }
        if (mode.equals("sunmi")) { sunmi(strips, started); return; }
        if (mode.equals("rovoo")) { rovoo(strips, started); return; }
        if (mode.equals("zcs")) { zcs(strips, started); return; }
        try (PrinterBinding binding = new PrinterBinding(activity)) {
            IBinder binder = binding.connect(PrinterDiscovery.intent(mode), mode.equals("senraise") ? "recieptservice.com.recieptservice.PrinterInterface" : "net.nyx.printerservice.print.IPrinterService");
            if (mode.equals("senraise")) {
                PrinterInterface printer = PrinterInterface.Stub.asInterface(binder);
                printer.getServiceVersion(); started.run();
                for (Bitmap strip : strips) { checkTime(); printer.printBitmap(strip); }
                printer.nextLine(6);
            } else {
                IPrinterService printer = IPrinterService.Stub.asInterface(binder);
                int status = printer.getPrinterStatus();
                if (status != 0) throw new Exception("الطابعة غير جاهزة. رمز الحالة: " + status);
                started.run();
                for (Bitmap strip : strips) {
                    checkTime();
                    int code = printer.printBitmap(strip, 0, 1);
                    if (code != 0) throw new Exception("توقفت الطباعة. رمز الجهاز: " + code);
                }
                int code = printer.paperOut(192);
                if (code != 0) throw new Exception("تعذر إخراج الورقة. رمز الجهاز: " + code);
            }
        }
    }

    private void bluetooth(String address, List<Bitmap> strips, Runnable started, PrinterTransferProfile profile, boolean feasycom) throws Exception {
        if (Build.VERSION.SDK_INT >= 31 && activity.checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED) throw new SecurityException("Bluetooth permission denied");
        BluetoothManager manager = (BluetoothManager) activity.getSystemService(Context.BLUETOOTH_SERVICE);
        BluetoothAdapter adapter = manager == null ? null : manager.getAdapter();
        if (adapter == null || !adapter.isEnabled()) throw new Exception("شغّل البلوتوث أولًا.");
        if (address == null || address.isEmpty()) throw new Exception("اختر الطابعة من إعدادات التطبيق أولًا.");
        BluetoothSocket socket = adapter.getRemoteDevice(address).createRfcommSocketToServiceRecord(UUID.fromString("00001101-0000-1000-8000-00805f9b34fb"));
        ScheduledExecutorService watchdog = Executors.newSingleThreadScheduledExecutor();
        watchdog.schedule(() -> { try { socket.close(); } catch (IOException ignored) {} }, Math.max(1,deadline-SystemClock.elapsedRealtime()), TimeUnit.MILLISECONDS);
        try {
            PrinterDiagnostics.transport(activity,"bluetooth connecting");
            socket.connect(); OutputStream raw = socket.getOutputStream();
            final long[] encodedBytes = {0};
            OutputStream out = !feasycom ? raw : new FilterOutputStream(raw) {
                @Override public void write(int value) throws IOException { this.out.write(value); encodedBytes[0]++; }
                @Override public void write(byte[] bytes, int offset, int count) throws IOException {
                    this.out.write(bytes,offset,count); encodedBytes[0]+=count;
                }
            };
            android.content.SharedPreferences config=activity.getSharedPreferences("printer",Context.MODE_PRIVATE);
            boolean statusSupported=config.getBoolean("status_support",false);
            if(statusSupported)bluetoothStatus(out,socket.getInputStream(),"before dispatch");
            started.run();
            long dispatchStartedAt=SystemClock.elapsedRealtime();
            encodedBytes[0]=0;
            out.write(new byte[]{27, 64});
            int part = 0;
            int rows = 0;
            for (Bitmap strip : strips) {
                checkTime();
                PrinterDiagnostics.transport(activity,"bluetooth part " + (++part) + "/" + strips.size() + " sending");
                int[] pixels = new int[strip.getWidth() * strip.getHeight()]; strip.getPixels(pixels, 0, strip.getWidth(), 0, 0, strip.getWidth(), strip.getHeight());
                boolean columns = activity.getSharedPreferences("printer", Context.MODE_PRIVATE).getString("image_mode", "raster").equals("columns");
                PrinterImageTransfer.write(out, strip.getWidth(), strip.getHeight(), pixels, columns, Thread::sleep, this::checkTime,profile);
                rows+=strip.getHeight();
                PrinterDiagnostics.transport(activity,"bluetooth part " + part + "/" + strips.size() + " socket write completed");
            }
            if(feasycom) {
                BluetoothTailCompletion.Result completion=BluetoothTailCompletion.finish(out,statusSupported?socket.getInputStream():null,rows,encodedBytes[0],dispatchStartedAt,statusSupported,SystemClock::elapsedRealtime,Thread::sleep,this::checkTime);
                checkTime();
                PrinterDiagnostics.status(activity,"Feasycom tail " + completion + "; physical paper confirmation remains manual");
            } else {
                out.write(new byte[]{27,100,6}); out.flush(); Thread.sleep(3000);
                if(statusSupported)bluetoothStatus(out,socket.getInputStream(),"after dispatch; status is not paper completion");
            }
            PrinterDiagnostics.transport(activity,"bluetooth all " + strips.size() + " parts and feed sent; physical completion unconfirmed");
            if(feasycom) {
                checkTime();
                watchdog.shutdownNow();
                checkTime();
            }
        } finally { socket.close(); watchdog.shutdownNow(); }
    }

    private void bluetoothStatus(OutputStream out, InputStream in, String stage) throws Exception {
        for(int n:new int[]{2,4,1}) {
            checkTime();
            Integer value=EscPosStatus.query(out,in,n,800,SystemClock::elapsedRealtime,Thread::sleep);
            PrinterDiagnostics.status(activity,"bluetooth status " + n + " " + (value==null?"unavailable":value) + " " + stage);
            String problem=EscPosStatus.problem(n,value);
            if(problem!=null)throw new Exception(problem);
            // Unsupported status is optional. Do not block or invent a ready response.
            if(value==null)return;
        }
    }

    private void sunmi(List<Bitmap> strips, Runnable started) throws Exception {
        CountDownLatch connected = new CountDownLatch(1); AtomicReference<SunmiPrinterService> service = new AtomicReference<>();
        InnerPrinterCallback callback = new InnerPrinterCallback() {
            protected void onConnected(SunmiPrinterService s) { service.set(s); connected.countDown(); }
            protected void onDisconnected() { service.set(null); }
        };
        Context context = activity.getApplicationContext();
        AtomicReference<Exception> connectionError = new AtomicReference<>();
        activity.runOnUiThread(() -> { try { if (!InnerPrinterManager.getInstance().bindService(context, callback)) connected.countDown(); } catch (Exception failure) { connectionError.set(failure); connected.countDown(); } });
        try {
            boolean ready = connected.await(10, TimeUnit.SECONDS);
            if (connectionError.get() != null) throw connectionError.get();
            if (!ready || service.get() == null) throw new Exception("خدمة SUNMI غير متصلة.");
            SunmiPrinterService printer = service.get();
            if (printer.updatePrinterState() != 1) throw new Exception("طابعة SUNMI غير جاهزة؛ افحص الورق والغطاء.");
            started.run();
            for (Bitmap strip : strips) {
                checkTime();
                CountDownLatch done = new CountDownLatch(1); AtomicReference<String> error = new AtomicReference<>();
                printer.printBitmap(strip, new InnerResultCallback() {
                    public void onRunResult(boolean ok) { if (!ok) error.set("الجهاز لم يقبل جزءًا من الوصل."); done.countDown(); }
                    public void onReturnString(String value) {}
                    public void onRaiseException(int code, String msg) { error.set("خطأ SUNMI: " + code); done.countDown(); }
                    public void onPrintResult(int code, String msg) {}
                });
                if (!awaitResult(done)) throw new Exception("لم يصل تأكيد قبول الطباعة من SUNMI.");
                if (error.get() != null) throw new Exception(error.get());
            }
            CountDownLatch fed = new CountDownLatch(1); AtomicReference<String> feedError = new AtomicReference<>();
            printer.lineWrap(3, new InnerResultCallback() {
                public void onRunResult(boolean ok) { if (!ok) feedError.set("تعذر إخراج الورقة من SUNMI."); fed.countDown(); }
                public void onReturnString(String value) {}
                public void onRaiseException(int code, String msg) { feedError.set("خطأ SUNMI: " + code); fed.countDown(); }
                public void onPrintResult(int code, String msg) {}
            });
            if (!awaitResult(fed)) throw new Exception("لم يصل تأكيد إخراج الورقة من SUNMI.");
            if (feedError.get() != null) throw new Exception(feedError.get());
        } finally { activity.runOnUiThread(() -> { try { InnerPrinterManager.getInstance().unBindService(context, callback); } catch (Exception failure) { PrinterDiagnostics.record(context, "sunmi-unbind", failure); } }); }
    }

    private void rovoo(List<Bitmap> strips, Runnable started) throws Exception {
        try { activity.getPackageManager().getApplicationInfo("com.centerm.cpaysdkq", 0); }
        catch (PackageManager.NameNotFoundException missing) {
            throw new Exception("خدمة ROVOO الأصلية CpaySDKQ غير مثبتة على الجهاز. يحتاج المورّد تثبيت خدمة متوافقة مع توقيع الجهاز؛ اختيار الاسم وحده لا يربط الطابعة.");
        }
        // SDK DevicesFactory binds with the supplied context but releases with its
        // application context. Own the binding directly using the SDK's same protocol.
        try (PrinterBinding binding = new PrinterBinding(activity)) {
            IBinder binder = binding.connect(PrinterDiscovery.intent("rovoo"), "com.pos.sdk.IDeviceManager");
            DeviceManager manager = new DeviceManager(IDeviceManager.Stub.asInterface(binder));
            com.pos.sdk.printer.PrinterDevice printer = manager.getPrinterDevice();
            if (printer == null) throw new Exception("لم يعثر الجهاز على الطابعة الداخلية.");
            com.pos.sdk.printer.PrinterStatus status = printer.getPrinterStatus();
            if (status == null || status.getStateCode() != com.pos.sdk.printer.PrinterStatus.PRINTER_STATE_NORMAL) throw new Exception("طابعة ROVOO غير جاهزة؛ افحص الورق والحرارة. رمز الحالة: " + (status == null ? "غير متاح" : status.getStateCode()));
            if (printer.isLabelMode()) throw new Exception("طابعة ROVOO مضبوطة لطباعة الملصقات؛ اضبطها لوضع الإيصال من إعدادات الجهاز.");
            awaitRovooReady(printer);
            started.run();
            int part = 0;
            for (Bitmap strip : strips) {
                checkTime();
                awaitRovooReady(printer);
                PrinterDiagnostics.transport(activity,"rovoo part " + (++part) + "/" + strips.size() + " sending");
                CountDownLatch done = new CountDownLatch(1); AtomicReference<String> error = new AtomicReference<>();
                printer.printBitmap(strip, new com.pos.sdk.printer.InnerResultCallback.Stub() {
                    public void onRunResult(boolean ok) { if (!ok) error.set("ROVOO لم يقبل الطباعة."); done.countDown(); }
                    public void onReturnString(String value) {}
                    public void onRaiseException(int code, String msg) { error.set("خطأ ROVOO: " + code); done.countDown(); }
                });
                if (!awaitResult(done)) throw new Exception("لم يصل تأكيد قبول الطباعة من ROVOO.");
                if (error.get() != null) throw new Exception(error.get());
                awaitRovooReady(printer);
            }
            CountDownLatch fed = new CountDownLatch(1); AtomicReference<String> feedError = new AtomicReference<>();
            printer.printLines(6, new com.pos.sdk.printer.InnerResultCallback.Stub() {
                public void onRunResult(boolean ok) { if (!ok) feedError.set("تعذر إخراج الورقة من ROVOO."); fed.countDown(); }
                public void onReturnString(String value) {}
                public void onRaiseException(int code, String msg) { feedError.set("خطأ إخراج الورقة: " + code); fed.countDown(); }
            });
            if (!awaitResult(fed)) throw new Exception("لم يصل تأكيد إخراج الورقة من ROVOO.");
            if (feedError.get() != null) throw new Exception(feedError.get());
            awaitRovooReady(printer);
            PrinterDiagnostics.transport(activity,"rovoo " + strips.size() + " parts and feed accepted; device no longer busy");
        }
    }

    private void awaitRovooReady(com.pos.sdk.printer.PrinterDevice printer) throws Exception {
        long until = Math.min(deadline, SystemClock.elapsedRealtime() + 12000);
        while (printer.isPrinterBusy()) {
            checkTime();
            if (SystemClock.elapsedRealtime() >= until) throw new Exception("لم تنتهِ طابعة ROVOO من الجزء السابق؛ تحقق من الورقة قبل إعادة المحاولة.");
            Thread.sleep(100);
        }
        com.pos.sdk.printer.PrinterStatus status = printer.getPrinterStatus();
        if (status == null || status.getStateCode() != com.pos.sdk.printer.PrinterStatus.PRINTER_STATE_NORMAL)
            throw new Exception("توقفت طابعة ROVOO؛ رمز الحالة: " + (status == null ? "غير متاح" : status.getStateCode()));
    }

    private void zcs(List<Bitmap> strips, Runnable started) throws Exception {
        try (PrinterBinding binding = new PrinterBinding(activity)) {
            Messenger printer = new Messenger(binding.connect(PrinterDiscovery.intent("zcs"), "android.os.IMessenger"));
            zcsCommand(printer, 1, null);
            started.run();
            int part = 0;
            for (Bitmap strip : strips) {
                PrinterDiagnostics.transport(activity,"zcs part " + (++part) + "/" + strips.size() + " sending");
                zcsCommand(printer, 2, strip);
            }
            zcsCommand(printer, 3, null);
            PrinterDiagnostics.transport(activity,"zcs " + strips.size() + " parts and feed returned ready");
        }
    }
    private void zcsCommand(Messenger printer, int command, Bitmap strip) throws Exception {
        checkTime();
        CountDownLatch completed = new CountDownLatch(1); AtomicReference<Bundle> response = new AtomicReference<>();
        Messenger reply = new Messenger(new Handler(Looper.getMainLooper(), message -> { response.set(message.getData()); completed.countDown(); return true; }));
        Message message = Message.obtain(null, command); message.replyTo = reply;
        if (strip != null) { Bundle data = new Bundle(); data.putParcelable("strip", strip); message.setData(data); }
        printer.send(message);
        if (!awaitResult(completed)) throw new Exception("لم تصل نتيجة خدمة Z91؛ افتح فحص الجهاز وتحقق من الورقة قبل إعادة المحاولة.");
        Bundle result = response.get();
        if (result == null || result.getInt("code", -1) != 0) PrinterDiagnostics.transport(activity,"zcs command " + command + " code " + (result == null ? -1 : result.getInt("code")));
        if (result == null || result.getInt("code", -1) != 0) throw new Exception("تعذر تشغيل طابعة Z91: " + (result == null ? "غير متصل" : result.getString("detail", "خطأ في SDK")) + "؛ رمز " + (result == null ? -1 : result.getInt("code")));
    }
    private void checkTime() throws Exception {
        if (SystemClock.elapsedRealtime() >= deadline) throw new Exception("انتهت مهلة محاولة الطباعة؛ تحقق من الورقة قبل إعادة المحاولة.");
    }
    private boolean awaitResult(CountDownLatch latch) throws InterruptedException {
        long remaining = Math.max(0, deadline - SystemClock.elapsedRealtime());
        return latch.await(Math.min(15000, remaining), TimeUnit.MILLISECONDS);
    }
}
