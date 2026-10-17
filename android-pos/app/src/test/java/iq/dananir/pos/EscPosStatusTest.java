package iq.dananir.pos;

import org.junit.Test;
import static org.junit.Assert.*;
import java.io.*;
import java.util.*;
import java.util.concurrent.atomic.AtomicLong;

public class EscPosStatusTest {
    @Test public void paperAndCoverErrorsAreDistinguishedFromOffline() {
        assertEquals("لا يوجد ورق في الطابعة.",EscPosStatus.problem(4,0x72));
        assertEquals("غطاء الطابعة مفتوح.",EscPosStatus.problem(2,0x16));
        assertNotNull(EscPosStatus.problem(1,0x1a));
        assertNull(EscPosStatus.problem(4,0x1e)); // near-end is a warning, not paper absent
        assertNull(EscPosStatus.problem(1,0x12));
        assertNull(EscPosStatus.problem(1,null));
    }
    @Test public void unsupportedPrinterDoesNotBlockOrInventReady() throws Exception {
        AtomicLong clock=new AtomicLong(); ByteArrayOutputStream out=new ByteArrayOutputStream();
        InputStream silent=new InputStream(){ public int read(){fail("No blocking read without available data");return -1;} };
        assertNull(EscPosStatus.query(out,silent,4,800,clock::get,clock::addAndGet));
        assertEquals(800,clock.get()); assertArrayEquals(new byte[]{16,4,4},out.toByteArray());
    }
    @Test public void staleAndInvalidBytesCannotBecomeReady() throws Exception {
        Queue<Integer> queue=new ArrayDeque<>(); queue.add(0x72);
        InputStream in=new InputStream(){public int available(){return queue.size();}public int read(){return queue.remove();}};
        OutputStream out=new OutputStream(){public void write(int value){} public void write(byte[] bytes){queue.add(0);queue.add(0x12);}};
        AtomicLong clock=new AtomicLong();
        assertEquals(Integer.valueOf(0x12),EscPosStatus.query(out,in,1,800,clock::get,clock::addAndGet));
        assertTrue(queue.isEmpty());
    }
    @Test public void brokenSocketIsNotClassifiedAsUnsupportedOrRetried() throws Exception {
        int[] writes={0}; OutputStream out=new OutputStream(){public void write(int v)throws IOException{writes[0]++;throw new IOException("disconnect");}};
        try{EscPosStatus.query(out,new ByteArrayInputStream(new byte[0]),1,800,()->0,ms->{});fail("disconnect must propagate");}
        catch(IOException expected){assertEquals(1,writes[0]);}
    }
}
