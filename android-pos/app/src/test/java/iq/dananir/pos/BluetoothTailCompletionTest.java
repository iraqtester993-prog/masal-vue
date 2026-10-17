package iq.dananir.pos;

import org.junit.Test;
import static org.junit.Assert.*;
import java.io.*;
import java.util.*;
import java.util.concurrent.atomic.AtomicLong;

public class BluetoothTailCompletionTest {
    private static final class Fixture {
        final AtomicLong time = new AtomicLong();
        final Queue<Integer> replies = new ArrayDeque<>();
        final ByteArrayOutputStream sent = new ByteArrayOutputStream();
        long replyAt = Long.MAX_VALUE;
        int[] reply = {0};
        boolean queried, delivered;
        final InputStream in = new InputStream() {
            public int available() {
                if (queried && !delivered && time.get() >= replyAt) {
                    for (int value : reply) replies.add(value);
                    delivered = true;
                }
                return replies.size();
            }
            public int read() { return replies.remove(); }
        };
        final OutputStream out = new OutputStream() {
            public void write(int value) { sent.write(value); }
            public void write(byte[] bytes) throws IOException {
                sent.write(bytes);
                if (Arrays.equals(bytes, new byte[]{29,114,1})) queried = true;
            }
        };
        BluetoothTailCompletion.Result finish(boolean status, int rows, long bytes) throws Exception {
            return BluetoothTailCompletion.finish(out,in,rows,bytes,0,status,time::get,time::addAndGet,()->{});
        }
    }

    @Test public void delayedQueuedReplyKeepsConnectionPastOldThreeSecondCutoffAndFeedsOnlyOnce() throws Exception {
        Fixture f=new Fixture();f.replyAt=4500;
        assertEquals(BluetoothTailCompletion.Result.QUEUED_STATUS_ACKNOWLEDGED,f.finish(true,960,1000));
        assertEquals(4500,f.time.get());
        assertArrayEquals(new byte[]{27,100,6,29,114,1},f.sent.toByteArray());
    }

    @Test public void supportedQuickReplyAvoidsTheOldFixedThreeSecondWait() throws Exception {
        Fixture f=new Fixture();f.replyAt=100;
        assertEquals(BluetoothTailCompletion.Result.QUEUED_STATUS_ACKNOWLEDGED,f.finish(true,960,1000));
        assertEquals(100,f.time.get());
    }

    @Test public void realtimeAndAutomaticStatusBytesCannotAcknowledgeQueuedCompletion() throws Exception {
        Fixture f=new Fixture();f.replyAt=100;f.reply=new int[]{0x12,0x10,0,0,0};
        assertEquals(BluetoothTailCompletion.Result.TIMED_DRAIN_UNCONFIRMED,f.finish(true,24,1000));
        assertEquals(BluetoothTailCompletion.MIN_DRAIN_MS,f.time.get());
        Fixture partial=new Fixture();partial.replies.add(0x10);partial.replyAt=100;partial.reply=new int[]{0,0,0};
        assertEquals(BluetoothTailCompletion.Result.TIMED_DRAIN_UNCONFIRMED,partial.finish(true,24,1000));
    }

    @Test public void silentUnsupportedStatusUsesBoundedAdaptiveDrainWithoutClaimingCompletion() throws Exception {
        Fixture f=new Fixture();
        assertEquals(BluetoothTailCompletion.Result.TIMED_DRAIN_UNCONFIRMED,f.finish(true,960,1000));
        assertEquals(11500,f.time.get());
        assertArrayEquals(new byte[]{27,100,6,29,114,1},f.sent.toByteArray());
        assertEquals(60000,BluetoothTailCompletion.fallbackWait(12000,576000,0));
        assertEquals(4000,BluetoothTailCompletion.fallbackWait(960,1000,20000));
        assertEquals(11500,BluetoothTailCompletion.fallbackWait(24,19200,0));
    }

    @Test public void disabledStatusPreservesUserChoiceAndNeverReadsOrSendsAQuery() throws Exception {
        Fixture f=new Fixture();
        assertEquals(BluetoothTailCompletion.Result.TIMED_DRAIN_UNCONFIRMED,
            BluetoothTailCompletion.finish(f.out,null,24,1000,0,false,f.time::get,f.time::addAndGet,()->{}));
        assertEquals(4000,f.time.get());assertArrayEquals(new byte[]{27,100,6},f.sent.toByteArray());
    }

    @Test public void cancellationAndDeadlineFailuresPropagateWithoutRetryOrSuccess() throws Exception {
        for(boolean status:new boolean[]{false,true}) {
            Fixture f=new Fixture();
            try {
                BluetoothTailCompletion.finish(f.out,f.in,960,1000,0,status,f.time::get,f.time::addAndGet,()->{
                    if(f.time.get()>=3250)throw new IOException("deadline");
                });fail("Deadline must stop the attempt");
            }catch(IOException expected){assertEquals("deadline",expected.getMessage());}
            assertEquals(3250,f.time.get());
            assertArrayEquals(status?new byte[]{27,100,6,29,114,1}:new byte[]{27,100,6},f.sent.toByteArray());
        }
        Fixture f=new Fixture();
        try {
            BluetoothTailCompletion.finish(f.out,null,24,1000,0,false,f.time::get,f.time::addAndGet,()->{
                if(f.time.get()>=4000)throw new IOException("closed at drain boundary");
            });fail("Final guard must reject an expired attempt");
        }catch(IOException expected){assertEquals("closed at drain boundary",expected.getMessage());}
    }

    @Test public void paperEndAndTransportFailureDoNotBecomeSuccessfulCompletion() throws Exception {
        Fixture f=new Fixture();f.replyAt=100;f.reply=new int[]{0x0c};
        try{f.finish(true,24,1000);fail("Paper end must fail");}
        catch(IOException expected){assertTrue(expected.getMessage().contains("ورق"));}
        int[] writes={0};
        OutputStream broken=new OutputStream(){public void write(int value)throws IOException{writes[0]++;throw new IOException("disconnected");}};
        try{
            BluetoothTailCompletion.finish(broken,null,24,1000,0,false,()->0,ms->{},()->{});fail("Broken feed must fail");
        }catch(IOException expected){assertEquals("disconnected",expected.getMessage());}
        assertEquals(1,writes[0]);
    }
}
