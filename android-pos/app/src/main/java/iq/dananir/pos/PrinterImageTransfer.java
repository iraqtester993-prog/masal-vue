package iq.dananir.pos;

import java.io.OutputStream;
import java.util.Arrays;

/** Pace each complete image command, not just socket packets. Never retry a partial write. */
final class PrinterImageTransfer {
    interface Guard { void check() throws Exception; }
    static void write(OutputStream out, int width, int height, int[] pixels, boolean columns,
                      PrinterReadyWait.Pause pause, Guard guard) throws Exception {
        write(out,width,height,pixels,columns,pause,guard,new PrinterTransferProfile(false));
    }
    static void write(OutputStream out, int width, int height, int[] pixels, boolean columns,
                      PrinterReadyWait.Pause pause, Guard guard, PrinterTransferProfile profile) throws Exception {
        if (width < 1 || width > 576 || height < 1 || pixels.length != width * height)
            throw new IllegalArgumentException("Invalid image");
        for (int top = 0; top < height; top += 24) {
            guard.check();
            int rows = Math.min(24, height-top);
            int[] band = Arrays.copyOfRange(pixels, top*width, (top+rows)*width);
            byte[] encoded = columns ? EscPos.columnImage(width, rows, band)
                : profile.compactRaster ? EscPos.compactRaster(width, rows, band) : EscPos.raster(width, rows, band);
            for (int offset = 0; offset < encoded.length; offset += profile.packet) {
                guard.check();
                out.write(encoded, offset, Math.min(profile.packet, encoded.length-offset));
                out.flush(); pause.sleep(profile.packetPause);
            }
            // Socket completion is not paper completion. Leave time for portable firmware to drain.
            pause.sleep(profile.bandPause);
        }
    }
    private PrinterImageTransfer() {}
}
