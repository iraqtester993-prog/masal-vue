package iq.dananir.pos;

import android.app.Instrumentation;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.pm.ApplicationInfo;
import android.graphics.Bitmap;
import android.graphics.Canvas;
import android.graphics.Color;
import android.graphics.Insets;
import android.os.Build;
import android.os.Bundle;
import android.view.KeyEvent;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.webkit.ServiceWorkerClient;
import android.webkit.ServiceWorkerController;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.webkit.CookieManager;
import java.io.ByteArrayInputStream;
import java.io.File;
import java.io.FileOutputStream;
import java.lang.reflect.Field;
import java.nio.charset.StandardCharsets;
import java.util.Collections;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import org.json.JSONArray;
import org.json.JSONObject;

/** Debug-only, offline end-to-end checks. Never dispatches a printer or a business request. */
@android.annotation.TargetApi(30)
public final class AppearanceBackInstrumentation extends Instrumentation {
    private static final String ORIGIN = "https://pos.dananir-iq.com";
    private MainActivity activity;
    private WebView web;
    private ViewGroup root;
    private int assertions;
    private int replies;
    private int locallyAnswered;
    private int deniedRequests;
    private ServiceWorkerController serviceWorkers;

    @Override public void onCreate(Bundle arguments) { super.onCreate(arguments); start(); }

    @Override public void onStart() {
        Bundle result = new Bundle();
        try {
            if (Build.VERSION.SDK_INT < 30) throw new AssertionError("This offline runner requires API30+");
            check((getTargetContext().getApplicationInfo().flags & ApplicationInfo.FLAG_DEBUGGABLE) != 0,
                "This runner requires the isolated debug APK");
            if (Build.VERSION.SDK_INT >= 24) {
                serviceWorkers = ServiceWorkerController.getInstance();
                serviceWorkers.setServiceWorkerClient(new ServiceWorkerClient() {
                    @Override public WebResourceResponse shouldInterceptRequest(WebResourceRequest request) {
                        return blocked();
                    }
                });
            }
            launch();
            checkCapabilities();
            checkAppearance();
            checkBackgroundInsets();
            checkHandledAndRepeatedBack();
            checkBusyBack();
            checkHistoryFallback();
            checkExit();
            closeActivity();
            launch();
            checkRetainedSession();
            checkLegacyExit();
            closeActivity();
            launch();
            checkRetainedSession();
            checkUntrustedOrigin();
            result.putString("stream", "PASS: appearance/back offline instrumentation; assertions=" + assertions
                + "; actual_bridge_replies=" + replies + "; local_http_responses=" + locallyAnswered
                + "; blocked_requests=" + deniedRequests + "; API=" + Build.VERSION.SDK_INT
                + "; explicit/fallback colors, independent icons, inset bitmap, unchanged single-WebView geometry,"
                + " handled/key/rapid back, busy guard, history fallback, double-back timing/revalidation,"
                + " cookie/storage retention after reopening, legacy fallback and trusted-origin isolation;"
                + " no print, Bluetooth, financial or external network request dispatched\n");
            finish(0, result);
        } catch (Throwable error) {
            result.putString("stream", "FAIL: appearance/back instrumentation: " + error + "\n");
            android.util.Log.e("AppearanceBackTest", "Offline instrumentation failed", error);
            finish(1, result);
        } finally {
            closeActivity();
            if (serviceWorkers != null) serviceWorkers.setServiceWorkerClient(null);
        }
    }

