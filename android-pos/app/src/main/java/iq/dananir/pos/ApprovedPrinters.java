package iq.dananir.pos;

import java.text.Normalizer;
import java.util.Locale;
import java.util.regex.Pattern;

/** Compatibility profiles, not an authorization lock on a physical printer. */
final class ApprovedPrinters {
    static final String[] BLUETOOTH_TYPES = {"GOOJPRT", "Feasycom", "Z51", "YHD-8006"};
    private static final Pattern MTP_2 = Pattern.compile("(?<![A-Z0-9])MTP[-_\\s]*(?:2|II)(?![A-Z0-9])");
    private static final Pattern YHD_8006 = Pattern.compile("(?<![A-Z0-9])YHD[-_\\s]*8006(?![A-Z0-9])");
    static String internal(String manufacturer, String model) {
        String identity = normalize(manufacturer + " " + model);
        if (identity.contains("sunmi")) return "sunmi";
        if (identity.contains("rovoo") || identity.contains("mthdm1")) return "rovoo";
        if (identity.contains("z91")) return "zcs";
        return null;
    }
    static String bluetooth(String name) {
        String identity = normalize(name);
        if (YHD_8006.matcher(normalizedText(name)).find()) return "YHD-8006";
        if (identity.contains("goojprt") || identity.contains("jingpu") || MTP_2.matcher(normalizedText(name)).find()) return "GOOJPRT";
        if (identity.contains("feasycom")) return "Feasycom";
        if (identity.contains("z51")) return "Z51";
        return null;
    }
    static boolean approved(String type) {
        for (String allowed : BLUETOOTH_TYPES) if (allowed.equals(type)) return true;
        return false;
    }
    static int receiptWidthPixels(String type) { return "YHD-8006".equals(type) ? 576 : 384; }
    static int paperWidthMm(String type) { return "YHD-8006".equals(type) ? 80 : 58; }
    static boolean matches(String name, String patterns) {
        String normalized = normalize(name);
        for (String pattern : (patterns == null ? "" : patterns).split("\\|")) {
            String needle = normalize(pattern);
            if (needle.equals("mtp2") || needle.equals("mtpii")) {
                if (MTP_2.matcher(normalizedText(name)).find()) return true;
                continue;
            }
            if (needle.equals("yhd8006")) {
                if (YHD_8006.matcher(normalizedText(name)).find()) return true;
                continue;
            }
            if (needle.length() >= 3 && normalized.contains(needle)) return true;
        }
        return false;
    }
    private static String normalizedText(String text) { return Normalizer.normalize(text == null ? "" : text, Normalizer.Form.NFKC).toUpperCase(Locale.ROOT); }
    private static String normalize(String text) { return normalizedText(text).toLowerCase(Locale.ROOT).replaceAll("[^a-z0-9]", ""); }
}
