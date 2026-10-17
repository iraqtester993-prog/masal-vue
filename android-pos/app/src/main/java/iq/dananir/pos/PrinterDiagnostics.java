package iq.dananir.pos;

import android.content.*;
import android.os.Build;
import java.util.*;

/** Reports device/SDK failures without receipt contents, credentials, serials or customer data. */
final class PrinterDiagnostics {
    private static boolean installed;
    static String failureSummary(String stage, Throwable error) {
        StringBuilder text = new StringBuilder(stage).append(": ").append(error.getClass().getName());
        for (StackTraceElement frame : error.getStackTrace()) {
            if (text.length() > 1600) break;
            text.append("\n").append(frame.getClassName()).append('.').append(frame.getMethodName()).append(':').append(frame.getLineNumber());
        }
        return text.toString();
    }
    static void record(Context context, String stage, Throwable error) {
        context.getSharedPreferences("printer-diagnostics", Context.MODE_PRIVATE).edit()
            .putString("last_failure", failureSummary(stage, error)).commit();
    }
    static void transport(Context context, String stage) {
        context.getSharedPreferences("printer-diagnostics",Context.MODE_PRIVATE).edit().putString("last_transport",stage).commit();
    }
    static void status(Context context, String value) {
        context.getSharedPreferences("printer-diagnostics",Context.MODE_PRIVATE).edit().putString("last_status",value).commit();
    }
    static synchronized void install(Context context) {
        if (installed) return;
        installed = true;
        Thread.UncaughtExceptionHandler previous = Thread.getDefaultUncaughtExceptionHandler();
        Thread.setDefaultUncaughtExceptionHandler((thread, error) -> {
            try { record(context, "crash/" + thread.getName(), error); } catch (Exception ignored) {}
            if (previous != null) previous.uncaughtException(thread, error);
            else { android.os.Process.killProcess(android.os.Process.myPid()); System.exit(1); }
        });
    }
    static String report(Context context) {
        StringBuilder report = new StringBuilder("دنانير — فحص الطباعة 0.9\n")
            .append("Manufacturer: ").append(Build.MANUFACTURER).append("\nModel: ").append(Build.MODEL)
            .append("\nAndroid: ").append(Build.VERSION.RELEASE).append(" (API ").append(Build.VERSION.SDK_INT).append(")")
            .append("\nCPU: ").append(Arrays.toString(Build.SUPPORTED_ABIS));
        for (String mode : PrinterDiscovery.MODES) report.append("\n").append(PrinterDiscovery.label(mode)).append(": ").append(PrinterDiscovery.available(context, mode) ? (mode.equals("zcs") ? "SDK مدمج لمعالج ARM؛ التهيئة تُفحص قبل الطباعة" : "خدمة موجودة") : "الخدمة غير موجودة/غير متاحة");
        android.content.SharedPreferences configuration=context.getSharedPreferences("printer",Context.MODE_PRIVATE);
        report.append("\nPrint mode: ").append(configuration.getString("mode","unset"))
            .append("\nPaper dots: ").append(configuration.getInt("width",384))
            .append("\nBluetooth images: ").append(configuration.getString("image_mode","raster"));
        report.append("\nTransfer: ").append(configuration.getBoolean("conservative",false)?"conservative":"balanced").append("\nOptional status: ").append(configuration.getBoolean("status_support",false));
        String transport = context.getSharedPreferences("printer-diagnostics", Context.MODE_PRIVATE).getString("last_transport", "");
        String printerStatus = context.getSharedPreferences("printer-diagnostics", Context.MODE_PRIVATE).getString("last_status", "");
        if(!printerStatus.isEmpty())report.append("\nLast status check: ").append(printerStatus);
        if (!transport.isEmpty()) report.append("\nLast transport: ").append(transport);
        String failure = context.getSharedPreferences("printer-diagnostics", Context.MODE_PRIVATE).getString("last_failure", "");
        if (!failure.isEmpty()) report.append("\n\nآخر خطأ:\n").append(failure);
        return report.toString();
    }
}