    private void launch() throws Exception {
        activity = (MainActivity) startActivitySync(new Intent(getTargetContext(), MainActivity.class)
            .putExtra("isolated_ui", true).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        onUi(() -> {
            ViewGroup content = activity.findViewById(android.R.id.content);
            root = (ViewGroup) content.getChildAt(0);
            check(root.getChildCount() == 1 && root.getChildAt(0) instanceof WebView,
                "The hosted page must remain the only app view");
            web = (WebView) root.getChildAt(0);
            web.stopLoading();
            WebViewClient original = web.getWebViewClient();
            web.setWebViewClient(new WebViewClient() {
                @Override public void onPageStarted(WebView view, String url, Bitmap icon) {
                    // Preserve the real lifecycle cancellation while replacing only the network.
                    original.onPageStarted(view, url, icon);
                }
                @Override public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                    // A real HTTPS URL is necessary for MainActivity's native trust check. No socket is used.
                    if (!request.getMethod().equals("GET") || !request.isForMainFrame()) return blocked();
                    String host = request.getUrl().getHost();
                    if (!"pos.dananir-iq.com".equals(host) && !"untrusted.invalid".equals(host)) return blocked();
                    locallyAnswered++;
                    return new WebResourceResponse("text/html", "UTF-8", 200, "Local test only",
                        Collections.singletonMap("Cache-Control", "no-store"),
                        new ByteArrayInputStream(page().getBytes(StandardCharsets.UTF_8)));
                }
            });
            web.loadUrl(ORIGIN + "/__appearance125/start");
        });
        awaitJs("window.qaReady === true", "Offline trusted page did not initialize");
        check(eval("location.origin").equals(ORIGIN), "Page must have its actual trusted URL, not about:blank");
    }

    private void checkCapabilities() throws Exception {
        JSONObject value = request(new JSONObject().put("action", "capabilities"));
        check(value.getBoolean("ok"), "Capabilities must acknowledge the trusted real bridge");
        check(value.getInt("appearanceVersion") == 2 && value.getInt("nativeBackVersion") == 1,
            "Appearance/back capabilities missing");
        check(value.getInt("doubleBackExitVersion") == 1 && value.getString("appVersion").equals(BuildConfig.VERSION_NAME),
            "Home Back must advertise the native double-back gate in the new APK");
        check(value.getInt("paperWidthMm") == 58 && value.getInt("receiptWidthPixels") == 384,
            "58mm/384px printing contract changed");
        check(!value.has("blocks") && !value.has("address") && !value.has("serial"),
            "Capabilities must not expose receipt content or physical printer identity");
    }

    private void checkAppearance() throws Exception {
        final int[][] geometry = {null};
        onUi(() -> geometry[0] = geometry());
        appearance(new JSONObject().put("theme", "light").put("color", "#885522")
            .put("statusBarColor", "#08131A").put("navigationBarColor", "#FFFFFF")
            .put("backgroundColor", "#F5F7F6"), 0xff08131a, Color.WHITE, 0xfff5f7f6, false, true);
        appearance(new JSONObject().put("theme", "dark").put("color", "#885522")
            .put("statusBarColor", "#FFFFFF").put("navigationBarColor", "#142329")
            .put("backgroundColor", "#0D171B"), Color.WHITE, 0xff142329, 0xff0d171b, true, false);
        appearance(new JSONObject().put("theme", "light").put("color", "#224466"),
            0xff224466, 0xff224466, 0xff224466, false, false);
        appearance(new JSONObject().put("theme", "light").put("color", "#F3F8FB")
            .put("statusBarColor", "red").put("navigationBarColor", "#123")
            .put("backgroundColor", "transparent"), 0xfff3f8fb, 0xfff3f8fb, 0xfff3f8fb, true, true);
        appearance(new JSONObject().put("theme", "dark").put("color", "invalid"),
            SystemBarPalette.LEGACY_DARK, SystemBarPalette.LEGACY_DARK, SystemBarPalette.LEGACY_DARK, false, false);
        onUi(() -> check(java.util.Arrays.equals(geometry[0], geometry()),
            "Applying independent colors must not change WebView/root geometry or padding"));
    }

