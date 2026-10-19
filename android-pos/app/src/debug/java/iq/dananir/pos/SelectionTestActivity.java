package iq.dananir.pos;

import android.app.Activity;
import android.os.Bundle;
import android.view.ActionMode;
import android.webkit.WebView;
import android.webkit.WebViewClient;

/** Offline fixture included only in debug APKs; no native bridge or business data. */
public final class SelectionTestActivity extends Activity {
    WebView web;
    int actionModesStarted;

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        web = getIntent().getBooleanExtra("plain_webview", false) ? new WebView(this) : new PosWebView(this);
        web.getSettings().setJavaScriptEnabled(true);
        web.getSettings().setBlockNetworkLoads(true);
        web.getSettings().setBlockNetworkImage(true);
        web.getSettings().setAllowFileAccess(false);
        web.getSettings().setAllowContentAccess(false);
        web.setWebViewClient(new WebViewClient());
        setContentView(web);
        web.loadDataWithBaseURL("https://offline.invalid/", "<!doctype html><html><head>"
            + "<meta name='viewport' content='width=device-width,initial-scale=1'>"
            + "<style>body{font:20px sans-serif;margin:24px}button,input,textarea{display:block;box-sizing:border-box;width:100%;font:20px sans-serif;margin:20px 0;padding:14px}#copy{line-height:1.6}#space{height:2200px}</style>"
            + "</head><body><p id='copy'>Local selection fixture text remains private during a long press.</p>"
            + "<button id='tap' onclick='window.taps++'>Tap fixture</button><input id='field' autocomplete='off' autocapitalize='off' autocorrect='off' spellcheck='false'>"
            + "<textarea id='area' rows='2' autocapitalize='off' autocorrect='off' spellcheck='false'></textarea><div id='space'></div><p id='bottom'>Scroll target</p>"
            + "<script>window.taps=0;window.qaKeys=[];window.qaInput=[];window.qaComposition=[];document.addEventListener('keydown',e=>window.qaKeys.push([e.target.id,e.key]),true);['beforeinput','input'].forEach(t=>document.addEventListener(t,e=>window.qaInput.push([t,e.target.id,e.inputType,e.data]),true));['compositionstart','compositionupdate','compositionend'].forEach(t=>document.addEventListener(t,e=>window.qaComposition.push([t,e.target.id,e.data]),true));document.addEventListener('pointerdown',e=>window.lastPointer=e.target.id,true);window.qaReady=true;</script></body></html>", "text/html", "UTF-8", null);
    }

    @Override public void onActionModeStarted(ActionMode mode) {
        actionModesStarted++;
        super.onActionModeStarted(mode);
    }

    @Override public void onDestroy() {
        web.destroy();
        super.onDestroy();
    }
}
