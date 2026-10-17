package iq.dananir.pos;
import java.util.concurrent.atomic.AtomicLong;
import java.util.concurrent.atomic.AtomicInteger;
import org.junit.Test;
import static org.junit.Assert.*;

public class PrinterReadyWaitTest {
    @Test public void waitsForBusyPrinterWithoutResending() throws Exception {
        AtomicLong clock=new AtomicLong(); AtomicInteger reads=new AtomicInteger();
        assertEquals(0,PrinterReadyWait.await(()->reads.incrementAndGet()<4 ? -1406:0,-1406,1000,clock::get,clock::addAndGet));
        assertEquals(4,reads.get()); assertEquals(300,clock.get());
    }
    @Test public void terminalPaperErrorStopsPolling() throws Exception {
        AtomicLong clock=new AtomicLong(); AtomicInteger reads=new AtomicInteger();
        assertEquals(-1403,PrinterReadyWait.await(()->reads.incrementAndGet()==1 ? -1406:-1403,-1406,1000,clock::get,clock::addAndGet));
        assertEquals(2,reads.get());
    }
    @Test public void timeoutRemainsBusyAndDoesNotClaimSuccess() throws Exception {
        AtomicLong clock=new AtomicLong();
        assertEquals(-1406,PrinterReadyWait.await(()->-1406,-1406,250,clock::get,clock::addAndGet));
        assertEquals(250,clock.get());
    }
    @Test public void readyDoesNotSleep() throws Exception {
        assertEquals(0,PrinterReadyWait.await(()->0,-1406,1000,()->0,ms->{throw new AssertionError("Already ready");}));
    }
}
