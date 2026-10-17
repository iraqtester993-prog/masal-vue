package iq.dananir.pos;

import android.graphics.*;
import android.graphics.pdf.PdfDocument;
import android.os.*;
import android.print.*;
import java.io.FileOutputStream;
import java.io.OutputStream;
import java.io.InterruptedIOException;
import java.util.List;

/** The Android system print dialog for an unsupported printer; never dispatches to an SDK. */
final class BrowserReceiptPrinter extends PrintDocumentAdapter {
    private final List<Bitmap> strips;
    private final Runnable finished;
    private boolean closed;
    private final int rows;
    BrowserReceiptPrinter(List<Bitmap> strips, Runnable finished) {
        this.strips = strips; this.finished = finished;
        int total = 0; for (Bitmap strip : strips) total += strip.getHeight(); rows = total;
    }
    PrintAttributes attributes() {
        int heightMils = Math.max(3937, (int)Math.ceil((rows + 24) * 2283.0 / 384));
        return new PrintAttributes.Builder().setMediaSize(new PrintAttributes.MediaSize("DANANEER_58", "58 mm", 2283, heightMils))
            .setResolution(new PrintAttributes.Resolution("thermal", "203 dpi", 203, 203))
            .setMinMargins(PrintAttributes.Margins.NO_MARGINS).setColorMode(PrintAttributes.COLOR_MODE_MONOCHROME).build();
    }
    @Override public void onLayout(PrintAttributes oldAttributes, PrintAttributes newAttributes, CancellationSignal cancellation, LayoutResultCallback callback, Bundle extras) {
        if (cancellation.isCanceled()) { callback.onLayoutCancelled(); return; }
        callback.onLayoutFinished(new PrintDocumentInfo.Builder("dananeer-receipt.pdf").setContentType(PrintDocumentInfo.CONTENT_TYPE_DOCUMENT).setPageCount(1).build(), true);
    }
    @Override public void onWrite(PageRange[] pages, ParcelFileDescriptor destination, CancellationSignal cancellation, WriteResultCallback callback) {
        if (cancellation.isCanceled()) { callback.onWriteCancelled(); return; }
        boolean includes = false; for (PageRange range : pages) if (range.getStart() <= 0 && range.getEnd() >= 0) includes = true;
        if (!includes) { callback.onWriteFinished(new PageRange[0]); return; }
        try (FileOutputStream output = new FileOutputStream(destination.getFileDescriptor())) {
            writePdf(output,cancellation); callback.onWriteFinished(new PageRange[]{new PageRange(0, 0)});
        } catch (InterruptedIOException cancelled) {callback.onWriteCancelled();}
        catch (Exception failure) { callback.onWriteFailed("تعذر تجهيز الوصل للطباعة."); }
    }
    void writePdf(OutputStream output,CancellationSignal cancellation) throws Exception {
        PdfDocument pdf = new PdfDocument();
        try {
            int width = 165, height = Math.max(284, (int)Math.ceil((rows + 24) * width / 384.0));
            PdfDocument.Page page = pdf.startPage(new PdfDocument.PageInfo.Builder(width, height, 1).create());
            Canvas canvas = page.getCanvas(); canvas.drawColor(Color.WHITE); canvas.scale(width / 384f, width / 384f);
            int top = 12;
            for (Bitmap strip : strips) {
                if (cancellation.isCanceled()) { pdf.finishPage(page); throw new InterruptedIOException(); }
                canvas.drawBitmap(strip, 0, top, null); top += strip.getHeight();
            }
            pdf.finishPage(page); pdf.writeTo(output);
        } finally { pdf.close(); }
    }
    @Override public void onFinish() { close(); }
    void close() { if (closed) return; closed = true; for (Bitmap strip : strips) if (!strip.isRecycled()) strip.recycle(); finished.run(); }
}
