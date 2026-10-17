package iq.dananir.pos;

import java.io.ByteArrayOutputStream;

/** Pure encoding: 24-row chunks bound printer buffer usage. */
public final class EscPos {
    public static byte[] raster(int width, int height, int[] pixels) {
        if (width < 1 || width > 576 || height < 1 || pixels.length != width * height) throw new IllegalArgumentException("Invalid raster");
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        int bytes = (width + 7) / 8;
        for (int top = 0; top < height; top += 24) {
            int rows = Math.min(24, height - top);
            out.write(0x1d); out.write(0x76); out.write(0x30); out.write(0);
            out.write(bytes & 255); out.write(bytes >> 8); out.write(rows); out.write(0);
            for (int y = top; y < top + rows; y++) for (int b = 0; b < bytes; b++) {
                int value = 0;
                for (int bit = 0; bit < 8; bit++) {
                    int x = b * 8 + bit;
                    if (x < width) {
                        int p = pixels[y * width + x];
                        int alpha = p >>> 24;
                        int luminance = (((p >> 16) & 255) * 299 + ((p >> 8) & 255) * 587 + (p & 255) * 114) / 1000;
                        if (alpha > 127 && luminance < 160) value |= 128 >> bit;
                    }
                }
                out.write(value);
            }
        }
        return out.toByteArray();
    }
    /** Feasycom: omit white data while retaining every row and horizontal ink position. */
    static byte[] compactRaster(int width, int height, int[] pixels) {
        if (width < 1 || width > 576 || height < 1 || pixels.length != width * height) throw new IllegalArgumentException("Invalid raster");
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        for (int top = 0; top < height; top += 24) {
            int end = Math.min(height, top + 24), first = end, last = top - 1, right = 0;
            for (int y = top; y < end; y++) for (int x = 0; x < width; x++) {
                if (ink(pixels[y * width + x])) {
                    first = Math.min(first, y); last = y; right = Math.max(right, x + 1);
                }
            }
            if (last < top) {
                blankRaster(out, end - top);
                continue;
            }
            if (first > top) blankRaster(out, first - top);
            int bytes = (right + 7) / 8;
            rasterHeader(out, bytes, last - first + 1);
            for (int y = first; y <= last; y++) for (int b = 0; b < bytes; b++) {
                int value = 0;
                for (int bit = 0; bit < 8; bit++) {
                    int x = b * 8 + bit;
                    if (x < width && ink(pixels[y * width + x])) value |= 128 >> bit;
                }
                out.write(value);
            }
            if (last + 1 < end) blankRaster(out, end - last - 1);
        }
        return out.toByteArray();
    }
    private static boolean ink(int pixel) {
        int luminance = (((pixel >> 16) & 255) * 299 + ((pixel >> 8) & 255) * 587 + (pixel & 255) * 114) / 1000;
        return (pixel >>> 24) > 127 && luminance < 160;
    }
    private static void blankRaster(ByteArrayOutputStream out, int rows) {
        rasterHeader(out, 1, rows);
        for (int row = 0; row < rows; row++) out.write(0);
    }
    private static void rasterHeader(ByteArrayOutputStream out, int bytes, int rows) {
        out.write(0x1d); out.write(0x76); out.write(0x30); out.write(0);
        out.write(bytes & 255); out.write(bytes >> 8); out.write(rows); out.write(0);
    }
    private EscPos() {}
    /** ESC * 33 column image mode, for printers that do not implement GS v 0. */
    public static byte[] columnImage(int width, int height, int[] pixels) {
        if (width < 1 || width > 576 || height < 1 || pixels.length != width * height) throw new IllegalArgumentException("Invalid raster");
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        out.write(27); out.write(51); out.write(24);
        for (int top = 0; top < height; top += 24) {
            out.write(27); out.write(42); out.write(33); out.write(width & 255); out.write(width >> 8);
            for (int x = 0; x < width; x++) for (int band = 0; band < 3; band++) {
                int value = 0;
                for (int bit = 0; bit < 8; bit++) {
                    int y = top + band * 8 + bit;
                    if (y >= height) continue;
                    int pixel = pixels[y * width + x];
                    int luminance = (((pixel >> 16) & 255) * 299 + ((pixel >> 8) & 255) * 587 + (pixel & 255) * 114) / 1000;
                    if ((pixel >>> 24) > 127 && luminance < 160) value |= 128 >> bit;
                }
                out.write(value);
            }
            out.write(10);
        }
        out.write(27); out.write(50);
        return out.toByteArray();
    }
}