    private void appearance(JSONObject payload, int status, int navigation, int background,
                            boolean statusDark, boolean navigationDark) throws Exception {
        JSONObject reply = request(payload.put("action", "appearance"));
        check(reply.getBoolean("ok") && "applied".equals(reply.getString("state")),
            "Appearance must acknowledge applied through the actual bridge");
        waitForIdleSync();
        onUi(() -> {
            check(root.getChildCount() == 1 && root.getChildAt(0) == web,
                "Appearance must not add a second header or replace WebView");
            check(root.getBackground() instanceof SystemBarBackground, "Root must own the system inset drawable");
            Bitmap image = Bitmap.createBitmap(root.getWidth(), root.getHeight(), Bitmap.Config.ARGB_8888);
            try {
                root.getBackground().draw(new Canvas(image));
                check(image.getPixel(image.getWidth() / 2, image.getHeight() / 2) == background,
                    "Actual root background differs from the bridge backgroundColor");
                if (root.getPaddingTop() > 0) check(image.getPixel(image.getWidth() / 2, 0) == status,
                    "Actual status inset differs from statusBarColor");
                if (root.getPaddingBottom() > 0) check(image.getPixel(image.getWidth() / 2, image.getHeight() - 1) == navigation,
                    "Actual navigation inset differs from navigationBarColor");
            } finally { image.recycle(); }
            if (Build.VERSION.SDK_INT >= 30) {
                WindowInsetsController controller = activity.getWindow().getDecorView().getWindowInsetsController();
                check(controller != null, "System icon controller unavailable");
                int appearance = controller.getSystemBarsAppearance();
                check(((appearance & WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS) != 0) == statusDark,
                    "Status icon contrast must resolve independently");
                check(((appearance & WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS) != 0) == navigationDark,
                    "Navigation icon contrast must resolve independently");
            }
        });
    }

    private void checkBackgroundInsets() {
        if (Build.VERSION.SDK_INT < 30) return;
        onUi(() -> {
            SystemBarBackground drawable = new SystemBarBackground();
            drawable.palette(SystemBarPalette.resolve(false, "#112233", "#203040", "#506070", "#8090A0"));
            drawable.insets(new WindowInsets.Builder()
                .setInsets(WindowInsets.Type.statusBars(), Insets.of(0, 12, 0, 0))
                .setInsets(WindowInsets.Type.navigationBars(), Insets.of(7, 0, 9, 15))
                .setInsets(WindowInsets.Type.ime(), Insets.of(0, 0, 0, 70)).build());
            drawable.setBounds(0, 0, 100, 100);
            Bitmap image = Bitmap.createBitmap(100, 100, Bitmap.Config.ARGB_8888);
            try {
                drawable.draw(new Canvas(image));
                check(image.getPixel(50, 0) == 0xff203040, "Status bitmap band lost its color");
                check(image.getPixel(50, 99) == 0xff506070, "Navigation bitmap band lost its color");
                check(image.getPixel(0, 50) == 0xff506070 && image.getPixel(99, 50) == 0xff506070,
                    "Side navigation insets must remain colored");
                check(image.getPixel(50, 50) == 0xff8090a0, "IME height must not repaint the page as navigation bar");
            } finally { image.recycle(); }
        });
    }

    private void checkHandledAndRepeatedBack() throws Exception {
        eval("window.qaMode='handled';window.qaBackCount=0;true");
        onUi(() -> { activity.onBackPressed(); activity.onBackPressed(); activity.onBackPressed(); });
        awaitJs("window.qaBackCount===1", "Rapid native back calls must dispatch only one pending web decision");
        check(!activity.isFinishing(), "Handled back must retain the activity");
        eval("window.qaBackCount=0;true");
        sendKeyDownUpSync(KeyEvent.KEYCODE_BACK);
        awaitJs("window.qaBackCount===1", "Android BACK key must reach the cancelable web event");
        check(!activity.isFinishing(), "Handled Android BACK key must retain the activity");
        if (Build.VERSION.SDK_INT >= 33) {
            Field callback = MainActivity.class.getDeclaredField("unregisterBackCallback");
            callback.setAccessible(true);
            check(callback.get(activity) != null, "API33+ back callback must be registered");
        }
    }

