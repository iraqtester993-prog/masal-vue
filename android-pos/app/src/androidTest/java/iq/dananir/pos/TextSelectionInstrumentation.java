package iq.dananir.pos;

import android.app.Instrumentation;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ApplicationInfo;
import android.os.Build;
import android.os.Bundle;
import android.os.SystemClock;
import android.view.ActionMode;
import android.view.InputDevice;
import android.view.Menu;
import android.view.MenuItem;
import android.view.MotionEvent;
import android.view.ViewConfiguration;
import android.view.WindowInsets;
import android.view.inputmethod.InputMethodManager;
import android.webkit.WebView;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import org.json.JSONArray;

/** Real touch/keyboard regression against an offline debug activity, without a business bridge. */
public final class TextSelectionInstrumentation extends Instrumentation {
    private SelectionTestActivity activity;
    private WebView web;
    private ClipboardManager clipboard;
    private String clipboardBefore;
    private int assertions;
    private boolean control;
    private boolean keyboardOnly;

    @Override public void onCreate(Bundle arguments) { super.onCreate(arguments); control = arguments != null && "1".equals(arguments.getString("control")); keyboardOnly = arguments != null && "1".equals(arguments.getString("keyboard_only")); start(); }

    @Override public void onStart() {
        Bundle result = new Bundle();
        try {
            check((getTargetContext().getApplicationInfo().flags & ApplicationInfo.FLAG_DEBUGGABLE) != 0,
                "Offline selection checks require a debug APK");
            activity = (SelectionTestActivity) startActivitySync(new Intent(getTargetContext(), SelectionTestActivity.class)
                .putExtra("plain_webview", control).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
            onUi(() -> {
                web = activity.web;
                clipboard = (ClipboardManager) activity.getSystemService(Context.CLIPBOARD_SERVICE);
                check(web.getSettings().getBlockNetworkLoads(), "Fixture must block every network load");
            });
            awaitJs("window.qaReady === true", "Offline page did not load");
            awaitVisible();
            onUi(() -> clipboardBefore = String.valueOf(clipboard.getPrimaryClip()));
            check(eval("typeof window.DananeerPrint").equals("undefined"), "Fixture must not install a native business bridge");
            if (control || keyboardOnly) {
                press("field", false); awaitEditor("field"); sendStringSync("typed123");
                awaitJs("document.getElementById('field').value === 'typed123'", "Control input did not receive native keys");
                press("area", false); awaitEditor("area"); sendStringSync("notes42");
                awaitJs("document.getElementById('area').value === 'notes42'", "Control textarea did not receive native keys");
                result.putString("stream", "PASS: " + (control ? "plain WebView" : "PosWebView") + " keyboard control only\n"); finish(0, result); return;
            }
            checkNativeEntryPoints();
            press("copy", true);
            checkNoSelectionMenu();
            press("tap", false);
            awaitJs("window.taps === 1", "Ordinary tap did not reach its button");
            press("field", false);
            awaitEditor("field");
            sendStringSync("typed123");
            awaitJs("document.getElementById('field').value === 'typed123'", "Native keyboard input did not reach the field");
            press("field", true);
            checkNoSelectionMenu();
            sendStringSync("4");
            awaitJs("document.getElementById('field').value.length === 9 && document.getElementById('field').value.includes('4')",
                "Input must remain editable after a blocked long press");
            press("area", false);
            awaitEditor("area");
            sendStringSync("notes42");
            awaitJs("document.getElementById('area').value === 'notes42'", "Native keyboard input did not reach the textarea");
            press("area", true);
            checkNoSelectionMenu();
            sendStringSync("5");
            awaitJs("document.getElementById('area').value.length === 8 && document.getElementById('area').value.includes('5')",
                "Textarea must remain editable after a blocked long press");
            onUi(() -> ((InputMethodManager) activity.getSystemService(Context.INPUT_METHOD_SERVICE))
                .hideSoftInputFromWindow(web.getWindowToken(), 0));
            eval("document.activeElement.blur();window.scrollTo(0,0);true");
            awaitKeyboardHidden();
            swipe();
            awaitJs("window.scrollY > 100", "Touch scrolling was blocked");
            press("tap", false);
            awaitJs("window.taps === 2", "Button tap stopped working after scrolling");
            checkNoSelectionMenu();
            result.putString("stream", "PASS: offline native selection checks; assertions=" + assertions
                + "; API=" + Build.VERSION.SDK_INT
                + "; long-press text/input/textarea, legacy/primary/floating action modes blocked;"
                + " clipboard unchanged; real taps, touch scrolling and native keyboard input preserved;"
                + " network loads blocked; no native business bridge or business command\n");
            finish(0, result);
        } catch (Throwable error) {
            result.putString("stream", "FAIL: offline native selection checks: " + error + "\n");
            android.util.Log.e("TextSelectionTest", "Offline selection instrumentation failed", error);
            finish(1, result);
        } finally {
            if (activity != null) onUi(activity::finish);
        }
    }

    private void checkNativeEntryPoints() {
        onUi(() -> {
            final int[] callbacks = {0};
            ActionMode.Callback callback = new ActionMode.Callback() {
                @Override public boolean onCreateActionMode(ActionMode mode, Menu menu) { callbacks[0]++; return true; }
                @Override public boolean onPrepareActionMode(ActionMode mode, Menu menu) { return false; }
                @Override public boolean onActionItemClicked(ActionMode mode, MenuItem item) { return false; }
                @Override public void onDestroyActionMode(ActionMode mode) { }
            };
            check(web.performLongClick(), "Legacy long-click must be consumed");
            check(web.performLongClick(20, 20), "Positioned long-click must be consumed");
            check(web.startActionMode(callback) == null, "Legacy action mode must be suppressed");
            check(web.startActionMode(callback, ActionMode.TYPE_PRIMARY) == null, "Primary action mode must be suppressed");
            check(web.startActionMode(callback, ActionMode.TYPE_FLOATING) == null, "Floating action mode must be suppressed");
            check(callbacks[0] == 0, "Suppressed action modes must not invoke selection callbacks");
        });
    }

    private void checkNoSelectionMenu() {
        waitForIdleSync();
        onUi(() -> {
            check(activity.actionModesStarted == 0, "An actual native text-selection menu opened");
            check(clipboardBefore.equals(String.valueOf(clipboard.getPrimaryClip())),
                "Long-press must not alter the clipboard");
        });
    }

    private void press(String id, boolean longPress) throws Exception {
        eval("window.lastPointer=null;document.getElementById('" + id + "').scrollIntoView({block:'center'});true");
        SystemClock.sleep(100);
        JSONArray point = new JSONArray(eval("JSON.stringify((()=>{const r=document.getElementById('" + id
            + "').getBoundingClientRect();return [r.left+r.width/2,r.top+r.height/2,innerWidth]})())"));
        final int[] location = new int[2];
        final int[] width = {0};
        final int[] height = {0};
        onUi(() -> { web.getLocationOnScreen(location); width[0] = web.getWidth(); height[0] = web.getHeight(); });
        float scale = (float) (width[0] / point.getDouble(2));
        float x = location[0] + (float) point.getDouble(0) * scale;
        float y = location[1] + (float) point.getDouble(1) * scale;
        check(x > location[0] && x < location[0] + width[0] && y > location[1] && y < location[1] + height[0],
            "Fixture touch point must stay inside its own WebView");
        long down = SystemClock.uptimeMillis();
        pointer(down, down, MotionEvent.ACTION_DOWN, x, y);
        SystemClock.sleep(longPress ? ViewConfiguration.getLongPressTimeout() + 250L : 60);
        pointer(down, SystemClock.uptimeMillis(), MotionEvent.ACTION_UP, x, y);
        waitForIdleSync();
        awaitJs("window.lastPointer === '" + id + "'", "Actual pointer event did not reach its fixture target: " + id);
    }

    private void swipe() {
        final int[] location = new int[2];
        final int[] size = new int[2];
        onUi(() -> { web.getLocationOnScreen(location); size[0] = web.getWidth(); size[1] = web.getHeight(); });
        float x = location[0] + size[0] * .5f;
        float start = location[1] + size[1] * .85f;
        float end = location[1] + size[1] * .3f;
        long down = SystemClock.uptimeMillis();
        pointer(down, down, MotionEvent.ACTION_DOWN, x, start);
        for (int index = 1; index <= 12; index++) {
            SystemClock.sleep(20);
            pointer(down, SystemClock.uptimeMillis(), MotionEvent.ACTION_MOVE, x, start + (end - start) * index / 12);
        }
        pointer(down, SystemClock.uptimeMillis(), MotionEvent.ACTION_UP, x, end);
        waitForIdleSync();
    }

    private void pointer(long down, long time, int action, float x, float y) {
        MotionEvent event = MotionEvent.obtain(down, time, action, x, y, 0);
        event.setSource(InputDevice.SOURCE_TOUCHSCREEN);
        try { sendPointerSync(event); }
        finally { event.recycle(); }
    }

    private String eval(String script) throws Exception {
        CountDownLatch done = new CountDownLatch(1);
        final String[] answer = {null};
        onUi(() -> web.evaluateJavascript(script, value -> { answer[0] = value; done.countDown(); }));
        check(done.await(5, TimeUnit.SECONDS), "Offline JavaScript evaluation timed out");
        return String.valueOf(new JSONArray("[" + answer[0] + "]").get(0));
    }

    private void awaitVisible() {
        for (int attempt = 0; attempt < 100; attempt++) {
            final boolean[] visible = {false};
            onUi(() -> visible[0] = activity.hasWindowFocus() && web.isShown() && web.getWidth() > 0 && web.getHeight() > 0);
            if (visible[0]) { assertions++; return; }
            SystemClock.sleep(50);
        }
        throw new AssertionError("Offline fixture did not obtain a visible focused window");
    }

    private void awaitKeyboardHidden() {
        final int[] previousSize = {-1, -1};
        int stable = 0;
        for (int attempt = 0; attempt < 100; attempt++) {
            final boolean[] hidden = {false};
            final int[] size = {0, 0};
            onUi(() -> {
                hidden[0] = activity.hasWindowFocus() && (Build.VERSION.SDK_INT < 30
                    || (web.getRootWindowInsets() != null && !web.getRootWindowInsets().isVisible(WindowInsets.Type.ime())));
                size[0] = web.getWidth(); size[1] = web.getHeight();
            });
            stable = hidden[0] && java.util.Arrays.equals(size, previousSize) ? stable + 1 : 0;
            System.arraycopy(size, 0, previousSize, 0, 2);
            if (stable >= 3) { waitForIdleSync(); assertions++; return; }
            SystemClock.sleep(50);
        }
        throw new AssertionError("Native keyboard did not finish hiding before the swipe");
    }

    private void awaitEditor(String id) throws Exception {
        awaitJs("document.activeElement.id === '" + id + "'", "Actual tap did not focus the fixture editor: " + id);
        int stable = 0;
        for (int attempt = 0; attempt < 100; attempt++) {
            final boolean[] ready = {false};
            onUi(() -> {
                InputMethodManager input = (InputMethodManager) activity.getSystemService(Context.INPUT_METHOD_SERVICE);
                ready[0] = activity.hasWindowFocus() && web.hasFocus() && input.isActive(web) && input.isAcceptingText();
            });
            stable = ready[0] ? stable + 1 : 0;
            if (stable >= 3) { assertions++; return; }
            SystemClock.sleep(100);
        }
        throw new AssertionError("Native input connection did not become ready: " + id);
    }

    private void awaitJs(String condition, String message) throws Exception {
        for (int attempt = 0; attempt < 80; attempt++) {
            if (eval("Boolean(" + condition + ")").equals("true")) { assertions++; return; }
            SystemClock.sleep(50);
        }
        throw new AssertionError(message + "; local fixture state=" + eval("JSON.stringify({active:document.activeElement.id,documentFocus:document.hasFocus(),lastPointer:window.lastPointer,width:innerWidth,height:innerHeight,scroll:scrollY,field:document.getElementById('field').value,area:document.getElementById('area').value,keys:window.qaKeys,input:window.qaInput,composition:window.qaComposition})"));
    }

    private void onUi(Runnable action) {
        final Throwable[] failure = {null};
        runOnMainSync(() -> { try { action.run(); } catch (Throwable error) { failure[0] = error; } });
        if (failure[0] != null) throw new AssertionError("UI assertion failed", failure[0]);
    }

    private void check(boolean valid, String message) {
        if (!valid) throw new AssertionError(message);
        assertions++;
    }
}
