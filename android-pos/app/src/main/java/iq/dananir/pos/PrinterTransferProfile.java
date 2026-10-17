package iq.dananir.pos;

final class PrinterTransferProfile {
    final int packet; final long packetPause, bandPause;
    final boolean compactRaster;
    PrinterTransferProfile(boolean conservative) {
        this(conservative, false);
    }
    private PrinterTransferProfile(boolean conservative, boolean compactRaster) {
        packet=conservative?128:256; packetPause=conservative?40:15; bandPause=conservative?120:60;
        this.compactRaster=compactRaster;
    }
    static PrinterTransferProfile forPrinter(String printerType, boolean conservative) {
        return new PrinterTransferProfile(conservative, "Feasycom".equals(printerType));
    }
    long timeout(int width, int rows, boolean columns) {
        long bands=(rows+23L)/24;
        long commandBytes=columns ? width*3L+12 : ((width+7L)/8)*24+8;
        long paced=bands*((commandBytes+packet-1)/packet*packetPause+bandPause);
        return Math.min(420000,Math.max(180000,paced+30000));
    }
}