    private void checkBusyBack() throws Exception {
        Field field = MainActivity.class.getDeclaredField("busy");
        field.setAccessible(true);
        AtomicBoolean busy = (AtomicBoolean) field.get(activity);
        eval("window.qaMode='exit';window.qaBackCount=0;true");
        String before = eval("location.href");
        try {
            busy.set(true);
            onUi(activity::onBackPressed);
            sendKeyDownUpSync(KeyEvent.KEYCODE_BACK);
            waitForIdleSync();
            check(!activity.isFinishing(), "A pending print must block native back exit");
            check(eval("String(window.qaBackCount)").equals("0"), "Busy back must stop before page navigation");
            check(eval("location.href").equals(before), "Busy back must preserve the current URL");
            check(request(new JSONObject().put("action", "capabilities")).getBoolean("busy"),
                "Busy guard must remain set; test may not complete a print");
        } finally { busy.set(false); }
    }

    private void checkHistoryFallback() throws Exception {
        // Native browser history must be tested with real document navigations. Synthetic
        // pushState entries without user activation can be skipped by Chromium's back UI.
        loadFixture("/__appearance125/first");
        loadFixture("/__appearance125/second");
        eval("window.qaMode='unhandled';window.qaBackCount=0;true");
        final boolean[] available = {false};
        onUi(() -> {
            available[0] = web.canGoBack();
            android.webkit.WebBackForwardList history = web.copyBackForwardList();
            int current = history.getCurrentIndex();
            check(current > 0 && (ORIGIN + "/__appearance125/first").equals(history.getItemAtIndex(current - 1).getUrl()),
                "The native previous entry must be the first offline document");
        });
        check(available[0], "The fallback scenario requires actual WebView history");
        String before = historyDiagnostics();
        onUi(activity::onBackPressed);
        try {
            awaitJs("location.pathname==='/__appearance125/first'&&window.qaReady===true",
                "Unhandled native back must use WebView history");
        } catch (AssertionError failure) {
            throw new AssertionError(failure.getMessage() + "; before=" + before + "; after=" + historyDiagnostics(), failure);
        }
        check(!activity.isFinishing(), "History fallback must retain the activity");
    }

    private void loadFixture(String path) throws Exception {
        onUi(() -> web.loadUrl(ORIGIN + path));
        awaitJs("location.pathname===" + JSONObject.quote(path) + "&&window.qaReady===true",
            "Offline history document did not initialize: " + path);
    }

    private String historyDiagnostics() {
        final JSONObject details = new JSONObject();
        try {
            onUi(() -> {
                try {
                    android.webkit.WebBackForwardList history = web.copyBackForwardList();
                    JSONArray entries = new JSONArray();
                    for (int index = 0; index < history.getSize(); index++) entries.put(history.getItemAtIndex(index).getUrl());
                    details.put("url", web.getUrl()).put("index", history.getCurrentIndex())
                        .put("entries", entries).put("canGoBack", web.canGoBack()).put("finishing", activity.isFinishing());
                    Field dispatchField = MainActivity.class.getDeclaredField("nativeBack"); dispatchField.setAccessible(true);
                    Field pendingField = NativeBackDispatch.class.getDeclaredField("pending"); pendingField.setAccessible(true);
                    details.put("backPending", pendingField.getBoolean(dispatchField.get(activity)));
                } catch (Exception error) { throw new IllegalStateException(error); }
            });
            details.put("page", new JSONObject(eval("JSON.stringify({url:location.href,mode:window.qaMode,backCount:window.qaBackCount,"
                + "ready:window.qaReady,hasUserActivation:!!(navigator.userActivation&&navigator.userActivation.hasBeenActive)})")));
        } catch (Exception error) {
            try { details.put("diagnosticError", error.toString()); } catch (org.json.JSONException ignored) { }
        }
        return details.toString();
    }

