package iq.dananir.pos;

import android.content.*;
import android.content.pm.*;
import java.util.*;

final class PrinterDiscovery {
    static final String[] MODES = {"sunmi", "rovoo", "nyx", "incar", "senraise", "zcs"};
    static String label(String mode) {
        switch (mode) {
            case "sunmi": return "SUNMI";
            case "rovoo": return "ROVOO / Cpay Q";
            case "nyx": return "B1008 / NYX";
            case "incar": return "1008 / Incar";
            case "senraise": return "Senraise / البصرة";
            case "zcs": return "Z91 / ZCS";
            default: return "بلوتوث — طابعة خارجية";
        }
    }
    static Intent intent(String mode) {
        switch (mode) {
            case "rovoo": return new Intent("com.pos.sdk.device").setClassName("com.centerm.cpaysdkq", "com.pos.sdk.CpaySdkQService");
            case "sunmi": return new Intent("woyou.aidlservice.jiuiv5.IWoyouService").setPackage("woyou.aidlservice.jiuiv5");
            case "nyx": return new Intent("net.nyx.printerservice.IPrinterService").setPackage("net.nyx.printerservice");
            case "incar": return new Intent("com.incar.printerservice.IPrinterService").setPackage("com.incar.printerservice");
            case "senraise": return new Intent().setClassName("recieptservice.com.recieptservice", "recieptservice.com.recieptservice.service.PrinterService");
            case "zcs": return new Intent().setClassName("iq.dananir.pos", "iq.dananir.pos.ZcsPrintService");
            default: throw new IllegalArgumentException("نوع الطابعة غير مدعوم.");
        }
    }
    static boolean available(Context context, String mode) {
        if (mode.equals("zcs")) return supportsZcsCpu();
        try {
            ServiceInfo service;
            Intent intent = intent(mode);
            if (intent.getComponent() != null) service = context.getPackageManager().getServiceInfo(intent.getComponent(), 0);
            else {
                ResolveInfo match = context.getPackageManager().resolveService(intent, 0);
                if (match == null) return false;
                service = match.serviceInfo;
            }
            return service != null && service.enabled && service.applicationInfo.enabled && service.exported
                && (service.permission == null || context.checkSelfPermission(service.permission) == PackageManager.PERMISSION_GRANTED);
        } catch (Exception missing) { return false; }
    }
    static List<String> detected(Context context) {
        List<String> modes = new ArrayList<>();
        for (String mode : MODES) if (available(context, mode) && (!mode.equals("zcs") || android.os.Build.MODEL.toUpperCase(Locale.ROOT).contains("Z91"))) modes.add(mode);
        return modes;
    }
    static List<String> selectable(Context context) {
        List<String> modes = new ArrayList<>(); modes.add("bluetooth");
        for (String mode : MODES) if (!mode.equals("zcs") || supportsZcsCpu()) modes.add(mode);
        return modes;
    }
    static boolean supportsZcsCpu() {
        for (String abi : android.os.Build.SUPPORTED_ABIS) if (abi.equals("arm64-v8a") || abi.equals("armeabi-v7a")) return true;
        return false;
    }
}
