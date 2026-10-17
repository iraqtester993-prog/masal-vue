package iq.dananir.pos;

/** Preflight arithmetic only. No bitmap is allocated before these limits pass. */
final class ReceiptBounds {
    static final int MAX_ROWS = 12000;
    static int[] image(int sourceWidth, int sourceHeight, int paperWidth, double fraction, int written) {
        if (sourceWidth < 1 || sourceWidth > 576 || sourceHeight < 1 || sourceHeight > 1200 || paperWidth != 384)
            throw new IllegalArgumentException("قياس صورة الوصل غير صالح.");
        if (!Double.isFinite(fraction) || fraction < 0.05 || fraction > 1) fraction = 1;
        int width = Math.max(1, Math.min(paperWidth - 24, (int)Math.round((paperWidth - 24) * fraction)));
        long height = Math.max(1L, (long)sourceHeight * width / sourceWidth);
        // Retain the image's aspect ratio, but never enlarge a narrow image past
        // the maximum source height. A tiny logo must not become a huge bitmap.
        if (height > 1200) {
            height = 1200;
            width = Math.max(1, (int)((long)sourceWidth * height / sourceHeight));
        }
        rows((int)height + 12, written);
        return new int[]{width, (int)height};
    }
    static int rows(int height, int written) {
        if (height < 1 || written < 0 || (long)height + written > MAX_ROWS)
            throw new IllegalArgumentException("الوصل طويل جدًا؛ قسّم البطاقات إلى عمليات أصغر.");
        return height;
    }
}