    private void checkExit() throws Exception {
        eval("window.qaMode='exit';localStorage.setItem('dananeer_exit_qa','retained');true");
        CountDownLatch cookieSaved = new CountDownLatch(1);
        onUi(() -> CookieManager.getInstance().setCookie(ORIGIN,
            "dananeer_exit_qa=retained; Path=/; Secure; HttpOnly; SameSite=Strict", accepted -> cookieSaved.countDown()));
        check(cookieSaved.await(5, TimeUnit.SECONDS), "Synthetic session cookie must be saved before exit testing");
        request(new JSONObject().put("action", "appearance").put("theme", "light").put("color", "#46512B")
            .put("statusBarColor", "#46512B").put("navigationBarColor", "#FFFFFF").put("backgroundColor", "#F5F7F6"));
        eval("window.qaBackCount=0;true");
        onUi(() -> { activity.onBackPressed(); activity.onBackPressed(); activity.onBackPressed(); });
        awaitJs("window.qaBackCount===1", "Rapid home Back must dispatch only one web decision");
        check(!activity.isFinishing(), "First home Back must show a brief notice without closing the app");
        check(eval("String(window.qaDoubleBackExitVersion)").equals("1"), "Actual event must advertise double-back support");
        checkRetainedSession();
        Thread.sleep(2100);
        onUi(activity::onBackPressed); awaitJs("window.qaBackCount===2", "Expired notice must require another first Back");
        check(!activity.isFinishing(), "An expired first Back may not close the app");
        eval("window.qaExitGeneration=2;true");
        onUi(activity::onBackPressed); awaitJs("window.qaBackCount===3", "Navigation generation must be checked again");
        check(!activity.isFinishing(), "Leaving and returning home requires two new presses");
        Field field = MainActivity.class.getDeclaredField("busy"); field.setAccessible(true);
        AtomicBoolean busy = (AtomicBoolean) field.get(activity);
        try {
            busy.set(true); onUi(activity::onBackPressed);
            check(!activity.isFinishing(), "Print starting after the first Back must prevent exit");
            checkRetainedSession();
        } finally { busy.set(false); }
        onUi(activity::onBackPressed); awaitJs("window.qaBackCount===4", "The print guard must reset the first Back");
        check(!activity.isFinishing(), "The first Back after printing only arms exit");
        eval("window.qaMode='handled';true");
        onUi(activity::onBackPressed); awaitJs("window.qaBackCount===5", "Second Back must freshly consult web blocking state");
        check(!activity.isFinishing(), "A web layer or pending operation must reset exit");
        eval("window.qaMode='exit';true");
        onUi(activity::onBackPressed); awaitJs("window.qaBackCount===6", "A handled layer must reset the first Back");
        check(!activity.isFinishing(), "Closing a layer must not reuse an earlier exit approval");
        loadFixture("/__appearance126/replaced");
        checkRetainedSession();
        eval("window.qaMode='exit';true"); onUi(activity::onBackPressed);
        awaitJs("window.qaBackCount===1", "A replacement document must require a first Back");
        check(!activity.isFinishing(), "A replacement document cannot inherit exit approval");
        onUi(activity::onBackPressed); awaitFinishing("Two fresh home Back presses must close the app");
    }

    private void checkRetainedSession() throws Exception {
        check(eval("localStorage.getItem('dananeer_exit_qa')").equals("retained"),
            "Canceling/exiting/reopening must preserve the session local storage");
        final String[] cookie = {null}; onUi(() -> cookie[0] = CookieManager.getInstance().getCookie(ORIGIN));
        check(cookie[0] != null && cookie[0].contains("dananeer_exit_qa=retained"),
            "Canceling/exiting/reopening must preserve the secure HttpOnly session cookie");
    }

