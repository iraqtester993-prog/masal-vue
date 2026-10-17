package iq.dananir.pos;

import android.Manifest;
import android.app.*;
import android.bluetooth.*;
import android.content.*;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.*;
import android.print.PrintManager;
import android.print.PrintJob;
import android.view.*;
import android.webkit.*;
import android.widget.*;
import androidx.webkit.*;
import org.json.*;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.*;
import java.util.concurrent.*;
import java.util.concurrent.atomic.AtomicBoolean;

public final class MainActivity extends Activity {
    private static final String ORIGIN = "https://pos.dananir-iq.com";
    private WebView web;
    private TextView status;
    private final ExecutorService worker = Executors.newSingleThreadExecutor();
    private final AtomicBoolean busy = new AtomicBoolean();
    private android.content.SharedPreferences prefs;
    private PrintLedger ledger;
    private GeolocationPermissions.Callback locationCallback;
    private String locationOrigin;
    private ValueCallback<Uri[]> imageChooser;
    private boolean destroyed;
    private BluetoothPrinterDiscovery bluetoothDiscovery;
    private final NativeBackDispatch nativeBack = new NativeBackDispatch();
    private Runnable unregisterBackCallback;
    private AlertDialog exitConfirmation;
    private long documentGeneration;
    private long exitConfirmationGeneration;
    private SystemBarPalette appearancePalette = SystemBarPalette.initial();
    private boolean darkAppearance;

