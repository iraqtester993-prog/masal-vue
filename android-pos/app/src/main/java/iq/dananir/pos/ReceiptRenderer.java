package iq.dananir.pos;

import android.content.Context;
import android.graphics.*;
import android.text.*;
import android.util.Base64;
import org.json.*;
import java.util.*;

final class ReceiptRenderer {
    static List<Bitmap> render(Context context, JSONArray blocks, int width) throws Exception {
        if (blocks.length() < 1 || blocks.length() > 180) throw new Exception("محتوى الوصل غير صالح أو كبير جدًا.");
        List<Bitmap> output = new ArrayList<>();
        TextPaint paint = new TextPaint(Paint.ANTI_ALIAS_FLAG);
        paint.setColor(Color.BLACK); paint.setTextSize(width == 384 ? 23 : 28);
        paint.setTypeface(Typeface.createFromAsset(context.getAssets(), "cairo-bold.ttf"));
        int total = 0;
        try {
        for (int i = 0; i < blocks.length(); i++) {
            JSONObject block = blocks.getJSONObject(i);
            Bitmap bitmap;
            if (block.has("image")) {
                String encoded = block.getString("image");
                if (encoded.length() > 1800000) throw new Exception("صورة الوصل كبيرة جدًا.");
                byte[] data = Base64.decode(encoded, Base64.DEFAULT);
                BitmapFactory.Options options = new BitmapFactory.Options(); options.inJustDecodeBounds = true;
                BitmapFactory.decodeByteArray(data, 0, data.length, options);
                int[] size = ReceiptBounds.image(options.outWidth, options.outHeight, width, block.optDouble("widthFraction",1), total);
                Bitmap source = BitmapFactory.decodeByteArray(data, 0, data.length);
                if (source == null) throw new Exception("تعذر قراءة صورة الوصل.");
                int w = size[0], h = size[1];
                try {
                    bitmap = Bitmap.createBitmap(width, h + 12, Bitmap.Config.ARGB_8888);
                    Canvas canvas = new Canvas(bitmap); canvas.drawColor(Color.WHITE);
                    canvas.drawBitmap(source, null, new Rect((width-w)/2, 6, (width+w)/2, h+6), new Paint(Paint.FILTER_BITMAP_FLAG));
                } finally { source.recycle(); }
            } else {
                String text = block.optString("text");
                if (text.length() > 2000) throw new Exception("نص الوصل كبير جدًا.");
                StaticLayout layout = StaticLayout.Builder.obtain(text, 0, text.length(), paint, width - 24)
                    .setAlignment(Layout.Alignment.ALIGN_CENTER).setIncludePad(true)
                    .setTextDirection(TextDirectionHeuristics.FIRSTSTRONG_RTL).build();
                bitmap = Bitmap.createBitmap(width, ReceiptBounds.rows(Math.max(1, layout.getHeight() + 8), total), Bitmap.Config.ARGB_8888);
                Canvas canvas = new Canvas(bitmap); canvas.drawColor(Color.WHITE); canvas.translate(12, 4); layout.draw(canvas);
            }
            total += bitmap.getHeight();
            output.add(bitmap);
        }
        return pack(output, width, total);
        } catch (Exception | LinkageError failure) {
            for (Bitmap bitmap : output) if (!bitmap.isRecycled()) bitmap.recycle();
            throw failure;
        }
    }
    /** Pack consecutive blocks without losing rows at block or SDK-strip boundaries. */
    static List<Bitmap> pack(List<Bitmap> blocks, int width, int total) {
        List<Bitmap> strips = new ArrayList<>(); Bitmap current = null; Canvas canvas = null;
        int written = 0, offset = 0;
        try {
            for (Bitmap block : blocks) {
                for (int top = 0; top < block.getHeight();) {
                    if (current == null) {
                        current = Bitmap.createBitmap(width, Math.min(192, total-written), Bitmap.Config.ARGB_8888);
                        canvas = new Canvas(current); canvas.drawColor(Color.WHITE); offset = 0;
                    }
                    int rows = Math.min(current.getHeight()-offset, block.getHeight()-top);
                    canvas.drawBitmap(block, new Rect(0,top,width,top+rows), new Rect(0,offset,width,offset+rows), null);
                    top += rows; offset += rows; written += rows;
                    if (offset == current.getHeight()) { strips.add(current); current = null; }
                }
                block.recycle();
            }
            if (written != total || current != null) throw new IllegalStateException("Incomplete receipt raster");
            return strips;
        } catch (RuntimeException failure) {
            if (current != null) current.recycle();
            for (Bitmap strip : strips) strip.recycle();
            throw failure;
        } finally {
            for (Bitmap block : blocks) if (!block.isRecycled()) block.recycle();
        }
    }
}
