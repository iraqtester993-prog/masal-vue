package iq.dananir.pos;

import android.content.Context;
import android.view.ActionMode;
import android.webkit.WebView;

/** Keeps normal WebView input while suppressing native text-selection menus. */
public final class PosWebView extends WebView {
    public PosWebView(Context context) { super(context); }

    @Override public boolean performLongClick() { return true; }

    @Override public boolean performLongClick(float x, float y) { return true; }

    @Override public ActionMode startActionMode(ActionMode.Callback callback) { return null; }

    @Override public ActionMode startActionMode(ActionMode.Callback callback, int type) { return null; }
}
