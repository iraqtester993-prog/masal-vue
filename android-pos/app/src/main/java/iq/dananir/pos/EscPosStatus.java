package iq.dananir.pos;

import java.io.*;
import java.util.function.LongSupplier;

/** Optional real-time status. A timeout is unavailable, never ready or proof of printing. */
final class EscPosStatus {
    static Integer query(OutputStream out, InputStream in, int n, long timeout,
                         LongSupplier clock, PrinterReadyWait.Pause pause) throws Exception {
        if (n != 1 && n != 2 && n != 4) throw new IllegalArgumentException("Unsupported status");
        // Discard bounded stale responses before issuing one request at a time.
        for (int i=0; i<32 && in.available()>0; i++) in.read();
        out.write(new byte[]{16,4,(byte)n}); out.flush();
        long end=clock.getAsLong()+timeout;
        while(clock.getAsLong()<end) {
            if(in.available()>0) {
                int value=in.read();
                if(value<0)throw new EOFException("Printer disconnected");
                if((value & 0x93)==0x12)return value;
            } else pause.sleep(Math.min(25,Math.max(1,end-clock.getAsLong())));
        }
        return null;
    }
    static String problem(int n, Integer value) {
        if(value==null)return null;
        if(n==1 && (value & 8)!=0)return "الطابعة غير جاهزة أو غير متصلة بوحدة الطباعة.";
        if(n==2) {
            if((value & 4)!=0)return "غطاء الطابعة مفتوح.";
            if((value & 32)!=0)return "توقفت الطابعة بسبب نفاد الورق.";
            if((value & 64)!=0)return "الطابعة تبلغ عن خطأ؛ افحص الورق والحرارة.";
        }
        if(n==4 && (value & 0x60)!=0)return "لا يوجد ورق في الطابعة.";
        return null;
    }
    private EscPosStatus() {}
}
