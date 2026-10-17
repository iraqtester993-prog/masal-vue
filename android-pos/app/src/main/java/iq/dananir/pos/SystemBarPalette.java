package iq.dananir.pos;

/** Opaque, independently resolved system surfaces. Kept Android-free for contrast regressions. */
final class SystemBarPalette {
    static final int LEGACY_DARK = 0xff101c2e;
    static final int LEGACY_LIGHT = 0xfff3f8fb;
    final int statusBarColor;
    final int navigationBarColor;
    final int backgroundColor;

    private SystemBarPalette(int status, int navigation, int background) {
        statusBarColor = status; navigationBarColor = navigation; backgroundColor = background;
    }

    static SystemBarPalette resolve(boolean darkTheme, String legacy, String status, String navigation, String background) {
        int fallback = color(legacy, darkTheme ? LEGACY_DARK : LEGACY_LIGHT);
        return new SystemBarPalette(color(status, fallback), color(navigation, fallback), color(background, fallback));
    }

    static SystemBarPalette initial() { return new SystemBarPalette(0xfff5f8fb, 0xfff5f8fb, 0xfff5f8fb); }

    SystemBarPalette forSdk(int sdk) {
        // API 24/25 have permanently light navigation icons. Keep that surface readable.
        return sdk < 26 && darkIcons(navigationBarColor)
            ? new SystemBarPalette(statusBarColor, LEGACY_DARK, backgroundColor) : this;
    }

    boolean statusDarkIcons() { return darkIcons(statusBarColor); }
    boolean navigationDarkIcons() { return darkIcons(navigationBarColor); }

    private static int color(String value, int fallback) {
        return value != null && value.matches("#[0-9a-fA-F]{6}")
            ? (int) (0xff000000L | Long.parseLong(value.substring(1), 16)) : fallback;
    }

    static boolean darkIcons(int color) {
        double luminance = .2126 * linear((color >> 16) & 255)
            + .7152 * linear((color >> 8) & 255) + .0722 * linear(color & 255);
        return luminance >= .179;
    }

    private static double linear(int channel) {
        double value = channel / 255.0;
        return value <= .04045 ? value / 12.92 : Math.pow((value + .055) / 1.055, 2.4);
    }
}
