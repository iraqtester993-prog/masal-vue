package iq.dananir.pos;

/** A second, freshly authorized Back at the same navigation state closes the app. */
final class DoubleBackExit {
    private static final long WINDOW_MS = 2000;
    private String context;
    private long at;

    boolean press(long now, String nextContext) {
        boolean close = context != null && context.equals(nextContext) && now >= at && now - at < WINDOW_MS;
        if (close) reset();
        else { context = nextContext; at = now; }
        return close;
    }

    void reset() { context = null; at = 0; }
}
