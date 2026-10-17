package iq.dananir.pos;

import org.junit.Test;
import static org.junit.Assert.*;
import java.io.ByteArrayOutputStream;
import java.util.Arrays;
import java.util.Random;

public class FeasycomRasterTest {
    private boolean[] decode(byte[] data, int width, int height) {
        boolean[] pixels = new boolean[width * height];
        int offset = 0, row = 0;
        while (offset < data.length) {
            assertTrue(offset + 8 <= data.length);
            assertEquals(29, data[offset++] & 255); assertEquals(118, data[offset++] & 255);
            assertEquals(48, data[offset++] & 255); assertEquals(0, data[offset++] & 255);
            int bytes = (data[offset++] & 255) | (data[offset++] & 255) << 8;
            int rows = (data[offset++] & 255) | (data[offset++] & 255) << 8;
            assertTrue(bytes > 0 && bytes <= (width + 7) / 8);
            assertTrue(rows > 0 && rows <= 24 && row + rows <= height);
            assertTrue(offset + rows * bytes <= data.length);
            for (int y = 0; y < rows; y++) for (int b = 0; b < bytes; b++) {
                int value = data[offset++] & 255;
                for (int bit = 0; bit < 8; bit++) {
                    int x = b * 8 + bit;
                    if (x < width) pixels[(row + y) * width + x] = (value & (128 >> bit)) != 0;
                }
            }
            row += rows;
        }
        assertEquals(height, row);
        return pixels;
    }

    @Test public void sparseReceiptRetainsBlankRowsEdgePixelsAndLastRowAcrossStrips() throws Exception {
        int width = 384, height = 193;
        int[] pixels = new int[width * height]; Arrays.fill(pixels, 0xffffffff);
        pixels[0] = 0xff000000; pixels[width * 23 + 383] = 0xff000000;
        pixels[width * 25 + 127] = 0xff000000; pixels[width * 192 + 383] = 0xff000000;
        ByteArrayOutputStream out = new ByteArrayOutputStream();
        PrinterImageTransfer.write(out,width,height,pixels,false,ms->{},()->{},PrinterTransferProfile.forPrinter("Feasycom",false));
        assertArrayEquals(decode(EscPos.raster(width,height,pixels),width,height),decode(out.toByteArray(),width,height));
        assertTrue(out.size() < EscPos.raster(width,height,pixels).length / 3);
    }

    @Test public void compactEncodingMatchesRasterThresholdTransparencyAndOddWidths() {
        Random random = new Random(41);
        for (int width : new int[]{1,7,8,9,383,384,576}) {
            int height = 49; int[] pixels = new int[width * height];
            for (int index = 0; index < pixels.length; index++) pixels[index] = random.nextInt();
            assertArrayEquals(decode(EscPos.raster(width,height,pixels),width,height),decode(EscPos.compactRaster(width,height,pixels),width,height));
        }
    }

    @Test public void whitespaceReductionLowersDataAndScheduledWaitWithoutFasterPackets() throws Exception {
        int width = 384, height = 192; int[] pixels = new int[width * height]; Arrays.fill(pixels,0xffffffff);
        for (int y = 8; y < height; y += 32) for (int line = 0; line < 12 && y + line < height; line++)
            Arrays.fill(pixels,(y + line) * width + 80,(y + line) * width + 304,0xff000000);
        ByteArrayOutputStream baseline = new ByteArrayOutputStream(), compact = new ByteArrayOutputStream();
        long[] waits = {0,0};
        PrinterImageTransfer.write(baseline,width,height,pixels,false,ms->waits[0]+=ms,()->{},new PrinterTransferProfile(false));
        PrinterImageTransfer.write(compact,width,height,pixels,false,ms->waits[1]+=ms,()->{},PrinterTransferProfile.forPrinter("Feasycom",false));
        assertArrayEquals(decode(baseline.toByteArray(),width,height),decode(compact.toByteArray(),width,height));
        assertTrue(compact.size() < baseline.size() / 2);
        assertTrue(waits[1] < waits[0]);
        assertEquals(new PrinterTransferProfile(false).packetPause,PrinterTransferProfile.forPrinter("Feasycom",false).packetPause);
        System.out.println("FEASYCOM_SPARSE_TRANSFER baseline_bytes="+baseline.size()+" compact_bytes="+compact.size()+" baseline_wait_ms="+waits[0]+" compact_wait_ms="+waits[1]);
    }

    @Test public void onlyFeasycomRasterChangesAndOtherPrintersKeepOriginalBytesAndPacing() throws Exception {
        int[] pixels = new int[384 * 49]; Arrays.fill(pixels,0xffffffff); pixels[384 * 48 + 383] = 0xff000000;
        for (boolean conservative : new boolean[]{false,true}) {
            for (String type : new String[]{"GOOJPRT","Z51","","other"}) {
                PrinterTransferProfile previous = new PrinterTransferProfile(conservative), profile = PrinterTransferProfile.forPrinter(type,conservative);
                assertFalse(profile.compactRaster); assertEquals(previous.packet,profile.packet);
                assertEquals(previous.packetPause,profile.packetPause); assertEquals(previous.bandPause,profile.bandPause);
                ByteArrayOutputStream out = new ByteArrayOutputStream();
                PrinterImageTransfer.write(out,384,49,pixels,false,ms->{},()->{},profile);
                assertArrayEquals(EscPos.raster(384,49,pixels),out.toByteArray());
            }
            ByteArrayOutputStream before = new ByteArrayOutputStream(), after = new ByteArrayOutputStream();
            PrinterImageTransfer.write(before,384,49,pixels,true,ms->{},()->{},new PrinterTransferProfile(conservative));
            PrinterImageTransfer.write(after,384,49,pixels,true,ms->{},()->{},PrinterTransferProfile.forPrinter("Feasycom",conservative));
            assertArrayEquals(before.toByteArray(),after.toByteArray());
        }
    }

    @Test public void compactTransferNeverRetriesWhenTheConnectionFails() throws Exception {
        int[] writes = {0}; java.io.OutputStream out = new java.io.OutputStream() {
            public void write(int value) { fail("Packet writes required"); }
            public void write(byte[] data,int offset,int count) throws java.io.IOException { writes[0]++; throw new java.io.IOException("Disconnected"); }
        };
        try { PrinterImageTransfer.write(out,384,49,new int[384 * 49],false,ms->{},()->{},PrinterTransferProfile.forPrinter("Feasycom",false)); fail(); }
        catch (java.io.IOException expected) { assertEquals(1,writes[0]); }
    }
}
