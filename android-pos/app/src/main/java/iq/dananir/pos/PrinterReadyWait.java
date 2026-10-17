package iq.dananir.pos;

import java.util.function.IntSupplier;
import java.util.function.LongSupplier;

/** Waits for an existing print to drain; never resends any receipt data. */
final class PrinterReadyWait {
    interface Pause { void sleep(long milliseconds) throws InterruptedException; }
    static int await(IntSupplier status, int printing, long timeout, LongSupplier clock, Pause pause) throws InterruptedException {
        long deadline = clock.getAsLong() + timeout;
        int current = status.getAsInt();
        while (current == printing) {
            long remaining = deadline - clock.getAsLong();
            if (remaining <= 0) return current;
            pause.sleep(Math.min(100, remaining));
            current = status.getAsInt();
        }
        return current;
    }
    private PrinterReadyWait() {}
}