    @Override public void onCreate(Bundle saved) {
        super.onCreate(saved);
        PrinterDiagnostics.install(getApplicationContext());
        prefs = getSharedPreferences("printer", MODE_PRIVATE);
        bluetoothDiscovery = new BluetoothPrinterDiscovery(this);
        ledger = new PrintLedger(this);
        LinearLayout root = new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL);
        SystemBarBackground systemBars = new SystemBarBackground(); root.setBackground(systemBars);
        root.setPadding(0, 0, 0, 0);
        root.setOnApplyWindowInsetsListener((v, insets) -> { systemBars.insets(insets); v.setPadding(insets.getSystemWindowInsetLeft(), insets.getSystemWindowInsetTop(), insets.getSystemWindowInsetRight(), insets.getSystemWindowInsetBottom()); return insets.consumeSystemWindowInsets(); });
        // Hosted PWA owns the header and navigation. Native controls are reached through the bridge.
        status = new TextView(this);
        web = new WebView(this); root.addView(web, new LinearLayout.LayoutParams(-1, 0, 1)); setContentView(root);
        WebSettings settings = web.getSettings(); settings.setJavaScriptEnabled(true); settings.setDomStorageEnabled(true);
        settings.setUseWideViewPort(true); settings.setLoadWithOverviewMode(true); settings.setTextZoom(100);
        settings.setUserAgentString(settings.getUserAgentString()+" DananeerPOS/1.4.7");
        settings.setAllowFileAccess(false); settings.setAllowContentAccess(false); settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setGeolocationEnabled(true); settings.setSupportMultipleWindows(false);
        CookieManager.getInstance().setAcceptCookie(true); CookieManager.getInstance().setAcceptThirdPartyCookies(web, false);
        web.setWebViewClient(new WebViewClient() {
            @Override public void onPageStarted(WebView view, String url, Bitmap icon) { documentGeneration++; dismissExitConfirmation(); nativeBack.invalidate(); bluetoothDiscovery.close(); }
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (trusted(request.getUrl())) return false;
                if (request.isForMainFrame()&&request.hasGesture()&&Arrays.asList("https","tel","mailto").contains(request.getUrl().getScheme())){
                    try{startActivity(new Intent(Intent.ACTION_VIEW,request.getUrl()));}catch(ActivityNotFoundException e){tell("لا يوجد تطبيق لفتح هذا الرابط.");}
                }
                return true;
            }
            @Override public void onReceivedSslError(WebView view, android.webkit.SslErrorHandler handler, android.net.http.SslError error) { handler.cancel(); tell("تعذر التحقق من اتصال الموقع الآمن."); }
            @Override public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) { if (request.isForMainFrame()) tell("تعذر فتح نقطة البيع؛ افحص اتصال الإنترنت."); }
        });
        web.setWebChromeClient(new WebChromeClient() {
            @Override public boolean onShowFileChooser(WebView view,ValueCallback<Uri[]> callback,FileChooserParams params) {
                if(!trusted(Uri.parse(view.getUrl()==null?"":view.getUrl()))||params.getMode()!=FileChooserParams.MODE_OPEN){callback.onReceiveValue(null);return true;}
                cancelImageChooser();imageChooser=callback;
                Intent choose=new Intent(Intent.ACTION_OPEN_DOCUMENT).addCategory(Intent.CATEGORY_OPENABLE).setType("image/*")
                    .putExtra(Intent.EXTRA_MIME_TYPES,new String[]{"image/png","image/jpeg"});
                try{startActivityForResult(choose,4);}catch(ActivityNotFoundException unavailable){cancelImageChooser();tell("لا يوجد تطبيق لاختيار الصورة على هذا الجهاز.");}
                return true;
            }
            @Override public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback callback) {
                if (!trusted(Uri.parse(origin))) { callback.invoke(origin, false, false); return; }
                if (checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED || checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED) { callback.invoke(origin, true, true); return; }
                locationCallback = callback; locationOrigin = origin;
                requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION}, 2);
            }
        });
        if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            WebViewCompat.addWebMessageListener(web, "DananeerPrint", Collections.singleton(ORIGIN), (view, message, sourceOrigin, isMainFrame, reply) -> {
                if (!isMainFrame || !trusted(sourceOrigin) || !trusted(Uri.parse(view.getUrl()))) return;
                String requestId = "";
                try {
                    String raw = message.getData();
                    if (raw == null || raw.length() > 4000000) throw new Exception("الوصل كبير جدًا.");
                    JSONObject payload = new JSONObject(raw); requestId = payload.getString("requestId");
                    if (requestId.length() > 128) throw new Exception("مرجع الطلب غير صالح.");
                    String action = payload.optString("action");
                    if(action.equals("deviceIdentity")) {
                        String installation=prefs.getString("installation_id","");
                        if(installation.isEmpty()) {
                            String previous=payload.optString("installationId");
                            installation=previous.matches("(?i)[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}")?previous:UUID.randomUUID().toString();
                            prefs.edit().putString("installation_id",installation).apply();
                        }
                        JSONObject identity=new JSONObject().put("requestId",requestId).put("ok",true).put("installationId",installation)
                            .put("kind",ApprovedPrinters.internal(Build.MANUFACTURER,Build.MODEL)!=null?"pos":"mobile")
                            .put("model",Build.MODEL).put("manufacturer",Build.MANUFACTURER).put("osVersion",Build.VERSION.RELEASE);
                        try { identity.put("proofPublicKey",DeviceProof.publicKey()).put("proofKeyKind","android-keystore"); }
                        catch(Exception unavailable) { identity.put("proofUnavailable",true); }
                        try {
                            String serial=Build.VERSION.SDK_INT<26?Build.SERIAL:null;
                            if(serial!=null&&!serial.isEmpty()&&!serial.equalsIgnoreCase("unknown"))identity.put("serial",serial);
                        }catch(SecurityException unavailable) { /* No phone or device-ID permission is requested. */ }
                        reply.postMessage(identity.toString());return;
                    }
                    if(action.equals("deviceProof")) {
                        if(!prefs.getString("installation_id","").equals(payload.optString("installationId")))throw new Exception("تعريف الجهاز غير متطابق.");
                        reply.postMessage(new JSONObject().put("requestId",requestId).put("ok",true)
                            .put("signature",DeviceProof.sign(payload.optString("challenge"))).toString());return;
                    }
                    if(action.equals("appearance")) {
                        applyAppearance(payload, systemBars);
                        reply.postMessage(response(requestId,new Outcome(true,"تم تطبيق المظهر.","applied")));return;
                    }
                    if (action.equals("printerRegistry")) {
                        if(busy.get())throw new Exception("انتظر انتهاء الطباعة.");
                        JSONArray entries=payload.optJSONArray("printers");
                        if(entries==null||entries.length()>40)throw new Exception("قائمة الطابعات غير صالحة.");
                        prefs.edit().putString("registry",entries.toString()).apply();configureAutomaticPrinter();updateStatus();
                        reply.postMessage(response(requestId,new Outcome(true,"تم تحديث أنواع الطابعات.","saved")));return;
                    }
                    if(action.equals("printerDevices")) {reply.postMessage(pairedDeviceResponse(requestId));return;}
                    if(action.equals("bluetoothScan")||action.equals("bluetoothPairDevice")||action.equals("bluetoothEnable")||action.equals("bluetoothPair")) {
                        if(busy.get())throw new Exception("انتظر انتهاء الطباعة قبل البحث أو الإقران.");
                        final String id=requestId;
                        BluetoothPrinterDiscovery.Listener callback=value->{
                            try { value.put("requestId",id);if(!destroyed&&trusted(Uri.parse(web.getUrl()==null?"":web.getUrl())))reply.postMessage(value.toString()); }
                            catch(Exception unavailable) { /* The page may close while an OS permission prompt is visible. */ }
                        };
                        String operation=payload.optString("operationId",UUID.randomUUID().toString());
                        if(action.equals("bluetoothPairDevice"))bluetoothDiscovery.pair(operation,payload.optString("address"),callback);
                        else bluetoothDiscovery.scan(operation,callback);
                        return;
                    }
                    if(action.equals("bluetoothCancel")) {
                        boolean cancelled=bluetoothDiscovery.cancel(payload.optString("operationId"));
                        reply.postMessage(response(requestId,new Outcome(true,cancelled?"تم إيقاف متابعة البلوتوث.":"لا توجد عملية مطابقة قيد المتابعة.","cancelled")));return;
                    }
                    if(action.equals("printerSelect")) {
                        if(busy.get())throw new Exception("انتظر انتهاء الطباعة.");
                        if(bluetoothDiscovery.active())throw new Exception("أوقف البحث أو أكمل الإقران قبل حفظ الطابعة.");
                        selectRegisteredPrinter(payload);updateStatus();
                        reply.postMessage(response(requestId,new Outcome(true,directAvailable()?"تم حفظ الطابعة للطباعة المباشرة.":"تم حفظ الطابعة؛ طباعتها حصريًا من نافذة المتصفح.","saved")));return;
                    }
                    if (action.equals("capabilities")) { reply.postMessage(capabilities(requestId)); return; }
                    if (action.equals("printerSettings") || action.equals("printerTest") || action.equals("diagnostics")) {
                        if (busy.get()) { reply.postMessage(response(requestId, new Outcome(false,"انتظر انتهاء محاولة الطباعة الحالية.","busy"))); return; }
                        if(action.equals("printerTest")&&bluetoothDiscovery.active())throw new Exception("أوقف البحث أو أكمل الإقران قبل اختبار الطابعة.");
                        if (action.equals("printerSettings")) chooseMode();
                        else if (action.equals("printerTest")) testPrint();
                        else showDiagnostics();
                        reply.postMessage(response(requestId,new Outcome(true,"تم فتح خيارات الطابعة.","opened"))); return;
                    }
                    if (!action.equals("print") && !action.equals("browserPrint")) throw new Exception("طلب طباعة غير صالح.");
                    String jobId = payload.getString("jobId");
                    if (!jobId.matches("(?:sale-[0-9]+-(?:[0-9]+|copy-[a-fA-F0-9-]{36})|digital-[0-9a-zA-Z-]+)")) throw new Exception("مرجع الطباعة غير صالح.");
                    final String id = requestId;
                    if(action.equals("browserPrint")) {
                        JSONArray browserBlocks=payload.optJSONArray("blocks");
                        browserPrint(jobId,browserBlocks,outcome->{
                            if(outcome.state.equals("duplicate")&&jobId.startsWith("digital-"))new AlertDialog.Builder(this).setTitle("نسخة أخرى من الإيصال").setMessage("تحقق من الورقة أولًا. هل تريد نسخة أخرى من نفس العملية دون بيع جديد؟").setPositiveButton("طباعة نسخة",(d,w)->browserPrint(jobId+"-copy-"+UUID.randomUUID(),browserBlocks,result->reply.postMessage(response(id,result)))).setNegativeButton("إلغاء",(d,w)->reply.postMessage(response(id,outcome))).setOnCancelListener(d->reply.postMessage(response(id,outcome))).show();
                            else reply.postMessage(response(id,outcome));
                        });return;
                    }
                    bluetoothDiscovery.close();
                    printFromPage(jobId, payload.optJSONArray("blocks"), id, reply);
                } catch (Exception error) { reply.postMessage(response(requestId, new Outcome(false, "تعذر قراءة طلب الطباعة: " + error.getMessage(), "not_sent"))); }
            });
        } else tell("حدّث Android System WebView لتفعيل طباعة الوصل؛ اختبار الطابعة متاح.");
        configureAutomaticPrinter();
        prefs.edit().putInt("width",receiptWidthPixels()).apply();
        updateStatus();
        if (Build.VERSION.SDK_INT >= 33) {
            android.window.OnBackInvokedCallback callback = this::handleNativeBack;
            getOnBackInvokedDispatcher().registerOnBackInvokedCallback(android.window.OnBackInvokedDispatcher.PRIORITY_DEFAULT, callback);
            unregisterBackCallback = () -> getOnBackInvokedDispatcher().unregisterOnBackInvokedCallback(callback);
        }
        boolean isolatedUi=getIntent().getBooleanExtra("isolated_ui",false)&&(getApplicationInfo().flags&android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE)!=0;
        web.loadUrl(isolatedUi?"about:blank":ORIGIN + "/dashboard");
    }
    static boolean trusted(Uri uri) { return uri != null && "https".equals(uri.getScheme()) && "pos.dananir-iq.com".equals(uri.getHost()) && (uri.getPort() == -1 || uri.getPort() == 443); }
    private BluetoothAdapter bluetoothAdapter() throws Exception {
        BluetoothManager manager=(BluetoothManager)getSystemService(BLUETOOTH_SERVICE);
        BluetoothAdapter adapter=manager==null?null:manager.getAdapter();
        if(adapter==null)throw new Exception("هذا الجهاز لا يحتوي بلوتوث.");
        return adapter;
    }
    private void requireBluetoothPermission() throws Exception {
        if(Build.VERSION.SDK_INT>=31&&checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT)!=PackageManager.PERMISSION_GRANTED){
            requestPermissions(new String[]{Manifest.permission.BLUETOOTH_CONNECT},3);
            throw new Exception("وافق على إذن الأجهزة القريبة، ثم أعد الضغط على الزر.");
        }
    }
    private String pairedDeviceResponse(String requestId) throws Exception {
        if(Build.VERSION.SDK_INT>=31&&checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT)!=PackageManager.PERMISSION_GRANTED)return new JSONObject().put("requestId",requestId).put("ok",true).put("permissionRequired",true).put("devices",new JSONArray()).put("message","اضغط بحث ووافق على إذن الأجهزة القريبة.").toString();
        BluetoothAdapter adapter=bluetoothAdapter();JSONArray devices=new JSONArray();
        if(adapter.isEnabled())for(BluetoothDevice d:adapter.getBondedDevices())devices.put(new JSONObject().put("name",d.getName()==null?"Bluetooth":d.getName()).put("address",d.getAddress()).put("bonded",true).put("transport",d.getType()==BluetoothDevice.DEVICE_TYPE_LE?"le":d.getType()==BluetoothDevice.DEVICE_TYPE_DUAL?"dual":"classic"));
        return new JSONObject().put("requestId",requestId).put("ok",true).put("devices",devices).put("enabled",adapter.isEnabled()).put("message",adapter.isEnabled()?"اختر طابعتك من الأجهزة المقترنة.":"شغّل البلوتوث أولًا.").toString();
    }
    private JSONArray printerRegistry(){try{return new JSONArray(prefs.getString("registry","[]"));}catch(Exception e){return new JSONArray();}}
    private boolean enabledPrinter(JSONObject row){
        if(row==null)return false;Object value=row.opt("enabled");
        return !Boolean.FALSE.equals(value)&&!(value instanceof Number&&((Number)value).intValue()==0)&&!"false".equalsIgnoreCase(String.valueOf(value))&&!"0".equals(String.valueOf(value));
    }
    private boolean printerMatches(String text,String match){
        return ApprovedPrinters.matches(text,match);
    }
    private String registeredInternal(){
        String hardware=ApprovedPrinters.internal(Build.MANUFACTURER,Build.MODEL);
        if(!prefs.contains("registry"))return hardware;
        JSONArray registry=printerRegistry();
        for(int i=0;i<registry.length();i++){JSONObject row=registry.optJSONObject(i);if(enabledPrinter(row)&&row.optString("profile").equals(hardware)&&printerMatches(Build.MANUFACTURER+" "+Build.MODEL,row.optString("match")))return hardware;}
        return null;
    }
    private boolean approvedStoredBluetooth(){
        String profile=prefs.getString("bluetooth_type","");if(!ApprovedPrinters.approved(profile))return false;
        if(prefs.getString("bluetooth_transport","").equals("le"))return false;
        if(!prefs.contains("registry"))return profile.equals(ApprovedPrinters.bluetooth(prefs.getString("name","")));
        JSONArray registry=printerRegistry();
        for(int i=0;i<registry.length();i++){JSONObject row=registry.optJSONObject(i);if(enabledPrinter(row)&&row.optString("profile").equals(profile)&&printerMatches(prefs.getString("name",""),row.optString("match")))return true;}
        return false;
    }
    private void selectRegisteredPrinter(JSONObject payload) throws Exception {
        if(payload.optBoolean("browser")) {
            prefs.edit().putString("mode","browser").putBoolean("browser_override",true).putString("name","طباعة المتصفح / النظام").remove("address").putString("bluetooth_type","").apply();return;
        }
        if(payload.optBoolean("internal")){
            prefs.edit().putBoolean("browser_override",false).apply();
            String internal=registeredInternal();
            if(internal==null||!PrinterDiscovery.available(this,internal)){prefs.edit().putString("mode","browser").apply();throw new Exception("لم تُكتشف طابعة داخلية مدعومة؛ استخدم الطباعة بالمتصفح.");}
            prefs.edit().putString("mode",internal).putInt("width",ApprovedPrinters.receiptWidthPixels(internal)).apply();return;
        }
        requireBluetoothPermission();
        if(Build.VERSION.SDK_INT>=31&&checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT)!=PackageManager.PERMISSION_GRANTED)throw new SecurityException("إذن البلوتوث غير متاح.");
        BluetoothAdapter adapter=bluetoothAdapter();
        if(!adapter.isEnabled())throw new Exception("شغّل البلوتوث أولًا.");
        BluetoothDevice device=null;
        for(BluetoothDevice d:adapter.getBondedDevices())if(d.getAddress().equals(payload.optString("address")))device=d;
        if(device==null)throw new Exception("الطابعة ليست ضمن الأجهزة المقترنة.");
        if(payload.optBoolean("other")){
            prefs.edit().putString("mode","browser").putBoolean("browser_override",true).putString("name",device.getName()).putString("address",device.getAddress()).apply();return;
        }
        JSONObject chosen=null;JSONArray registry=printerRegistry();
        for(int i=0;i<registry.length();i++){JSONObject row=registry.optJSONObject(i);if(enabledPrinter(row)&&row.optString("id").equals(payload.optString("printerId")))chosen=row;}
        if(chosen==null||!ApprovedPrinters.approved(chosen.optString("profile"))||!printerMatches(device.getName(),chosen.optString("match")))throw new Exception("اسم الطابعة المتصلة لا يطابق النوع المحدد؛ اختر النوع الصحيح أو أخرى.");
        if(device.getType()==BluetoothDevice.DEVICE_TYPE_LE)throw new Exception("هذا الجهاز يستخدم Bluetooth LE فقط؛ اختر طباعة المتصفح / النظام.");
        prefs.edit().putString("mode","bluetooth").putBoolean("browser_override",false).putString("bluetooth_type",chosen.getString("profile")).putString("bluetooth_transport",device.getType()==BluetoothDevice.DEVICE_TYPE_DUAL?"dual":"classic").putString("address",device.getAddress()).putString("name",device.getName()).putInt("width",ApprovedPrinters.receiptWidthPixels(chosen.optString("profile"))).apply();
        String key="device_"+device.getAddress()+"_";
        prefs.edit().putString("image_mode",prefs.getString(key+"image_mode","raster")).putBoolean("conservative",prefs.getBoolean(key+"conservative",false)).putBoolean("status_support",prefs.getBoolean(key+"status_support",false)).apply();
    }
    private void configureAutomaticPrinter() {
        if(prefs.getBoolean("browser_override",false)){prefs.edit().putString("mode","browser").apply();return;}
        String type=prefs.getString("bluetooth_type","");
        if(type.isEmpty())type=ApprovedPrinters.bluetooth(prefs.getString("name",""));
        if(prefs.getString("mode","").equals("bluetooth") && approvedStoredBluetooth() && !prefs.getString("address","").isEmpty()) { prefs.edit().putString("bluetooth_type",type).putInt("width",ApprovedPrinters.receiptWidthPixels(type)).apply(); return; }
        String internal=registeredInternal();
        if(internal!=null && PrinterDiscovery.available(this,internal)) { prefs.edit().putString("mode",internal).putInt("width",ApprovedPrinters.receiptWidthPixels(internal)).apply(); return; }
        prefs.edit().putString("mode","browser").apply();
    }
    private String selectedBluetoothProfile() {
        return prefs.getString("mode","").equals("bluetooth") && approvedStoredBluetooth() ? prefs.getString("bluetooth_type","") : "";
    }
    private int receiptWidthPixels() { return ApprovedPrinters.receiptWidthPixels(selectedBluetoothProfile()); }
    private int paperWidthMm() { return ApprovedPrinters.paperWidthMm(selectedBluetoothProfile()); }
    private boolean directAvailable() {
        String mode=prefs.getString("mode","");
        if(mode.equals("bluetooth"))return approvedStoredBluetooth() && !prefs.getString("address","").isEmpty();
        return mode.equals(registeredInternal()) && PrinterDiscovery.available(this,mode);
    }
    private String modeName() {
        switch (prefs.getString("mode", "")) {
            case "browser": return prefs.getString("name", "طباعة المتصفح / النظام") + " · المتصفح";
            case "bluetooth": return prefs.getString("name", "طابعة بلوتوث ESC/POS");
            case "sunmi": return "SUNMI";
            case "rovoo": return "ROVOO";
            case "nyx": return "B1008 / NYX";
            case "incar": return "1008 / Incar";
            case "senraise": return "Senraise / البصرة";
            case "zcs": return "Z91 / ZCS";
            default: return "لم تُحدد طابعة";
        }
    }
    private void updateStatus() { status.setText("1.4.7 · " + modeName() + " · " + paperWidthMm() + "mm"); if(web!=null && trusted(Uri.parse(web.getUrl()==null?"":web.getUrl())))web.evaluateJavascript("window.dispatchEvent(new Event('dananeer:printer-change'))",null); }
    private String capabilities(String requestId) throws JSONException {
        String internal=registeredInternal();
        boolean internalAvailable=internal!=null&&PrinterDiscovery.available(this,internal);
        return new JSONObject().put("requestId",requestId).put("ok",true).put("state","ready")
            .put("printerSelectionVersion",1).put("internalPrinterAvailable",internalAvailable).put("internalPrinterMode",internal==null?"":internal).put("internalPrinterName",internalAvailable?Build.MODEL:"")
            .put("bridgeVersion",3).put("appVersion","1.4.7").put("appearanceVersion",2).put("nativeBackVersion",1).put("exitConfirmationVersion",1).put("deviceProofVersion",1).put("paperWidthMm",paperWidthMm()).put("receiptWidthPixels",receiptWidthPixels()).put("bluetoothPrinterProfiles",new JSONArray(ApprovedPrinters.BLUETOOTH_TYPES)).put("bluetoothDiscoveryVersion",1)
            .put("printRoute",directAvailable()?"direct":"browser").put("browserPrintSupported",true)
            .put("printerConfigured",!prefs.getString("mode","").isEmpty()).put("printerMode",prefs.getString("mode",""))
            .put("printerName",modeName()).put("busy",busy.get()).toString();
    }
    private void tell(String message) { if (!isFinishing() && !isDestroyed()) new AlertDialog.Builder(this).setTitle("دنانير").setMessage(message).setPositiveButton("حسنًا", null).show(); }
    private void chooseMode() {
        if(busy.get()) { tell("انتظر انتهاء محاولة الطباعة الحالية."); return; }
        if(prefs.getString("mode","").isEmpty()) { choosePrinterType(); return; }
        String[] actions=prefs.getString("mode","").equals("bluetooth")
            ?new String[]{"تغيير الطابعة","إعدادات الطابعة الحالية","اختبار الطابعة"}
            :new String[]{"تغيير الطابعة","اختبار الطابعة"};
        new AlertDialog.Builder(this).setTitle(modeName()).setItems(actions,(d,which)->{
            if(which==0)choosePrinterType();
            else if(actions[which].equals("إعدادات الطابعة الحالية"))configureBluetooth();
            else if(actions[which].equals("اختبار الطابعة"))testPrint();
            else showDiagnostics();
        }).show();
    }
    private void choosePrinterType() {
        if (busy.get()) { tell("انتظر انتهاء محاولة الطباعة الحالية."); return; }
        new AlertDialog.Builder(this).setTitle("ربط الطابعة")
            .setItems(new String[]{"طابعة الجهاز الداخلية — تلقائي","طابعة Bluetooth معتمدة","طباعة المتصفح / النظام"},(dialog,choice)->{
                if(choice==1) { pairedPrinters(); return; }
                if(choice==2) { prefs.edit().putString("mode","browser").putBoolean("browser_override",true).apply();updateStatus();return; }
                String internal=registeredInternal();
                if(internal!=null && PrinterDiscovery.available(this,internal)) {
                    if(prefs.getString("mode","").equals("bluetooth"))saveBluetoothProfile();
                    prefs.edit().putString("mode",internal).putBoolean("browser_override",false).putInt("width",ApprovedPrinters.receiptWidthPixels(internal)).apply(); updateStatus();
                    tell("تم اختيار طابعة الجهاز. اختبر خروج الوصل كاملًا من خيارات الطابعة.");
                } else {prefs.edit().putString("mode","browser").apply();updateStatus();tell("الطابعة الداخلية غير متاحة ضمن الأجهزة المعتمدة؛ استخدم طباعة المتصفح / النظام. الجهاز المعروف قد يحتاج خدمة المورد.");}
            }).setNegativeButton("إغلاق",null).show();
    }
    private void chooseAdvancedPrinterType() {
        if (busy.get()) { tell("انتظر انتهاء محاولة الطباعة الحالية."); return; }
        List<String> modes = PrinterDiscovery.selectable(this);
        String[] labels = new String[modes.size()];
        for (int i = 0; i < modes.size(); i++) labels[i] = PrinterDiscovery.label(modes.get(i)) + (i == 0 ? " — الأجهزة المقترنة عند دعم ESC/POS" : modes.get(i).equals("zcs") ? " — لأجهزة Z91 فقط" : PrinterDiscovery.available(this, modes.get(i)) ? " — خدمة مكتشفة" : " — اختيار يدوي وفحص الاتصال");
        new AlertDialog.Builder(this).setTitle("اختيار نوع الطابعة").setItems(labels, (d, which) -> {
            if (which == 0) { pairedPrinters(); return; }
            if(prefs.getString("mode","").equals("bluetooth"))saveBluetoothProfile();
            prefs.edit().putString("mode", modes.get(which)).remove("address").remove("name").putInt("width", ApprovedPrinters.receiptWidthPixels(modes.get(which))).apply(); updateStatus();
        }).setNeutralButton("فحص الجهاز", (d,w) -> showDiagnostics()).show();
    }
    private void showDiagnostics() {
        String report = PrinterDiagnostics.report(this);
        new AlertDialog.Builder(this).setTitle("فحص الجهاز وخدمات الطباعة").setMessage(report)
            .setPositiveButton("نسخ الفحص", (d,w) -> { ((android.content.ClipboardManager) getSystemService(CLIPBOARD_SERVICE)).setPrimaryClip(ClipData.newPlainText("فحص دنانير", report)); Toast.makeText(this, "تم نسخ نتيجة الفحص", Toast.LENGTH_SHORT).show(); })
            .setNeutralButton("مشاركة", (d,w) -> startActivity(Intent.createChooser(new Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, report), "مشاركة فحص الجهاز")))
            .setNegativeButton("إغلاق", null).show();
    }
    private String approvedBluetoothProfile(String name) {
        if(!prefs.contains("registry"))return ApprovedPrinters.bluetooth(name);
        JSONArray registry=printerRegistry();
        for(int i=0;i<registry.length();i++){JSONObject row=registry.optJSONObject(i);if(enabledPrinter(row)&&ApprovedPrinters.approved(row.optString("profile"))&&printerMatches(name,row.optString("match")))return row.optString("profile");}
        return null;
    }
    private void pairedPrinters() {
        if(busy.get()){tell("انتظر انتهاء الطباعة.");return;}
        if(Build.VERSION.SDK_INT>=31&&checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT)!=PackageManager.PERMISSION_GRANTED){requestPermissions(new String[]{Manifest.permission.BLUETOOTH_CONNECT},1);return;}
        try {
            BluetoothAdapter adapter=bluetoothAdapter();
            if(!adapter.isEnabled()){showBluetoothSearch();return;}
            List<BluetoothDevice> devices=new ArrayList<>(adapter.getBondedDevices());
            if(devices.isEmpty()){showBluetoothSearch();return;}
            String[] labels=new String[devices.size()];
            for(int i=0;i<devices.size();i++)labels[i]=devices.get(i).getName()+"\n"+devices.get(i).getAddress();
            new AlertDialog.Builder(this).setTitle("أجهزة البلوتوث المقترنة")
                .setItems(labels,(dialog,index)->saveDiscoveredPrinter(devices.get(index)))
                .setNeutralButton("بحث عن طابعة",(dialog,index)->showBluetoothSearch()).setNegativeButton("إغلاق",null).show();
        } catch(Exception unavailable){tell("تعذر قراءة البلوتوث. تحقق من إذن الأجهزة القريبة.");}
    }
    private void saveDiscoveredPrinter(BluetoothDevice device) {
        try {
            String profile=approvedBluetoothProfile(device.getName());
            if(profile==null||device.getType()==BluetoothDevice.DEVICE_TYPE_LE){
                prefs.edit().putString("mode","browser").putBoolean("browser_override",true).putString("name",device.getName()).putString("address",device.getAddress()).remove("bluetooth_type").apply();
                updateStatus();tell("هذا الجهاز متاح عبر نافذة طباعة النظام عند وجود خدمة طباعة مناسبة. لا يُستخدم للطباعة المباشرة.");return;
            }
            rememberBluetooth(device,profile);
        } catch(SecurityException denied){tell("إذن الأجهزة القريبة مطلوب لحفظ الطابعة.");}
    }
    private void showBluetoothSearch() {
        if(busy.get()){tell("انتظر انتهاء الطباعة.");return;}
        LinearLayout body=new LinearLayout(this);body.setOrientation(LinearLayout.VERTICAL);body.setPadding(24,12,24,12);
        TextView progress=new TextView(this);progress.setText("جارٍ تجهيز البحث…");body.addView(progress);
        ListView list=new ListView(this);body.addView(list,new LinearLayout.LayoutParams(-1,Math.round(280*getResources().getDisplayMetrics().density)));
        ArrayList<String> labels=new ArrayList<>();ArrayList<JSONObject> rows=new ArrayList<>();
        ArrayAdapter<String> entries=new ArrayAdapter<>(this,android.R.layout.simple_list_item_1,labels);list.setAdapter(entries);
        String[] operation={UUID.randomUUID().toString()};boolean[] pairing={false};
        AlertDialog dialog=new AlertDialog.Builder(this).setTitle("أجهزة البلوتوث القريبة").setView(body).setNegativeButton("إغلاق",null).create();
        BluetoothPrinterDiscovery.Listener callback=value->{
            if(!dialog.isShowing())return;
            progress.setText(value.optString("message"));rows.clear();labels.clear();
            JSONArray found=value.optJSONArray("devices");
            if(found!=null)for(int i=0;i<found.length();i++){JSONObject row=found.optJSONObject(i);if(row==null)continue;rows.add(row);labels.add(row.optString("name")+"\n"+row.optString("address")+" · "+(row.optBoolean("bonded")?"مقترن":"غير مقترن"));}
            entries.notifyDataSetChanged();
            if(!value.optBoolean("progress"))pairing[0]=false;
        };
        list.setOnItemClickListener((parent,view,index,item)->{
            if(pairing[0]||index>=rows.size())return;
            JSONObject row=rows.get(index);
            try {
                String address=row.optString("address");boolean bonded=row.optBoolean("bonded");
                bluetoothDiscovery.cancel(operation[0]);
                if(bonded){saveDiscoveredPrinter(bluetoothAdapter().getRemoteDevice(address));dialog.dismiss();return;}
                operation[0]=UUID.randomUUID().toString();pairing[0]=true;bluetoothDiscovery.pair(operation[0],address,callback);
            } catch(Exception failure){pairing[0]=false;progress.setText(failure.getMessage());}
        });
        dialog.setOnDismissListener(value->bluetoothDiscovery.cancel(operation[0]));dialog.show();
        try{bluetoothDiscovery.scan(operation[0],callback);}catch(Exception failure){progress.setText(failure.getMessage());}
    }
    private void rememberBluetooth(BluetoothDevice device,String type) {
            if(Build.VERSION.SDK_INT>=31&&checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT)!=PackageManager.PERMISSION_GRANTED){tell("فعّل إذن الأجهزة القريبة قبل حفظ الطابعة.");return;}
            if(!type.equals(approvedBluetoothProfile(device.getName()))||device.getType()==BluetoothDevice.DEVICE_TYPE_LE){tell("هذه الطابعة غير معتمدة للطباعة المباشرة. استخدم طباعة المتصفح / النظام.");return;}
            if(prefs.getString("mode","").equals("bluetooth"))saveBluetoothProfile();
            prefs.edit().putString("mode", "bluetooth").putBoolean("browser_override",false).putString("bluetooth_type",type).putString("bluetooth_transport",device.getType()==BluetoothDevice.DEVICE_TYPE_DUAL?"dual":"classic").putString("address", device.getAddress()).putString("name", device.getName()).apply();
            String key="device_"+device.getAddress()+"_";
            prefs.edit().putInt("width",ApprovedPrinters.receiptWidthPixels(type)).putString("image_mode",prefs.getString(key+"image_mode","raster"))
                .putBoolean("conservative",prefs.getBoolean(key+"conservative",false)).putBoolean("status_support",prefs.getBoolean(key+"status_support",false)).apply();
            updateStatus();tell("تم حفظ الطابعة ونوعها. اختبر الوصل قبل البيع.");
    }
    private void chooseImageMode() {
        new AlertDialog.Builder(this).setTitle("صور الوصل — جرّب الاختبار قبل البيع")
            .setItems(new String[]{"قياسي — GS v 0", "بديل — ESC * إذا الوصل ناقص أو لا يطبع"}, (d,which) -> {
                prefs.edit().putString("image_mode",which==0 ? "raster":"columns").apply(); saveBluetoothProfile(); updateStatus();
            }).show();
    }
    private void configureBluetooth() {
        if(busy.get()) { tell("انتظر انتهاء محاولة الطباعة الحالية."); return; }
        String images=prefs.getString("image_mode","raster").equals("columns")?"ESC *":"GS v 0";
        String[] items={"صور الوصل: "+images,
            "الإرسال: "+(prefs.getBoolean("conservative",false)?"محافظ":"متوازن"),
            "قراءة الحالة: "+(prefs.getBoolean("status_support",false)?"مفعلة":"معطلة"),"اختبار الوصل الكامل"};
        new AlertDialog.Builder(this).setTitle("إعدادات الطابعة — " + paperWidthMm() + " ملم").setItems(items,(d,which)->{
            if(which==0)chooseImageMode();
            else if(which==1)new AlertDialog.Builder(this).setTitle("سرعة إرسال الصور").setItems(new String[]{"متوازن","محافظ — للطابعة التي تتوقف وسط الوصل"},(dialog,speed)->{prefs.edit().putBoolean("conservative",speed==1).apply();saveBluetoothProfile();updateStatus();}).show();
            else if(which==2)new AlertDialog.Builder(this).setTitle("فحص الحالة اختياري؛ يعتمد دعم الطابعة").setItems(new String[]{"معطل","مفعل — فحص الورق والغطاء بمهلة محددة"},(dialog,enabled)->{prefs.edit().putBoolean("status_support",enabled==1).apply();saveBluetoothProfile();}).show();
            else testPrintRows(true);
        }).setNegativeButton("إغلاق",null).show();
    }
    private void saveBluetoothProfile() {
        String key="device_"+prefs.getString("address","")+"_";
        prefs.edit().putInt(key+"width",ApprovedPrinters.receiptWidthPixels(prefs.getString("bluetooth_type",""))).putString(key+"image_mode",prefs.getString("image_mode","raster"))
            .putBoolean(key+"conservative",prefs.getBoolean("conservative",false)).putBoolean(key+"status_support",prefs.getBoolean("status_support",false)).apply();
    }
    private void testPrint() {
        if(!directAvailable()){
            JSONArray blocks=new JSONArray();try{blocks.put(new JSONObject().put("text","دنانير — اختبار الطابعة 58 ملم"));blocks.put(new JSONObject().put("text","هذا اختبار دون بيع أو خصم. نهاية الاختبار."));}catch(JSONException e){tell(e.getMessage());return;}
            browserPrint("digital-test-"+UUID.randomUUID(),blocks,outcome->tell(outcome.message));return;
        }
        if (busy.get()) { tell("انتظر انتهاء محاولة الطباعة الحالية."); return; }
        new AlertDialog.Builder(this).setTitle("اختبار بدون بيع أو خصم")
            .setItems(new String[]{"اختبار قصير", "وصل طويل — 20 سطرًا ونهاية واضحة"}, (d,which) -> testPrintRows(which==1)).show();
    }
    private void testPrintRows(boolean longReceipt) {
        try {
            JSONArray blocks = new JSONArray();
            for (String text : new String[]{"دنانير", "اختبار الطباعة المباشرة", "العربية — Cairo Bold", "المبلغ: 6,000 د.ع", "1234 5678 9012", "TEST ONLY — لا توجد عملية بيع", "إذا خرجت الورقة بوضوح فالربط الأولي يعمل"}) blocks.put(new JSONObject().put("text", text));
            blocks.put(new JSONObject().put("text","الورق: " + paperWidthMm() + " ملم — عرض الطباعة: " + receiptWidthPixels() + " نقطة"));
            if (longReceipt) {
                for (int row=1; row<=20; row++) blocks.put(new JSONObject().put("text",String.format(Locale.ROOT,"اختبار %02d / 20 — 1234 5678 9012",row)));
                blocks.put(new JSONObject().put("text","نهاية الوصل كاملة — END 20/20"));
            }
            if(directAvailable())submit("test-" + UUID.randomUUID(), blocks, result -> tell(result.message));
            else browserPrint("test-"+UUID.randomUUID(),blocks,result->tell(result.message));
        } catch (Exception error) { tell("تعذر تجهيز الاختبار."); }
    }
    interface Callback { void complete(Outcome result); }
    private void printFromPage(String jobId, JSONArray blocks, String id, JavaScriptReplyProxy reply) {
        Callback finish = result -> {
            try { reply.postMessage(response(id, result)); }
            catch (RuntimeException failure) { PrinterDiagnostics.record(getApplicationContext(), "web-reply", failure); }
            // Page shows the print outcome beside its recovery controls.
        };
        submit(jobId, blocks, result -> {
            if (result.state.equals("duplicate") && jobId.startsWith("digital-")) {
                new AlertDialog.Builder(this).setTitle("نسخة أخرى من الإيصال")
                    .setMessage("هذا الإيصال أُرسل سابقًا. افحص الورقة أولًا. هل تريد طباعة نسخة أخرى من نفس العملية؟")
                    .setPositiveButton("طباعة نسخة", (d,w) -> submit(jobId + "-copy-" + UUID.randomUUID(), blocks, finish))
                    .setNegativeButton("إلغاء", (d,w) -> reply.postMessage(response(id, result)))
                    .setOnCancelListener(d -> reply.postMessage(response(id, result))).show();
            } else finish.complete(result);
        });
    }
    static final class Outcome {
        final boolean ok; final String message; final String state;
        Outcome(boolean ok, String message, String state) { this.ok=ok; this.message=message; this.state=state; }
    }
    private static String response(String id, Outcome result) {
        try { return new JSONObject().put("requestId", id).put("ok", result.ok).put("message", result.message).put("state", result.state).toString(); } catch (JSONException error) { return "{}"; }
    }
    private void submit(String jobId, JSONArray blocks, Callback callback) {
        String mode = prefs.getString("mode", ""), address = prefs.getString("address", ""); int width = receiptWidthPixels();
        if (!directAvailable()) { callback.complete(new Outcome(false, "هذا الجهاز أو نوع الطابعة يحتاج طباعة المتصفح / النظام؛ لم يُرسل الوصل.", "not_sent")); return; }
        if (!busy.compareAndSet(false, true)) { callback.complete(new Outcome(false, "توجد محاولة طباعة جارية.", "not_sent")); return; }
        status.setText("جارٍ تجهيز وإرسال الوصل…");
        worker.execute(() -> {
            List<Bitmap> strips = new ArrayList<>(); AtomicBoolean sent = new AtomicBoolean(); Outcome result;
            try {
                if (blocks == null) throw new Exception("لا يوجد محتوى للطباعة.");
                String hash = hex(MessageDigest.getInstance("SHA-256").digest(jobId.getBytes(StandardCharsets.UTF_8)));
                if (!jobId.startsWith("test-") && ledger.contains(hash)) {
                    runOnUiThread(() -> { busy.set(false); updateStatus(); if (!isFinishing()) callback.complete(new Outcome(false, "هذا الوصل أُرسل سابقًا أو نتيجته غير مؤكدة. تحقق من الورقة؛ لا تعاود البيع. استخدم إجراءات إعادة الطباعة في النظام.", "duplicate")); });
                    return;
                }
                strips = ReceiptRenderer.render(this, blocks, width);
                new PrinterEngine(this).print(mode, address, strips, () -> {
                    // Save BEFORE sending. A process crash must never cause an automatic duplicate.
                    if (!jobId.startsWith("test-")) ledger.submit(hash);
                    sent.set(true);
                });
                if (!jobId.startsWith("test-")) ledger.accepted(hash);
                result = new Outcome(true, "تم إرسال الوصل للطابعة. تحقق من خروج الورقة، ثم أكد نجاح الطباعة داخل النظام إن ظهر زر التأكيد.", "accepted");
            } catch (Exception | LinkageError error) {
                PrinterDiagnostics.record(getApplicationContext(), "print/" + mode, error);
                result = new Outcome(false, (sent.get() ? "نتيجة الطباعة غير مؤكدة؛ قد تكون الورقة خرجت. تحقق قبل إعادة المحاولة. " : "لم يتم إرسال الوصل. ") + (error instanceof SecurityException ? "فعّل إذن الأجهزة القريبة من إعدادات التطبيق." : error.getMessage()), sent.get() ? "unknown" : "not_sent");
            } finally { for (Bitmap bitmap : strips) if (!bitmap.isRecycled()) bitmap.recycle(); }
            final Outcome completed = result;
            runOnUiThread(() -> { busy.set(false); updateStatus(); if (!isFinishing()) callback.complete(completed); });
        });
    }
    private void browserPrint(String jobId, JSONArray blocks, Callback callback) {
        if(!busy.compareAndSet(false,true)){callback.complete(new Outcome(false,"توجد محاولة طباعة جارية.","not_sent"));return;}
        worker.execute(()->{
            List<Bitmap> strips=new ArrayList<>();
            try {
                String hash=hex(MessageDigest.getInstance("SHA-256").digest(jobId.getBytes(StandardCharsets.UTF_8)));
                if(ledger.contains(hash))throw new IllegalStateException("هذا الوصل أُرسل سابقًا. تحقق من الورقة واستخدم إذن إعادة الطباعة لنفس العملية.");
                if(blocks==null)throw new Exception("الوصل غير جاهز.");
                // The system-print PDF adapter has its own fixed 58 mm / 384 px layout.
                strips=ReceiptRenderer.render(this,blocks,384);
                final List<Bitmap> receipt=strips;
                runOnUiThread(()->{
                    BrowserReceiptPrinter adapter=new BrowserReceiptPrinter(receipt,()->{busy.set(false);updateStatus();});
                    try {
                        PrintManager manager=(PrintManager)getSystemService(PRINT_SERVICE);
                        if(manager==null || isFinishing())throw new Exception("خدمة طباعة النظام غير متاحة.");
                        ledger.submit(hash);
                        PrintJob job=manager.print("دنانير — وصل",adapter,adapter.attributes());
                        if(job==null)throw new Exception("تعذر فتح نافذة طباعة النظام.");
                        ledger.accepted(hash);
                        callback.complete(new Outcome(true,"فُتحت طباعة المتصفح / النظام. اختر طابعتك وتحقق من الورقة؛ فتح النافذة لا يعني نجاح الطباعة.","accepted"));
                    } catch(Exception failure) {adapter.close();callback.complete(new Outcome(false,failure.getMessage(),"not_sent"));}
                });
            } catch(Exception | LinkageError failure) {
                for(Bitmap strip:strips)if(!strip.isRecycled())strip.recycle();
                runOnUiThread(()->{busy.set(false);updateStatus();callback.complete(new Outcome(false,failure.getMessage(),failure instanceof IllegalStateException?"duplicate":"not_sent"));});
            }
        });
    }
    private static String hex(byte[] bytes) { StringBuilder result = new StringBuilder(); for (byte b : bytes) result.append(String.format(Locale.ROOT, "%02x", b)); return result.toString(); }
    @Override public void onRequestPermissionsResult(int request, String[] permissions, int[] results) {
        super.onRequestPermissionsResult(request, permissions, results);
        if (request == BluetoothPrinterDiscovery.PERMISSIONS) { bluetoothDiscovery.permissionResult(); return; }
        if (request == 1) { if (results.length > 0 && results[0] == PackageManager.PERMISSION_GRANTED) pairedPrinters(); else tell("إذن الأجهزة القريبة مطلوب لربط الطابعة؛ فعّله من إعدادات التطبيق."); }
        if (request == 2 && locationCallback != null) {
            boolean granted = checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED || checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED;
            locationCallback.invoke(locationOrigin, granted, granted); locationCallback = null;
        }
    }
    private void cancelImageChooser(){if(imageChooser!=null){imageChooser.onReceiveValue(null);imageChooser=null;}}
    @Override protected void onActivityResult(int request,int result,Intent data) {
        super.onActivityResult(request,result,data);
        if(request==BluetoothPrinterDiscovery.ENABLE){bluetoothDiscovery.enableResult(result);return;}
        if(request!=4)return;
        final ValueCallback<Uri[]> callback=imageChooser;
        if(callback==null)return;
        if(result!=RESULT_OK||data==null||data.getData()==null){cancelImageChooser();return;}
        worker.execute(()->{
            Uri prepared=null;String failure=null;
            try{prepared=SafeImageUpload.prepare(this,data.getData());}catch(Exception error){failure=error.getMessage();}
            final Uri image=prepared;final String message=failure;
            runOnUiThread(()->{if(imageChooser!=callback)return;imageChooser=null;if(destroyed||!trusted(Uri.parse(web.getUrl()==null?"":web.getUrl()))){callback.onReceiveValue(null);return;}callback.onReceiveValue(image==null?null:new Uri[]{image});if(message!=null)tell(message);});
        });
    }
    private void applyAppearance(JSONObject payload, SystemBarBackground background) {
        SystemBarPalette palette = SystemBarPalette.resolve(payload.optString("theme").equals("dark"), payload.optString("color"),
            payload.optString("statusBarColor"), payload.optString("navigationBarColor"), payload.optString("backgroundColor")).forSdk(Build.VERSION.SDK_INT);
        appearancePalette = palette; darkAppearance = payload.optString("theme").equals("dark");
        background.palette(palette);
        // On target 35 the transparent system bars reveal the separately painted root insets.
        getWindow().setStatusBarColor(palette.statusBarColor); getWindow().setNavigationBarColor(palette.navigationBarColor);
        if (Build.VERSION.SDK_INT >= 29) {
            getWindow().setStatusBarContrastEnforced(false); getWindow().setNavigationBarContrastEnforced(false);
        }
        if (Build.VERSION.SDK_INT >= 30) {
            WindowInsetsController controller = getWindow().getDecorView().getWindowInsetsController();
            if (controller != null) {
                int mask = WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS | WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS;
                int appearance = (palette.statusDarkIcons() ? WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS : 0)
                    | (palette.navigationDarkIcons() ? WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS : 0);
                controller.setSystemBarsAppearance(appearance, mask);
            }
        } else {
            int flags = getWindow().getDecorView().getSystemUiVisibility();
            flags = palette.statusDarkIcons() ? flags | View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR : flags & ~View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR;
            if (Build.VERSION.SDK_INT >= 26) flags = palette.navigationDarkIcons()
                ? flags | View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR : flags & ~View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR;
            getWindow().getDecorView().setSystemUiVisibility(flags);
        }
    }

    private void handleNativeBack() {
        if (exitConfirmation != null && exitConfirmation.isShowing()) { dismissExitConfirmation(); return; }
        if (busy.get()) { tell("انتظر نتيجة محاولة الطباعة قبل مغادرة التطبيق."); return; }
        if (destroyed || isFinishing()) return;
        long ticket = nativeBack.begin(); if (ticket == 0) return;
        if (!trusted(Uri.parse(web.getUrl() == null ? "" : web.getUrl()))) { completeNativeBack(ticket, "\"unhandled\""); return; }
        try { web.evaluateJavascript(NativeBackDispatch.SCRIPT, result -> completeNativeBack(ticket, result)); }
        catch (RuntimeException unavailable) { nativeBack.invalidate(); }
    }

    private void completeNativeBack(long ticket, String result) {
        NativeBackDispatch.Action action = nativeBack.complete(ticket, result, busy.get());
        if (destroyed || isFinishing()) return;
        if (action == NativeBackDispatch.Action.BLOCKED) { tell("انتظر نتيجة محاولة الطباعة قبل مغادرة التطبيق."); return; }
        if (action == NativeBackDispatch.Action.EXIT) showExitConfirmation(false);
        else if (action == NativeBackDispatch.Action.FALLBACK) { if (web.canGoBack()) web.goBack(); else showExitConfirmation(true); }
    }

    private void showExitConfirmation(boolean legacyFallback) {
        if (destroyed || isFinishing() || busy.get() || exitConfirmation != null) return;
        final String page = web.getUrl(); final long document = documentGeneration;
        final boolean trustedPage = trusted(Uri.parse(page == null ? "" : page));
        final long generation = ++exitConfirmationGeneration;
        int panel = darkAppearance ? 0xff142329 : 0xffffffff;
        int text = darkAppearance ? 0xffecf2f3 : 0xff173239;
        int muted = darkAppearance ? 0xffadc0c4 : 0xff627a80;
        int accent = appearancePalette.statusBarColor;
        LinearLayout content = new LinearLayout(this); content.setOrientation(LinearLayout.VERTICAL);
        content.setTag("dananeer-exit-confirmation");
        content.setPadding(dp(24), dp(24), dp(24), dp(8)); content.setLayoutDirection(View.LAYOUT_DIRECTION_RTL);
        TextView title = new TextView(this); title.setText("الخروج من التطبيق؟"); title.setTextSize(20); title.setTextColor(text);
        title.setTextDirection(View.TEXT_DIRECTION_RTL); title.setTextAlignment(View.TEXT_ALIGNMENT_VIEW_START);
        title.setTypeface(android.graphics.Typeface.createFromAsset(getAssets(), "cairo-bold.ttf"));
        content.addView(title, new LinearLayout.LayoutParams(-1, -2));
        TextView message = new TextView(this); message.setText("هل تريد إغلاق التطبيق؟ سيبقى حسابك مسجّل الدخول.");
        message.setTextDirection(View.TEXT_DIRECTION_RTL); message.setTextAlignment(View.TEXT_ALIGNMENT_VIEW_START);
        message.setTextSize(15); message.setTextColor(muted); message.setPadding(0, dp(12), 0, dp(10));
        content.addView(message, new LinearLayout.LayoutParams(-1, -2));
        AlertDialog dialog = new AlertDialog.Builder(this).setView(content)
            .setPositiveButton("خروج", null).setNegativeButton("البقاء في التطبيق", null).create();
        exitConfirmation = dialog; dialog.setCanceledOnTouchOutside(true);
        dialog.setOnDismissListener(value -> {
            if (exitConfirmation == dialog) { exitConfirmation = null; exitConfirmationGeneration++; nativeBack.invalidate(); }
        });
        dialog.show();
        android.graphics.drawable.GradientDrawable surface = new android.graphics.drawable.GradientDrawable();
        surface.setColor(panel); surface.setCornerRadius(dp(22)); dialog.getWindow().setBackgroundDrawable(surface);
        dialog.getWindow().getDecorView().setLayoutDirection(View.LAYOUT_DIRECTION_RTL);
        Button stay = dialog.getButton(AlertDialog.BUTTON_NEGATIVE); stay.setAllCaps(false); stay.setTextColor(text);
        Button leave = dialog.getButton(AlertDialog.BUTTON_POSITIVE); leave.setAllCaps(false);
        // Android may resolve DecorView against the device locale. Keep the actual dialog controls RTL independently.
        ((View) leave.getParent()).setLayoutDirection(View.LAYOUT_DIRECTION_RTL);
        stay.setTextDirection(View.TEXT_DIRECTION_RTL); leave.setTextDirection(View.TEXT_DIRECTION_RTL);
        android.graphics.drawable.GradientDrawable fill = new android.graphics.drawable.GradientDrawable();
        fill.setColor(accent); fill.setCornerRadius(dp(12)); leave.setBackgroundTintList(null); leave.setBackground(fill);
        leave.setTextColor(SystemBarPalette.darkIcons(accent) ? 0xff10242b : 0xffffffff);
        leave.setPadding(dp(22), dp(6), dp(22), dp(6));
        leave.setOnClickListener(value -> {
            if (!validExitConfirmation(dialog, generation, page, document)) { dismissExitConfirmation(); return; }
            if (busy.get()) { dismissExitConfirmation(); tell("انتظر نتيجة محاولة الطباعة قبل مغادرة التطبيق."); return; }
            long ticket = nativeBack.begin(); if (ticket == 0) return;
            leave.setEnabled(false);
            if (!trustedPage) {
                // Startup/offline fallback still requires confirmation, without running page code at an untrusted URL.
                nativeBack.invalidate();
                if (legacyFallback && !trusted(Uri.parse(web.getUrl() == null ? "" : web.getUrl())) && !web.canGoBack()) finishConfirmedExit(dialog, generation, page, document);
                else dismissExitConfirmation();
                return;
            }
            try {
                // A sale or blocking layer may have started since the prompt opened. Ask the current page again.
                web.evaluateJavascript(NativeBackDispatch.SCRIPT, result -> {
                    NativeBackDispatch.Action action = nativeBack.complete(ticket, result, busy.get());
                    if (!validExitConfirmation(dialog, generation, page, document)) { if (exitConfirmation == dialog) dismissExitConfirmation(); return; }
                    if (action == NativeBackDispatch.Action.IGNORE) { dismissExitConfirmation(); return; }
                    if (!busy.get() && trusted(Uri.parse(web.getUrl() == null ? "" : web.getUrl()))
                        && (action == NativeBackDispatch.Action.EXIT || (legacyFallback && action == NativeBackDispatch.Action.FALLBACK && !web.canGoBack())))
                        finishConfirmedExit(dialog, generation, page, document);
                    else { dismissExitConfirmation(); if (busy.get()) tell("انتظر نتيجة محاولة الطباعة قبل مغادرة التطبيق."); }
                });
            } catch (RuntimeException unavailable) { dismissExitConfirmation(); }
        });
    }

    private boolean validExitConfirmation(AlertDialog dialog, long generation, String page, long document) {
        return !destroyed && !isFinishing() && exitConfirmation == dialog && dialog.isShowing()
            && generation == exitConfirmationGeneration && document == documentGeneration && Objects.equals(page, web.getUrl());
    }

    private void finishConfirmedExit(AlertDialog dialog, long generation, String page, long document) {
        if (!validExitConfirmation(dialog, generation, page, document) || busy.get()) { dismissExitConfirmation(); return; }
        CookieManager.getInstance().flush(); dismissExitConfirmation(); finish();
    }

    private void dismissExitConfirmation() { if (exitConfirmation != null) exitConfirmation.dismiss(); }
    private int dp(int value) { return Math.round(value * getResources().getDisplayMetrics().density); }

    @Override public void onBackPressed() { handleNativeBack(); }
    @Override protected void onPause() { CookieManager.getInstance().flush(); super.onPause(); }
    @Override protected void onStop() { dismissExitConfirmation(); nativeBack.invalidate(); bluetoothDiscovery.background(); super.onStop(); }
    @Override protected void onDestroy() { destroyed=true;dismissExitConfirmation();nativeBack.invalidate();if(unregisterBackCallback!=null)unregisterBackCallback.run();bluetoothDiscovery.close();cancelImageChooser();SafeImageUpload.clear(this);CookieManager.getInstance().flush(); web.destroy(); worker.shutdown(); super.onDestroy(); }
}
