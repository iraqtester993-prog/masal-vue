package iq.dananir.pos;
import org.junit.Test;
import static org.junit.Assert.*;
public class EscPosTest {
    @Test public void blackAndWhiteRaster() {
        assertArrayEquals(new byte[]{29,118,48,0,1,0,1,0,(byte)128}, EscPos.raster(2,1,new int[]{0xff000000,0xffffffff}));
    }
    @Test public void transparentPixelsStayWhiteAndPartialBytesArePadded() {
        assertEquals(0, EscPos.raster(1,1,new int[]{0x00000000})[8]);
        assertEquals((byte)128, EscPos.raster(1,1,new int[]{0xff000000})[8]);
    }
    @Test public void longImagesAreSplitInto24RowCommands() {
        byte[] output=EscPos.raster(1,25,new int[25]);
        assertEquals(41,output.length); assertEquals(24,output[6]); assertEquals(1,output[38]);
    }
    @Test(expected=IllegalArgumentException.class) public void rejectInvalidDimensions() { EscPos.raster(577,1,new int[577]); }
    @Test public void alternateImagePreservesColumnAndVerticalBitOrder() {
        int[] pixels=new int[48]; java.util.Arrays.fill(pixels,0xffffffff);
        pixels[0]=0xff000000; pixels[16]=0xff000000; pixels[47]=0xff000000;
        byte[] output=EscPos.columnImage(2,24,pixels);
        assertArrayEquals(new byte[]{27,51,24,27,42,33,2,0,(byte)128,(byte)128,0,0,0,1,10,27,50},output);
    }
    @Test public void alternateLongImageKeepsLastRowAndPadsBottomWhite() {
        int[] pixels=new int[25]; pixels[24]=0xff000000;
        byte[] output=EscPos.columnImage(1,25,pixels);
        assertEquals(23,output.length);
        assertEquals((byte)128,output[17]); assertEquals(0,output[18]); assertEquals(0,output[19]);
        assertEquals(10,output[20]); assertEquals(27,output[21]); assertEquals(50,output[22]);
    }
    @Test public void full80mmRasterKeepsThe576thColumnAnd25thRow() {
        int[] pixels=new int[576*25]; java.util.Arrays.fill(pixels,0xffffffff);
        pixels[0]=0xff000000; pixels[pixels.length-1]=0xff000000;
        byte[] output=EscPos.raster(576,25,pixels);
        assertEquals(1816,output.length);
        assertArrayEquals(new byte[]{29,118,48,0,72,0,24,0},java.util.Arrays.copyOfRange(output,0,8));
        assertEquals((byte)128,output[8]);
        assertArrayEquals(new byte[]{29,118,48,0,72,0,1,0},java.util.Arrays.copyOfRange(output,1736,1744));
        assertEquals(1,output[1815]);
    }
    @Test public void full80mmColumnsKeepThe576thColumnAndPadTheLastBandWhite() {
        int[] pixels=new int[576*25]; java.util.Arrays.fill(pixels,0xffffffff);
        pixels[0]=0xff000000; pixels[pixels.length-1]=0xff000000;
        byte[] output=EscPos.columnImage(576,25,pixels);
        assertEquals(3473,output.length);
        assertArrayEquals(new byte[]{27,51,24,27,42,33,64,2},java.util.Arrays.copyOfRange(output,0,8));
        assertEquals((byte)128,output[8]);
        assertArrayEquals(new byte[]{27,42,33,64,2},java.util.Arrays.copyOfRange(output,1737,1742));
        assertArrayEquals(new byte[]{(byte)128,0,0,10,27,50},java.util.Arrays.copyOfRange(output,3467,3473));
    }
}
