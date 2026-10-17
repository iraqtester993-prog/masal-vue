package iq.dananir.pos;

import android.Manifest;
import android.app.Activity;
import android.bluetooth.*;
import android.bluetooth.le.*;
import android.content.*;
import android.content.pm.PackageManager;
import android.location.LocationManager;
import android.os.*;
import org.json.*;
import java.util.*;

/** Foreground-only discovery and OS-confirmed bonding. Never selects a print route. */
@android.annotation.SuppressLint("MissingPermission") // Checked before starting; callbacks/cleanup also handle revocation.
final class BluetoothPrinterDiscovery {
    static final int PERMISSIONS = 5, ENABLE = 6;
    static final long SCAN_MS = 18000, PAIR_MS = 60000, TOTAL_MS = 120000;
    interface Listener { void response(JSONObject value); }
    private final Activity activity;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private final BluetoothDiscoverySession session = new BluetoothDiscoverySession();
    private final LinkedHashMap<String,BluetoothDevice> known = new LinkedHashMap<>();
    private final Map<String,String> advertisedNames = new HashMap<>();
    private BluetoothAdapter adapter;
    private BluetoothLeScanner scanner;
    private ScanCallback leCallback;
    private BroadcastReceiver receiver;
    private Listener listener;
    private long ticket, permissionTicket, enableTicket;
    private String operationId, phase = "", pairAddress;
    private boolean permissionPrompt, enablePrompt, classicRunning, leRunning;
    private Runnable deadline, stageDeadline;

    BluetoothPrinterDiscovery(Activity activity) { this.activity = activity; }
    boolean active() { return session.active(); }