    private void checkLegacyExit() throws Exception {
        onUi(web::clearHistory); eval("window.qaMode='unhandled';true");
        eval("window.qaBackCount=0;true");onUi(activity::onBackPressed);
        awaitJs("window.qaBackCount===1", "Legacy first Back must complete its web decision");
        check(!activity.isFinishing(), "Old web without a handler still requires a second Back");
        onUi(activity::onBackPressed);awaitFinishing("Legacy double-back no-history exit must remain available");
    }

    private AlertDialog exitDialog() {
        final AlertDialog[] value = {null};
        onUi(() -> {
            try { Field field = MainActivity.class.getDeclaredField("exitConfirmation"); field.setAccessible(true); value[0] = (AlertDialog) field.get(activity); }
            catch (ReflectiveOperationException error) { throw new IllegalStateException(error); }
        });
        return value[0];
    }

    private AlertDialog awaitExitDialog() throws Exception {
        for (int attempt = 0; attempt < 120; attempt++) {
            AlertDialog dialog = exitDialog(); if (dialog != null && dialog.isShowing()) return dialog;
            Thread.sleep(30);
        }
        throw new AssertionError("Exit confirmation did not appear");
    }

    private void awaitNoExitDialog() throws Exception {
        for (int attempt = 0; attempt < 120; attempt++) {
            AlertDialog dialog = exitDialog(); if (dialog == null || !dialog.isShowing()) return;
            Thread.sleep(30);
        }
        throw new AssertionError("Exit confirmation did not close");
    }

    private void awaitFinishing(String failure) throws Exception {
        for (int attempt = 0; attempt < 120 && !activity.isFinishing(); attempt++) Thread.sleep(30);
        check(activity.isFinishing(), failure);
    }

    private void checkDialogStyle(AlertDialog dialog, int panel, int accent) {
        onUi(() -> {
            android.graphics.drawable.GradientDrawable surface = (android.graphics.drawable.GradientDrawable) dialog.getWindow().getDecorView().getBackground();
            check(surface.getColor().getDefaultColor() == panel, "Exit dialog surface must follow the app light/dark panel");
            android.graphics.drawable.GradientDrawable button = (android.graphics.drawable.GradientDrawable) dialog.getButton(AlertDialog.BUTTON_POSITIVE).getBackground();
            check(button.getColor().getDefaultColor() == accent, "Exit button must follow the current agent color");
            View content = dialog.getWindow().getDecorView().findViewWithTag("dananeer-exit-confirmation");
            check(content != null && content.getLayoutDirection() == View.LAYOUT_DIRECTION_RTL,
                "Actual Arabic dialog content must stay right-to-left independently of the device locale");
            check(((View) dialog.getButton(AlertDialog.BUTTON_POSITIVE).getParent()).getLayoutDirection() == View.LAYOUT_DIRECTION_RTL,
                "Exit/stay button arrangement must stay right-to-left independently of window decor");
        });
    }

    private void captureDialog(String name) throws Exception {
        waitForIdleSync(); Thread.sleep(150);
        Bitmap screenshot = getUiAutomation().takeScreenshot(); check(screenshot != null, "Dialog screenshot unavailable");
        try (FileOutputStream output = new FileOutputStream(new File(getTargetContext().getCacheDir(), name))) {
            check(screenshot.compress(Bitmap.CompressFormat.PNG, 100, output), "Dialog screenshot could not be encoded");
        } finally { screenshot.recycle(); }
    }

    private void checkUntrustedOrigin() throws Exception {
        onUi(() -> web.loadUrl("https://untrusted.invalid/__appearance125/isolated"));
        awaitJs("location.hostname==='untrusted.invalid'&&window.qaReady===true", "Offline untrusted test page did not load");
        check(eval("typeof window.DananeerPrint").equals("undefined"), "Untrusted page must not receive the native bridge");
    }

