package iq.dananir.pos;
import org.junit.Test;
import static org.junit.Assert.*;
import java.io.ByteArrayOutputStream;
import java.util.*;

public class PrinterTransferProfileTest {
    @Test public void standardPacingIsFasterWhilePreservingIdenticalReceiptBytes() throws Exception {
        int[] pixels=new int[384*241]; java.util.Arrays.fill(pixels,0xff000000);
        java.io.ByteArrayOutputStream normal=new java.io.ByteArrayOutputStream(),safe=new java.io.ByteArrayOutputStream();
        long[] waits={0,0};
        PrinterImageTransfer.write(normal,384,241,pixels,false,ms->waits[0]+=ms,()->{},new PrinterTransferProfile(false));
        PrinterImageTransfer.write(safe,384,241,pixels,false,ms->waits[1]+=ms,()->{},new PrinterTransferProfile(true));
        assertArrayEquals(safe.toByteArray(),normal.toByteArray());
        assertTrue(waits[0]<waits[1]);
    }

    @Test public void conservativeTransferKeepsEveryByteAndFitsDeadlineForMaximumReceipt() throws Exception {
        for(boolean columns:new boolean[]{false,true}) {
            int[] pixels=new int[576*12000]; Arrays.fill(pixels,0xff000000);
            long[] waits={0}; ByteArrayOutputStream out=new ByteArrayOutputStream();
            PrinterTransferProfile profile=new PrinterTransferProfile(true);
            PrinterImageTransfer.write(out,576,12000,pixels,columns,ms->waits[0]+=ms,()->{},profile);
            if(!columns)assertArrayEquals(EscPos.raster(576,12000,pixels),out.toByteArray());
            assertTrue(profile.timeout(576,12000,columns)>waits[0]+15000);
            assertTrue(profile.timeout(576,12000,columns)<=420000);
        }
    }
    @Test public void balancedAndConservativeProduceIdenticalRasterBytes() throws Exception {
        int[] pixels=new int[384*31]; Arrays.fill(pixels,0xff000000);
        ByteArrayOutputStream a=new ByteArrayOutputStream(),b=new ByteArrayOutputStream();
        PrinterImageTransfer.write(a,384,31,pixels,false,ms->{},()->{},new PrinterTransferProfile(false));
        PrinterImageTransfer.write(b,384,31,pixels,false,ms->{},()->{},new PrinterTransferProfile(true));
        assertArrayEquals(a.toByteArray(),b.toByteArray());
    }
}