    void scan(String id, Listener callback) throws Exception { begin(id, null, callback); }
    void pair(String id, String address, Listener callback) throws Exception {
        if (!BluetoothAdapter.checkBluetoothAddress(address)) throw new Exception("عنوان الجهاز غير صالح.");
        begin(id, address, callback);
    }
    private void begin(String id, String address, Listener callback) throws Exception {
        if (permissionPrompt || enablePrompt) throw new Exception("أغلق نافذة إذن أو تشغيل البلوتوث أولًا، ثم أعد المحاولة.");
        BluetoothManager manager = (BluetoothManager)activity.getSystemService(Context.BLUETOOTH_SERVICE);
        adapter = manager == null ? null : manager.getAdapter();
        if (adapter == null) throw new Exception("هذا الجهاز لا يحتوي بلوتوث.");
        ticket = session.begin(id); operationId = id; pairAddress = address; listener = callback; phase = "preparing";
        final long expected = ticket;
        deadline = () -> finish(expected, false, "timeout", "انتهت مهلة انتظار البلوتوث. أعد المحاولة عندما تصبح الطابعة جاهزة.");
        handler.postDelayed(deadline, TOTAL_MS);
        try { prepare(expected); } catch (Exception failure) { fail(expected, failure); }
    }
    private String[] missingPermissions() {
        List<String> result = new ArrayList<>();
        if (Build.VERSION.SDK_INT >= 31) {
            if (activity.checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED) result.add(Manifest.permission.BLUETOOTH_CONNECT);
            if (activity.checkSelfPermission(Manifest.permission.BLUETOOTH_SCAN) != PackageManager.PERMISSION_GRANTED) result.add(Manifest.permission.BLUETOOTH_SCAN);
        } else if (pairAddress == null && activity.checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) result.add(Manifest.permission.ACCESS_FINE_LOCATION);
        return result.toArray(new String[0]);
    }
    private void prepare(long expected) throws Exception {
        if (!session.current(expected)) return;
        String[] missing = missingPermissions();
        if (missing.length > 0) {
            phase = "permission"; permissionPrompt = true; permissionTicket = expected;
            progress(expected, "وافق على إذن الأجهزة القريبة للبحث والإقران.");
            try { activity.requestPermissions(missing, PERMISSIONS); } catch (RuntimeException failure) { permissionPrompt = false; throw failure; } return;
        }
        register(expected);
        if (!adapter.isEnabled()) {
            phase = "enabling"; enablePrompt = true; enableTicket = expected;
            progress(expected, "وافق على تشغيل البلوتوث من نافذة Android.");
            try { activity.startActivityForResult(new Intent(BluetoothAdapter.ACTION_REQUEST_ENABLE), ENABLE); } catch (RuntimeException failure) { enablePrompt = false; throw failure; } return;
        }
        startReady(expected);
    }
    void permissionResult() {
        if (!permissionPrompt) return;
        permissionPrompt = false; final long expected = permissionTicket;
        if (!session.current(expected)) return;
        try {
            if (missingPermissions().length > 0) { finish(expected, false, "permission_denied", "لم يُمنح إذن البحث عن أجهزة البلوتوث. امنح الإذن ثم اضغط بحث."); return; }
            prepare(expected);
        } catch (Exception failure) { fail(expected, failure); }
    }
    void enableResult(int result) {
        if (!enablePrompt) return;
        enablePrompt = false; final long expected = enableTicket;
        if (!session.current(expected)) return;
        try {
            if (result != Activity.RESULT_OK) { finish(expected, false, "bluetooth_disabled", "لم يتم تشغيل البلوتوث."); return; }
            if (adapter.isEnabled()) startReady(expected);
            // Otherwise ACTION_STATE_CHANGED resumes this same operation once ON.
        } catch (Exception failure) { fail(expected, failure); }
    }
    private void register(final long expected) {
        if (receiver != null) return;
        receiver = new BroadcastReceiver() {
            @Override public void onReceive(Context context, Intent intent) {
                if (!session.current(expected)) return;
                try {
                    String action = intent.getAction();
                    if (BluetoothAdapter.ACTION_STATE_CHANGED.equals(action)) {
                        if (adapter.isEnabled() && phase.equals("enabling") && !enablePrompt) startReady(expected);
                        else if (!adapter.isEnabled() && (phase.equals("scanning") || phase.equals("pairing"))) finish(expected, false, "bluetooth_disabled", "توقف البلوتوث أثناء العملية.");
                        return;
                    }
                    BluetoothDevice device = intent.getParcelableExtra(BluetoothDevice.EXTRA_DEVICE);
                    if (BluetoothDevice.ACTION_FOUND.equals(action) || BluetoothDevice.ACTION_NAME_CHANGED.equals(action)) {
                        if (device != null) { add(device, intent.getStringExtra(BluetoothDevice.EXTRA_NAME)); progress(expected, "جارٍ البحث عن الأجهزة القريبة…"); }
                    } else if (BluetoothDevice.ACTION_BOND_STATE_CHANGED.equals(action) && device != null && phase.equals("pairing") && device.getAddress().equals(pairAddress)) {
                        add(device, null);
                        // Inspect the current system state; a broadcast alone is never proof of pairing.
                        int bond = device.getBondState();
                        if (bond == BluetoothDevice.BOND_BONDED) finish(expected, true, "paired", "تم إقران الجهاز. اختره من القائمة لحفظ مسار الطباعة المناسب.");
                        else if (bond == BluetoothDevice.BOND_NONE && intent.getIntExtra(BluetoothDevice.EXTRA_PREVIOUS_BOND_STATE, BluetoothDevice.BOND_NONE) == BluetoothDevice.BOND_BONDING) finish(expected, false, "pair_failed", "لم يكتمل الإقران. تحقق من الطابعة ووافق على نافذة Android ثم أعد المحاولة.");
                    } else if (BluetoothAdapter.ACTION_DISCOVERY_FINISHED.equals(action)) {
                        if (adapter.isDiscovering()) return; // Ignore a late finish from a preceding discovery.
                        classicRunning = false;
                        if (phase.equals("scanning") && !leRunning) finish(expected, true, "complete", "انتهى البحث. اختر الجهاز المطلوب.");
                    }
                } catch (Exception failure) { fail(expected, failure); }
            }
        };
        IntentFilter filter = new IntentFilter();
        filter.addAction(BluetoothDevice.ACTION_FOUND); filter.addAction(BluetoothDevice.ACTION_NAME_CHANGED);
        filter.addAction(BluetoothDevice.ACTION_BOND_STATE_CHANGED); filter.addAction(BluetoothAdapter.ACTION_DISCOVERY_FINISHED); filter.addAction(BluetoothAdapter.ACTION_STATE_CHANGED);
        // Bluetooth broadcasts originate from a privileged system app outside our UID.
        if (Build.VERSION.SDK_INT >= 33) activity.registerReceiver(receiver, filter, Context.RECEIVER_EXPORTED);
        else activity.registerReceiver(receiver, filter);
    }
    private void startReady(final long expected) throws Exception {
        if (!session.current(expected) || phase.equals("scanning") || phase.equals("pairing")) return;
        if (pairAddress == null && Build.VERSION.SDK_INT <= 30 && !locationEnabled()) { finish(expected, false, "location_disabled", "فعّل خدمة الموقع في الجهاز حتى يسمح Android بالبحث عن البلوتوث، ثم أعد البحث."); return; }
        if (pairAddress == null) { known.clear(); advertisedNames.clear(); }
        for (BluetoothDevice device : adapter.getBondedDevices()) add(device, null);
        if (pairAddress != null) {
            BluetoothDevice target = known.get(pairAddress);
            if (target == null) { finish(expected, false, "not_found", "الجهاز غير موجود ضمن نتائج البحث. ابحث عنه أولًا."); return; }
            if (target.getBondState() == BluetoothDevice.BOND_BONDED) { finish(expected, true, "paired", "الجهاز مقترن بالفعل؛ اختره لحفظ الطابعة."); return; }
            phase = "pairing"; stopRadio(); progress(expected, "وافق على طلب الإقران ورمز الطابعة في نافذة Android.");
            if (target.getBondState() != BluetoothDevice.BOND_BONDING && !target.createBond()) { finish(expected, false, "pair_failed", "تعذر بدء الإقران بهذه الطابعة."); return; }
            stageDeadline = () -> finish(expected, false, "pair_timeout", "لم تصل نتيجة الإقران ضمن المهلة. قد تبقى نافذة Android مفتوحة؛ تحقق منها ومن حالة الجهاز قبل المحاولة مجددًا.");
            handler.postDelayed(stageDeadline, PAIR_MS); return;
        }
        phase = "scanning"; progress(expected, "جارٍ البحث عن الأجهزة القريبة…");
        if (adapter.isDiscovering()) { finish(expected, false, "discovery_busy", "يوجد بحث بلوتوث جارٍ في الجهاز. انتظر انتهاءه ثم أعد البحث."); return; }
        classicRunning = adapter.startDiscovery();
        scanner = adapter.getBluetoothLeScanner();
        if (scanner != null) {
            leCallback = new ScanCallback() {
                @Override public void onScanResult(int type, ScanResult result) { handler.post(() -> {
                    if (!session.current(expected) || !phase.equals("scanning")) return;
                    try { if (result != null) { add(result.getDevice(), result.getScanRecord() == null ? null : result.getScanRecord().getDeviceName()); progress(expected, "جارٍ البحث عن الأجهزة القريبة…"); } } catch (Exception failure) { fail(expected, failure); }
                }); }
                @Override public void onScanFailed(int code) { handler.post(() -> {
                    if (!session.current(expected)) return; leRunning = false;
                    if (!classicRunning) finish(expected, false, "scan_failed", "تعذر تشغيل البحث عن أجهزة البلوتوث. أعد المحاولة.");
                }); }
            };
            try { scanner.startScan(null, new ScanSettings.Builder().setScanMode(ScanSettings.SCAN_MODE_LOW_LATENCY).build(), leCallback); leRunning = true; }
            catch (RuntimeException unavailable) { leRunning = false; }
        }
        if (!classicRunning && !leRunning) { finish(expected, false, "scan_failed", "تعذر بدء البحث. تحقق من صلاحيات البلوتوث ثم أعد المحاولة."); return; }
        stageDeadline = () -> finish(expected, true, "complete", "انتهى البحث. اختر الجهاز المطلوب.");
        handler.postDelayed(stageDeadline, SCAN_MS);
    }
    private boolean locationEnabled() {
        LocationManager manager = (LocationManager)activity.getSystemService(Context.LOCATION_SERVICE);
        return manager != null && (Build.VERSION.SDK_INT >= 28 ? manager.isLocationEnabled() : manager.isProviderEnabled(LocationManager.GPS_PROVIDER) || manager.isProviderEnabled(LocationManager.NETWORK_PROVIDER));
    }
    private void add(BluetoothDevice device, String name) {
        if (device == null || !BluetoothAdapter.checkBluetoothAddress(device.getAddress())) return;
        String address = device.getAddress();
        if (known.size() >= 100 && !known.containsKey(address)) return;
        known.put(address, device);
        if (name != null && !name.trim().isEmpty()) advertisedNames.put(address, name.length() > 100 ? name.substring(0,100) : name);
    }
    private JSONArray devices() throws JSONException {
        JSONArray rows = new JSONArray();
        for (BluetoothDevice device : known.values()) {
            String name; int bond, type;
            try { name = device.getName(); bond = device.getBondState(); type = device.getType(); } catch (SecurityException denied) { continue; }
            if (name == null || name.trim().isEmpty()) name = advertisedNames.get(device.getAddress());
            rows.put(new JSONObject().put("name", name == null ? "جهاز بلوتوث" : name).put("address",device.getAddress()).put("bonded",bond == BluetoothDevice.BOND_BONDED).put("bonding",bond == BluetoothDevice.BOND_BONDING).put("transport",type == BluetoothDevice.DEVICE_TYPE_LE ? "le" : type == BluetoothDevice.DEVICE_TYPE_DUAL ? "dual" : "classic"));
        }
        return rows;
    }
    private void progress(long expected, String message) {
        if (!session.current(expected) || listener == null) return;
        try { listener.response(new JSONObject().put("ok",true).put("progress",true).put("operationId",operationId).put("state",phase).put("devices",devices()).put("message",message)); } catch (JSONException ignored) { /* Values above are bounded, non-null local metadata. */ }
    }
    private void fail(long expected, Exception failure) { finish(expected, false, failure instanceof SecurityException ? "permission_denied" : "unavailable", "تعذر تنفيذ عملية البلوتوث. تحقق من الإذن والطابعة ثم أعد المحاولة."); }
    private void stopRadio() {
        if (adapter != null && classicRunning) try { adapter.cancelDiscovery(); } catch (RuntimeException ignored) { }
        if (scanner != null && leCallback != null && leRunning) try { scanner.stopScan(leCallback); } catch (RuntimeException ignored) { }
        classicRunning = false; leRunning = false; scanner = null; leCallback = null;
    }
    private void cleanup() {
        if (deadline != null) handler.removeCallbacks(deadline);
        if (stageDeadline != null) handler.removeCallbacks(stageDeadline);
        stopRadio();
        if (receiver != null) { try { activity.unregisterReceiver(receiver); } catch (IllegalArgumentException ignored) { } receiver = null; }
    }
    private void finish(long expected, boolean ok, String state, String message) {
        if (!session.complete(expected)) return;
        Listener callback = listener; listener = null; cleanup();
        try { if (callback != null) callback.response(new JSONObject().put("ok",ok).put("operationId",operationId).put("state",state).put("devices",devices()).put("message",message)); } catch (JSONException ignored) { }
    }
    boolean cancel(String id) {
        if (!session.owns(id)) return false;
        finish(ticket, true, "cancelled", "تم إيقاف البحث أو متابعة الإقران داخل التطبيق. إذا بقيت نافذة إقران Android، يمكن إلغاؤها من النافذة."); return true;
    }
    void background() { if (session.active() && (phase.equals("scanning") || phase.equals("pairing"))) cancel(operationId); }
    void close() { if (session.active()) cancel(operationId); else cleanup(); }
}