    private JSONObject request(JSONObject payload) throws Exception {
        String id = "appearance-test-" + (++replies);
        payload.put("requestId", id);
        // Only these two non-mutating native commands are permitted by this runner.
        check(payload.getString("action").equals("appearance") || payload.getString("action").equals("capabilities"),
            "Runner must not dispatch printer, device-proof, Bluetooth or financial operations");
        eval("window.DananeerPrint.postMessage(" + JSONObject.quote(payload.toString()) + ");true");
        awaitJs("!!window.qaReplies[" + JSONObject.quote(id) + "]", "Actual bridge acknowledgement timed out: " + id);
        return new JSONObject(eval("JSON.stringify(window.qaReplies[" + JSONObject.quote(id) + "])") );
    }

    private String eval(String script) throws Exception {
        CountDownLatch done = new CountDownLatch(1);
        final String[] answer = {null};
        onUi(() -> web.evaluateJavascript(script, value -> { answer[0] = value; done.countDown(); }));
        if (!done.await(5, TimeUnit.SECONDS)) throw new AssertionError("WebView evaluation timed out");
        if (answer[0] == null || answer[0].equals("null")) return "null";
        Object decoded = new JSONArray("[" + answer[0] + "]").get(0);
        return String.valueOf(decoded);
    }

    private void awaitJs(String condition, String failure) throws Exception {
        for (int attempt = 0; attempt < 120; attempt++) {
            if (eval("Boolean(" + condition + ")").equals("true")) return;
            Thread.sleep(50);
        }
        throw new AssertionError(failure);
    }

    private int[] geometry() {
        return new int[]{root.getChildCount(), root.getWidth(), root.getHeight(), root.getPaddingLeft(),
            root.getPaddingTop(), root.getPaddingRight(), root.getPaddingBottom(), web.getLeft(), web.getTop(),
            web.getWidth(), web.getHeight()};
    }

    private WebResourceResponse blocked() {
        deniedRequests++;
        return new WebResourceResponse("text/plain", "UTF-8", 403, "Blocked by offline test",
            Collections.emptyMap(), new ByteArrayInputStream(new byte[0]));
    }

    private static String page() {
        return "<!doctype html><html><head><meta name='viewport' content='width=device-width,initial-scale=1'>"
            + "<meta http-equiv='Content-Security-Policy' content=\"default-src 'none';script-src 'unsafe-inline';"
            + "style-src 'unsafe-inline';connect-src 'none';img-src 'none';form-action 'none';base-uri 'none'\">"
            + "<title>Offline appearance/back QA</title></head><body><p>LOCAL TEST ONLY</p><script>"
            + "window.qaReplies={};window.qaMode='handled';window.qaBackCount=0;"
            + "if(window.DananeerPrint)window.DananeerPrint.onmessage=e=>{var r=JSON.parse(e.data);window.qaReplies[r.requestId]=r;};"
            + "window.addEventListener('dananeer:native-back',e=>{window.qaBackCount++;"
            + "window.qaExitConfirmationVersion=e.detail.exitConfirmationVersion;"
            + "window.qaDoubleBackExitVersion=e.detail.doubleBackExitVersion;e.detail.exitGeneration=window.qaExitGeneration||0;"
            + "if(window.qaMode==='unhandled')return;e.preventDefault();e.detail.result=window.qaMode==='exit'?'exit':'handled';});"
            + "window.qaReady=true;</script></body></html>";
    }

    private void closeActivity() {
        if (activity == null) return;
        MainActivity closing = activity;
        activity = null;
        onUi(() -> { if (!closing.isFinishing()) closing.finish(); });
        waitForIdleSync();
    }

    private void onUi(Runnable operation) {
        final Throwable[] failure = {null};
        super.runOnMainSync(() -> {
            try { operation.run(); } catch (Throwable error) { failure[0] = error; }
        });
        if (failure[0] instanceof AssertionError) throw (AssertionError) failure[0];
        if (failure[0] instanceof RuntimeException) throw (RuntimeException) failure[0];
        if (failure[0] != null) throw new IllegalStateException("Offline UI test failed", failure[0]);
    }

    private void check(boolean valid, String message) {
        assertions++;
        if (!valid) throw new AssertionError(message);
    }
}
