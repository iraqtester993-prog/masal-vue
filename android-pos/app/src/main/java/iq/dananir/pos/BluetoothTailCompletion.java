package iq.dananir.pos;

import java.io.InputStream;
import java.io.OutputStream;
import java.io.IOException;
import java.util.function.LongSupplier;

/** Feasycom tail protection. A timed drain is a heuristic, never proof of paper completion. */
final class BluetoothTailCompletion {
    static final long MIN_DRAIN_MS = 4000;
    static final long MAX_DRAIN_MS = 60000;
    private static final long MARGIN_MS = 1500;
    // Deliberately conservative estimates, not detected device/serial-port specifications.
    private static final long ROWS_PER_SECOND = 96; // About 12 mm/s at 203 dpi.
    private static final long BYTES_PER_SECOND = 1920;

    enum Result { QUEUED_STATUS_ACKNOWLEDGED, TIMED_DRAIN_UNCONFIRMED }

    static long fallbackWait(int rows, long encodedBytes, long transferElapsedMs) {
        if (rows < 0 || encodedBytes < 0 || transferElapsedMs < 0)
            throw new IllegalArgumentException("Invalid drain estimate");
        long total = Math.max(duration(rows, ROWS_PER_SECOND), duration(encodedBytes, BYTES_PER_SECOND));
        long remaining = Math.max(0, total - Math.min(total, transferElapsedMs));
        return Math.max(MIN_DRAIN_MS, Math.min(MAX_DRAIN_MS, remaining + MARGIN_MS));
    }

    private static long duration(long amount, long perSecond) {
        // Saturate before multiplication; estimates longer than this are capped below anyway.
        long seconds = amount / perSecond;
        if (seconds >= Long.MAX_VALUE / 1000 - 1) return Long.MAX_VALUE - MARGIN_MS;
        return seconds * 1000 + ((amount % perSecond) * 1000 + perSecond - 1) / perSecond;
    }

    static Result finish(OutputStream out, InputStream in, int rows, long encodedBytes,
                         long dispatchStartedAt, boolean queryStatus, LongSupplier clock,
                         PrinterReadyWait.Pause pause, PrinterImageTransfer.Guard guard) throws Exception {
        guard.check();
        out.write(new byte[]{27, 100, 6});
        out.flush();
        guard.check();
        long start = clock.getAsLong();
        long wait = fallbackWait(rows, encodedBytes, Math.max(0, start - dispatchStartedAt));
        long until = start + wait;
        int asbRemaining = 0;
        if (queryStatus) {
            // Discard only already available, bounded stale data before the queued query.
            for (int count = 0; count < 32 && in.available() > 0; count++) {
                guard.check();
                int value = in.read();
                if (value < 0) throw new IOException("Printer disconnected");
                if (asbRemaining > 0) asbRemaining--;
                else if ((value & 0x93) == 0x10) asbRemaining = 3;
            }
            guard.check();
            // Unlike DLE EOT, GS r 1 executes after preceding print/feed commands.
            out.write(new byte[]{29, 114, 1});
            out.flush();
        }
        while (clock.getAsLong() < until) {
            guard.check();
            if (queryStatus) {
                for (int count = 0; count < 32 && in.available() > 0; count++) {
                    guard.check();
                    int value = in.read();
                    if (value < 0) throw new IOException("Printer disconnected");
                    if (asbRemaining > 0) { asbRemaining--; continue; }
                    if ((value & 0x93) == 0x10) { asbRemaining = 3; continue; }
                    // Normal GS r status fixes bits 4/7 low. Ignore real-time DLE EOT/ASB replies.
                    if ((value & 0x90) != 0 || !sensorBits(value & 3) || !sensorBits((value >> 2) & 3)) continue;
                    if ((value & 0x0c) != 0) throw new IOException("لا يوجد ورق في الطابعة.");
                    guard.check();
                    return Result.QUEUED_STATUS_ACKNOWLEDGED;
                }
            }
            long remaining = until - clock.getAsLong();
            if (remaining > 0) pause.sleep(Math.min(50, remaining));
        }
        guard.check();
        return Result.TIMED_DRAIN_UNCONFIRMED;
    }

    private static boolean sensorBits(int value) { return value == 0 || value == 3; }
    private BluetoothTailCompletion() {}
}
