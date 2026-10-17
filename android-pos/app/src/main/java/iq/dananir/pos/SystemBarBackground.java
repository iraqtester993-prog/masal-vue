package iq.dananir.pos;

import android.graphics.Canvas;
import android.graphics.ColorFilter;
import android.graphics.Paint;
import android.graphics.PixelFormat;
import android.graphics.Rect;
import android.graphics.drawable.Drawable;
import android.os.Build;
import android.view.WindowInsets;

/** Paints the existing root padding; adds no views, padding, layout flags or IME adjustments. */
final class SystemBarBackground extends Drawable {
    private final Paint paint = new Paint();
    private SystemBarPalette palette = SystemBarPalette.initial();
    private int statusTop, navigationLeft, navigationTop, navigationRight, navigationBottom;

    void palette(SystemBarPalette value) { palette = value; invalidateSelf(); }

    void insets(WindowInsets insets) {
        if (Build.VERSION.SDK_INT >= 30) {
            android.graphics.Insets status = insets.getInsets(WindowInsets.Type.statusBars());
            android.graphics.Insets navigation = insets.getInsets(WindowInsets.Type.navigationBars());
            statusTop = status.top;
            navigationLeft = navigation.left; navigationTop = navigation.top;
            navigationRight = navigation.right; navigationBottom = navigation.bottom;
        } else {
            // Stable system bars exclude the keyboard height on older Android versions.
            statusTop = insets.getStableInsetTop(); navigationTop = 0;
            navigationLeft = insets.getStableInsetLeft(); navigationRight = insets.getStableInsetRight();
            navigationBottom = insets.getStableInsetBottom();
        }
        invalidateSelf();
    }

    @Override public void draw(Canvas canvas) {
        Rect bounds = getBounds();
        fill(canvas, bounds.left, bounds.top, bounds.right, bounds.bottom, palette.backgroundColor);
        fill(canvas, bounds.left, bounds.top, bounds.left + Math.min(bounds.width(), navigationLeft), bounds.bottom, palette.navigationBarColor);
        fill(canvas, bounds.left, bounds.top, bounds.right, bounds.top + Math.min(bounds.height(), navigationTop), palette.navigationBarColor);
        fill(canvas, bounds.right - Math.min(bounds.width(), navigationRight), bounds.top, bounds.right, bounds.bottom, palette.navigationBarColor);
        fill(canvas, bounds.left, bounds.bottom - Math.min(bounds.height(), navigationBottom), bounds.right, bounds.bottom, palette.navigationBarColor);
        fill(canvas, bounds.left, bounds.top, bounds.right, bounds.top + Math.min(bounds.height(), statusTop), palette.statusBarColor);
    }

    private void fill(Canvas canvas, int left, int top, int right, int bottom, int color) {
        if (right <= left || bottom <= top) return;
        paint.setColor(color); canvas.drawRect(left, top, right, bottom, paint);
    }

    @Override public void setAlpha(int alpha) { /* These surfaces are deliberately opaque. */ }
    @Override public void setColorFilter(ColorFilter filter) { }
    @Override public int getOpacity() { return PixelFormat.OPAQUE; }
}
