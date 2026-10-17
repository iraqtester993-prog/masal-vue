package iq.dananir.pos;

import org.junit.Test;
import static org.junit.Assert.*;
import java.io.*;
import java.util.*;

public class PrinterImageTransferTest {
    @Test public void allRasterRowsIncludingTailAreSentExactlyOnce() throws Exception {
        int[] pixels = new int[384*193]; Arrays.fill(pixels, 0xff000000);
        ByteArrayOutputStream out = new ByteArrayOutputStream(); List<Long> waits = new ArrayList<>();
        PrinterImageTransfer.write(out,384,193,pixels,false,waits::add,()->{});
        assertArrayEquals(EscPos.raster(384,193,pixels),out.toByteArray());
        assertEquals(9,waits.stream().filter(v->v==60).count());
    }
    @Test public void columnsRetainEveryBandAndFinalRow() throws Exception {
        int[] pixels = new int[384*25]; Arrays.fill(pixels, 0xffffffff); pixels[384*24+383]=0xff000000;
        ByteArrayOutputStream out = new ByteArrayOutputStream(), expected = new ByteArrayOutputStream();
        expected.write(EscPos.columnImage(384,24,Arrays.copyOfRange(pixels,0,384*24)));
        expected.write(EscPos.columnImage(384,1,Arrays.copyOfRange(pixels,384*24,pixels.length)));
        PrinterImageTransfer.write(out,384,25,pixels,true,ms->{},()->{});
        assertArrayEquals(expected.toByteArray(),out.toByteArray());
    }
    @Test public void brokenConnectionStopsWithoutRetryOrFollowingBand() throws Exception {
        int[] calls={0};
        OutputStream out = new OutputStream() {
            public void write(int b) { fail("packet writes required"); }
            public void write(byte[] b,int off,int len) throws IOException { assertTrue(len<=256); if(++calls[0]==2)throw new IOException("disconnect"); }
        };
        try { PrinterImageTransfer.write(out,384,48,new int[384*48],false,ms->{},()->{}); fail("must stop"); }
        catch(IOException expected) { assertEquals(2,calls[0]); }
    }
    @Test public void yhd80mmTransferKeepsTheLastDotAfterThe192RowBoundary() throws Exception {
        int[] pixels=new int[576*193]; Arrays.fill(pixels,0xffffffff);
        pixels[0]=0xff000000; pixels[pixels.length-1]=0xff000000;
        PrinterTransferProfile profile=PrinterTransferProfile.forPrinter("YHD-8006",false);
        assertFalse(profile.compactRaster);
        assertEquals(256,profile.packet); assertEquals(15,profile.packetPause); assertEquals(60,profile.bandPause);
        for (boolean columns : new boolean[]{false,true}) {
            ByteArrayOutputStream out=new ByteArrayOutputStream() {
                @Override public synchronized void write(byte[] bytes,int offset,int length) {
                    assertTrue(length<=256); super.write(bytes,offset,length);
                }
            };
            List<Long> waits=new ArrayList<>();
            PrinterImageTransfer.write(out,576,193,pixels,columns,waits::add,()->{},profile);
            byte[] actual=out.toByteArray();
            assertEquals(9,waits.stream().filter(v->v==60).count());
            if (columns) {
                ByteArrayOutputStream expected=new ByteArrayOutputStream();
                for (int top=0;top<193;top+=24) expected.write(EscPos.columnImage(576,Math.min(24,193-top),Arrays.copyOfRange(pixels,top*576,Math.min(193,top+24)*576)));
                assertArrayEquals(expected.toByteArray(),actual);
                assertArrayEquals(new byte[]{(byte)128,0,0,10,27,50},Arrays.copyOfRange(actual,actual.length-6,actual.length));
            } else {
                assertArrayEquals(EscPos.raster(576,193,pixels),actual);
                assertEquals(1,actual[actual.length-1]);
            }
        }
    }
}
