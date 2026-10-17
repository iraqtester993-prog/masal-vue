package iq.dananir.pos;

import android.app.Instrumentation;
import android.content.*;
import android.os.*;
import java.util.concurrent.*;

/** Real Android bind/unbind regression tests; never sends printer commands or creates sales. */
public final class PrinterBindingInstrumentation extends Instrumentation {
    private Bundle arguments;
    @Override public void onCreate(Bundle arguments) { super.onCreate(arguments);this.arguments=arguments; start(); }
    @Override public void onStart() {
        Bundle result = new Bundle();
        try {
            if(arguments!=null&&"1".equals(arguments.getString("ui"))){result.putString("stream",checkHostedUi(arguments.getString("theme","light")));finish(0,result);return;}
            java.util.List<android.graphics.Bitmap> blocks = new java.util.ArrayList<>();
            int[] heights = {25,190,7,300}; int sourceRow = 0;
            for (int height : heights) {
                android.graphics.Bitmap bitmap = android.graphics.Bitmap.createBitmap(384,height,android.graphics.Bitmap.Config.ARGB_8888);
                for (int row = 0; row < height; row++) for (int x = 0; x < 384; x++) bitmap.setPixel(x,row,0xff000000 | sourceRow + row);
                blocks.add(bitmap); sourceRow += height;
            }
            java.util.List<android.graphics.Bitmap> packed = ReceiptRenderer.pack(blocks,384,sourceRow);
            int printedRow = 0;
            for (android.graphics.Bitmap strip : packed) {
                check(strip.getHeight()<=192,"SDK raster must stay bounded");
                for (int row = 0; row < strip.getHeight(); row++) {
                    check(strip.getPixel(0,row)==(0xff000000 | printedRow),"Receipt row lost or reordered");
                    check(strip.getPixel(383,row)==(0xff000000 | printedRow),"Receipt right edge clipped");
                    printedRow++;
                }
                strip.recycle();
            }
            check(printedRow==sourceRow,"Receipt tail missing");
            TrackingContext context = new TrackingContext(getTargetContext());
            Intent service = new Intent().setClassName(getContext().getPackageName(), FakePrinterService.class.getName());
            try (PrinterBinding binding = new PrinterBinding(context)) {
                if (!binding.connect(service, "dananeer.test.Printer").isBinderAlive()) throw new AssertionError("No live binder");
            }
            drainMain();
            check(context.binds == 1 && context.unbinds == 1, "One matching context must bind and unbind");
            try (PrinterBinding binding = new PrinterBinding(context)) {
                try { binding.connect(service, "wrong.Descriptor"); throw new AssertionError("Wrong descriptor accepted"); }
                catch (Exception expected) { check(expected.getMessage().contains("SDK"), "Wrong error for mismatched descriptor"); }
            }
            drainMain();
            check(context.binds == 2 && context.unbinds == 2, "Descriptor failure must release binding");
            try (PrinterBinding binding = new PrinterBinding(context)) {
                try { binding.connect(new Intent().setClassName("missing.printer", "missing.printer.Service"), "missing"); throw new AssertionError("Missing service accepted"); }
                catch (Exception expected) { /* Expected before any print command. */ }
            }
            drainMain();
            check(context.unbinds == 2, "Never unbind a failed binding");
            PrinterBinding closed = new PrinterBinding(context); closed.close();
            try { closed.connect(service, "dananeer.test.Printer"); throw new AssertionError("Closed binding reconnected"); }
            catch (Exception expected) { /* No late connection after cancellation. */ }
            drainMain();
            check(context.binds == 3, "Closed binding must not start bindService");
            PrintLedger ledger = new PrintLedger(getTargetContext());
            String hash = "test-binding-" + System.nanoTime(); ledger.submit(hash);
            ledger.close(); ledger = new PrintLedger(getTargetContext());
            check(ledger.contains(hash), "Uncertain print job must survive process/session reopening");
            ledger.getWritableDatabase().delete("jobs", "hash=?", new String[]{hash}); ledger.close();
            check(PrinterDiscovery.detected(getTargetContext()).isEmpty(), "Emulator must not claim hardware printer support");
            check(PrinterDiscovery.selectable(getTargetContext()).contains("sunmi") && PrinterDiscovery.selectable(getTargetContext()).contains("rovoo"), "OEM service discovery failure must not hide manual printer selection");
            CountDownLatch zcsReply = new CountDownLatch(1);
            final int[] zcsCode = {-999};
            try (PrinterBinding binding = new PrinterBinding(getTargetContext())) {
                Messenger zcs = new Messenger(binding.connect(PrinterDiscovery.intent("zcs"), "android.os.IMessenger"));
                Message init = Message.obtain(null, 1);
                init.replyTo = new Messenger(new Handler(Looper.getMainLooper(), message -> { zcsCode[0] = message.getData().getInt("code"); zcsReply.countDown(); return true; }));
                zcs.send(init);
                zcsReply.await(15, TimeUnit.SECONDS);
                check(zcsCode[0] != 0, "ZCS must not report initialized POS hardware on an emulator");
            }
            drainMain();
            checkDeviceProof();
            checkImagePreflight();
            checkImageUpload();
            checkBrowserPdf();
            checkHostedBridge();
            result.putString("stream", "PASS: connect, same-context cleanup, descriptor mismatch, missing service, cancelled binding, persistent duplicate guard, hardware discovery, isolated ZCS failure (code " + zcsCode[0] + "), nonexportable device key/signature, bounded receipt image, validated upload URI, hosted PWA header, trusted bridge capabilities/settings, untrusted origin isolation\n");
            finish(0, result);
        } catch (Throwable error) {
            result.putString("stream", "FAIL: " + error.getMessage() + "\n" + PrinterDiagnostics.failureSummary("verification", error)); finish(1, result);
        }
    }
    private void checkHostedBridge() throws Exception {
        android.app.Activity activity=startActivitySync(new Intent(getTargetContext(),MainActivity.class).putExtra("isolated_ui",true).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        try {
            android.webkit.WebView[] web={null};
            runOnMainSync(()->{
                android.view.ViewGroup content=activity.findViewById(android.R.id.content);
                android.view.ViewGroup root=(android.view.ViewGroup)content.getChildAt(0);
                check(root.getChildCount()==1 && root.getChildAt(0) instanceof android.webkit.WebView,"PWA must own the only app header/navigation");
                web[0]=(android.webkit.WebView)root.getChildAt(0);web[0].stopLoading();
            });
            org.json.JSONObject capabilities=new org.json.JSONObject(bridgePage(web[0],"https://pos.dananir-iq.com/","window.DananeerPrint.onmessage=e=>document.title='BRIDGE:'+e.data;window.DananeerPrint.postMessage(JSON.stringify({requestId:'instrument-capabilities',action:'capabilities'}));"));
            check(capabilities.getBoolean("ok") && capabilities.getInt("bridgeVersion")==3 && capabilities.getInt("deviceProofVersion")==1,"Trusted bridge capabilities unavailable");
            check(capabilities.getString("printRoute").equals("browser")&&capabilities.getBoolean("browserPrintSupported"),"Unknown emulator must choose system/browser printing");
            check(capabilities.getInt("paperWidthMm")==58 && capabilities.getInt("receiptWidthPixels")==384,"58mm contract changed");
            check(!capabilities.has("address")&&!capabilities.has("serial")&&!capabilities.has("blocks"),"Bridge capabilities leak printer fingerprint or receipt");
            org.json.JSONObject opened=new org.json.JSONObject(bridgePage(web[0],"https://pos.dananir-iq.com/","window.DananeerPrint.onmessage=e=>document.title='BRIDGE:'+e.data;window.DananeerPrint.postMessage(JSON.stringify({requestId:'instrument-settings',action:'printerSettings'}));"));
            check(opened.getBoolean("ok") && opened.getString("state").equals("opened"),"Settings must report dialog opening rather than paper completion");
            sendKeyDownUpSync(android.view.KeyEvent.KEYCODE_BACK);
            String untrusted=bridgePage(web[0],"https://untrusted.example/","document.title='BRIDGE:'+typeof window.DananeerPrint;");
            check(untrusted.equals("undefined"),"Untrusted origin has native bridge access");
        } finally {runOnMainSync(activity::finish);}
    }
    private void checkDeviceProof() throws Exception {
        String exported=DeviceProof.publicKey(),challenge="dananeer-device-proof-v1\nlocal-instrumentation-only";
        java.security.PublicKey publicKey=java.security.KeyFactory.getInstance("EC").generatePublic(new java.security.spec.X509EncodedKeySpec(android.util.Base64.decode(exported,android.util.Base64.DEFAULT)));
        java.security.Signature verifier=java.security.Signature.getInstance("SHA256withECDSA");verifier.initVerify(publicKey);verifier.update(challenge.getBytes(java.nio.charset.StandardCharsets.UTF_8));
        check(verifier.verify(android.util.Base64.decode(DeviceProof.sign(challenge),android.util.Base64.DEFAULT)),"Device signature must verify against enrolled SPKI");
        java.security.KeyStore store=java.security.KeyStore.getInstance("AndroidKeyStore");store.load(null);
        check(store.getKey("dananeer.device.proof.v1",null).getEncoded()==null,"Private device key must not be exportable");
        check(exported.equals(DeviceProof.publicKey()),"Device public key must survive subsequent reads");
    }
    private byte[] smallImage(int width,int height) throws Exception {
        android.graphics.Bitmap image=android.graphics.Bitmap.createBitmap(width,height,android.graphics.Bitmap.Config.ARGB_8888);image.eraseColor(android.graphics.Color.BLACK);
        try(java.io.ByteArrayOutputStream output=new java.io.ByteArrayOutputStream()){image.compress(android.graphics.Bitmap.CompressFormat.PNG,100,output);return output.toByteArray();}finally{image.recycle();}
    }
    private void checkImagePreflight() throws Exception {
        org.json.JSONArray blocks=new org.json.JSONArray().put(new org.json.JSONObject().put("image",android.util.Base64.encodeToString(smallImage(1,1200),android.util.Base64.NO_WRAP)).put("widthFraction",1));
        java.util.List<android.graphics.Bitmap> rendered=ReceiptRenderer.render(getTargetContext(),blocks,384);int rows=0;
        for(android.graphics.Bitmap strip:rendered){rows+=strip.getHeight();strip.recycle();}check(rows==1212,"Narrow image must stay bounded before allocation");
    }
    private void checkImageUpload() throws Exception {
        java.io.File directory=new java.io.File(getTargetContext().getCacheDir(),"image-uploads");directory.mkdirs();
        java.io.File image=new java.io.File(directory,"local-picker-test.png");try(java.io.FileOutputStream output=new java.io.FileOutputStream(image)){output.write(smallImage(12,12));}
        android.net.Uri source=androidx.core.content.FileProvider.getUriForFile(getTargetContext(),"iq.dananir.pos.uploads",image);
        android.net.Uri validated=SafeImageUpload.prepare(getTargetContext(),source);
        check("image/png".equals(getTargetContext().getContentResolver().getType(validated)),"Upload must expose only the validated image MIME");
        try(java.io.InputStream input=getTargetContext().getContentResolver().openInputStream(validated)){check(input!=null&&input.read()==137,"Prepared upload must remain readable");}
        java.io.File invalid=new java.io.File(directory,"not-image.png");try(java.io.FileOutputStream output=new java.io.FileOutputStream(invalid)){output.write("not an image".getBytes(java.nio.charset.StandardCharsets.UTF_8));}
        try{SafeImageUpload.prepare(getTargetContext(),androidx.core.content.FileProvider.getUriForFile(getTargetContext(),"iq.dananir.pos.uploads",invalid));throw new AssertionError("Malformed upload accepted");}catch(java.io.IOException expected){}
        try{SafeImageUpload.prepare(getTargetContext(),android.net.Uri.parse("file:///data/data/iq.dananir.pos/shared_prefs/printer.xml"));throw new AssertionError("Private file URI accepted");}catch(java.io.IOException expected){}
        SafeImageUpload.clear(getTargetContext());
    }
    private void checkBrowserPdf() throws Exception {
        java.util.List<android.graphics.Bitmap> strips=new java.util.ArrayList<>();int rows=0;
        for(int index=0;index<21;index++){int height=index==20?27:192;android.graphics.Bitmap bitmap=android.graphics.Bitmap.createBitmap(384,height,android.graphics.Bitmap.Config.ARGB_8888);bitmap.eraseColor(android.graphics.Color.WHITE);if(index==20)new android.graphics.Canvas(bitmap).drawRect(0,height-5,384,height,new android.graphics.Paint());strips.add(bitmap);rows+=height;}
        final int[] closes={0};BrowserReceiptPrinter adapter=new BrowserReceiptPrinter(strips,()->closes[0]++);
        check(adapter.attributes().getMediaSize().getWidthMils()==2283,"System print width must remain 58mm");
        java.io.File file=java.io.File.createTempFile("receipt-pdf-test-",".pdf",getTargetContext().getCacheDir());
        try(java.io.FileOutputStream out=new java.io.FileOutputStream(file)){adapter.writePdf(out,new CancellationSignal());}
        try(ParcelFileDescriptor descriptor=ParcelFileDescriptor.open(file,ParcelFileDescriptor.MODE_READ_ONLY);android.graphics.pdf.PdfRenderer renderer=new android.graphics.pdf.PdfRenderer(descriptor);android.graphics.pdf.PdfRenderer.Page page=renderer.openPage(0)){
            check(renderer.getPageCount()==1&&page.getHeight()>1650,"Long receipt must not be cropped to a short system print page");
            android.graphics.Bitmap rendered=android.graphics.Bitmap.createBitmap(page.getWidth(),page.getHeight(),android.graphics.Bitmap.Config.ARGB_8888);page.render(rendered,null,null,android.graphics.pdf.PdfRenderer.Page.RENDER_MODE_FOR_PRINT);
            int tail=(int)((rows+9)*165.0/384);boolean footer=false;for(int y=tail-4;y<=tail+4&&y<rendered.getHeight();y++)if(android.graphics.Color.red(rendered.getPixel(rendered.getWidth()/2,y))<100)footer=true;
            check(footer,"System print PDF lost the final receipt rows");rendered.recycle();
        } finally {adapter.close();adapter.close();file.delete();}
        check(closes[0]==1,"System print completion must release busy state once");
    }
    private String checkHostedUi(String theme) throws Exception {
        org.json.JSONObject fixtures;
        try(java.io.InputStream stream=getContext().getAssets().open("ui-"+theme+".json")){fixtures=new org.json.JSONObject(new String(stream.readAllBytes(),java.nio.charset.StandardCharsets.UTF_8));}
        android.app.Activity activity=startActivitySync(new Intent(getTargetContext(),MainActivity.class).putExtra("isolated_ui",true).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK));
        android.webkit.WebView[] web={null};
        try {
            runOnMainSync(()->{
                android.view.ViewGroup root=(android.view.ViewGroup)((android.view.ViewGroup)activity.findViewById(android.R.id.content)).getChildAt(0);web[0]=(android.webkit.WebView)root.getChildAt(0);web[0].stopLoading();
                web[0].setWebViewClient(new android.webkit.WebViewClient(){@Override public android.webkit.WebResourceResponse shouldInterceptRequest(android.webkit.WebView view,android.webkit.WebResourceRequest request){
                    String path=request.getUrl().getPath();
                    try{
                        if(!MainActivity.trusted(request.getUrl())||!request.getMethod().equals("GET"))return denied();
                        if(fixtures.has(path))return new android.webkit.WebResourceResponse("application/json","UTF-8",new java.io.ByteArrayInputStream(fixtures.getJSONObject(path).toString().getBytes(java.nio.charset.StandardCharsets.UTF_8)));
                        if(path.startsWith("/api/v1/qa/"))return new android.webkit.WebResourceResponse("image/svg+xml","UTF-8",new java.io.ByteArrayInputStream("<svg xmlns='http://www.w3.org/2000/svg' width='160' height='100'><rect width='160' height='100' rx='12' fill='#079cb0'/><text x='80' y='55' text-anchor='middle' fill='white'>LOCAL QA</text></svg>".getBytes(java.nio.charset.StandardCharsets.UTF_8)));
                        if(path.startsWith("/api/")||path.startsWith("/sanctum/"))return denied();
                        String asset=path.equals("/")||!path.contains(".")?"index.html":path.substring(1);String mime=asset.endsWith(".js")?"application/javascript":asset.endsWith(".css")?"text/css":asset.endsWith(".ttf")?"font/ttf":asset.endsWith(".json")?"application/json":asset.endsWith(".png")?"image/png":"text/html";
                        return new android.webkit.WebResourceResponse(mime,"UTF-8",getContext().getAssets().open("pwa/"+asset));
                    }catch(Exception missing){return denied();}
                }private android.webkit.WebResourceResponse denied(){return new android.webkit.WebResourceResponse("application/json","UTF-8",403,"Blocked by isolated test",java.util.Collections.emptyMap(),new java.io.ByteArrayInputStream("{}".getBytes(java.nio.charset.StandardCharsets.UTF_8)));}});
            });
            android.webkit.ServiceWorkerController.getInstance().setServiceWorkerClient(new android.webkit.ServiceWorkerClient(){@Override public android.webkit.WebResourceResponse shouldInterceptRequest(android.webkit.WebResourceRequest request){return new android.webkit.WebResourceResponse("text/plain","UTF-8",403,"Blocked by isolated test",java.util.Collections.emptyMap(),new java.io.ByteArrayInputStream(new byte[0]));}});
            runOnMainSync(()->{web[0].clearCache(true);web[0].loadUrl("https://pos.dananir-iq.com/dashboard");});
            String metrics="null";
            for(int attempt=0;attempt<200;attempt++){
                java.util.concurrent.CountDownLatch evaluated=new java.util.concurrent.CountDownLatch(1);final String[] value={"null"};
                runOnMainSync(()->web[0].evaluateJavascript("document.querySelector('.pos-home-summary')&&document.querySelectorAll('.pos-activity-row').length===10?JSON.stringify({width:innerWidth,scroll:document.documentElement.scrollWidth,nav:document.querySelectorAll('.pos-app-nav>a').length,theme:document.documentElement.dataset.theme,portal:document.documentElement.dataset.portal,shell:document.querySelector('.portal-shell').className,range:matchMedia('screen and (width<=800px)').matches,screen:matchMedia('(max-width:800px)').matches,match:document.querySelector('.pos-home-summary').matches('html[data-theme] .portal-shell.pos-app .pos-home-summary'),css:Array.from(document.styleSheets).map(s=>{try{return s.href+'|'+s.cssRules.length}catch(e){return String(e)}}),summary:getComputedStyle(document.querySelector('.pos-home-summary')).backgroundImage}):null",answer->{value[0]=answer;evaluated.countDown();}));evaluated.await(2,TimeUnit.SECONDS);
                if(!"null".equals(value[0])){metrics=value[0];break;}Thread.sleep(100);
            }
            check(!metrics.equals("null"),"Isolated PWA did not render inside the APK");
            String decoded=new org.json.JSONArray("["+metrics+"]").getString(0);org.json.JSONObject geometry=new org.json.JSONObject(decoded);
            check(geometry.getInt("width")<=500&&geometry.getInt("scroll")<=geometry.getInt("width")&&geometry.getInt("nav")==4,"APK viewport differs from mobile PWA layout");
            check(theme.equals(geometry.getString("theme"))&&geometry.getString("summary").contains("linear-gradient"),"APK theme/design differs from PWA: "+decoded);
            Thread.sleep(500);
            android.graphics.Bitmap capture=android.graphics.Bitmap.createBitmap(activity.getWindow().getDecorView().getWidth(),activity.getWindow().getDecorView().getHeight(),android.graphics.Bitmap.Config.ARGB_8888);CountDownLatch copied=new CountDownLatch(1);
            runOnMainSync(()->{if(Build.VERSION.SDK_INT>=26)android.view.PixelCopy.request(activity.getWindow(),capture,code->copied.countDown(),new Handler(Looper.getMainLooper()));else{activity.getWindow().getDecorView().draw(new android.graphics.Canvas(capture));copied.countDown();}});copied.await(3,TimeUnit.SECONDS);
            java.io.File screenshot=new java.io.File(activity.getExternalFilesDir(null),"pos-ui-"+theme+".png");try(java.io.FileOutputStream output=new java.io.FileOutputStream(screenshot)){capture.compress(android.graphics.Bitmap.CompressFormat.PNG,100,output);}capture.recycle();
            return "PASS: isolated APK/PWA layout "+decoded+" screenshot="+screenshot.getAbsolutePath()+"; all external requests and writes blocked\n";
        }finally{android.webkit.ServiceWorkerController.getInstance().setServiceWorkerClient(null);runOnMainSync(activity::finish);}
    }
    private String bridgePage(android.webkit.WebView web,String origin,String script) throws Exception {
        CountDownLatch done=new CountDownLatch(1);String[] response={null};
        runOnMainSync(()->{
            web.setWebChromeClient(new android.webkit.WebChromeClient(){
                @Override public void onReceivedTitle(android.webkit.WebView view,String title){if(title.startsWith("BRIDGE:")){response[0]=title.substring(7);done.countDown();}}
                @Override public boolean onConsoleMessage(android.webkit.ConsoleMessage message){android.util.Log.d("DananeerBridgeTest",message.message()+" nativeURL="+web.getUrl());return true;}
            });
            String page="<!doctype html><html><head><title>Bridge test</title></head><body><script>setTimeout(()=>{console.log('bridge '+typeof window.DananeerPrint+' url '+location.href);"+script+"},300)</script></body></html>";
            // A real URL navigation is required: loadDataWithBaseURL can leave
            // WebView.getUrl() at about:blank, correctly denied by the bridge.
            // Every request is answered locally, including the untrusted test URL.
            web.setWebViewClient(new android.webkit.WebViewClient(){@Override public android.webkit.WebResourceResponse shouldInterceptRequest(android.webkit.WebView view,android.webkit.WebResourceRequest request){return new android.webkit.WebResourceResponse("text/html","UTF-8",new java.io.ByteArrayInputStream(page.getBytes(java.nio.charset.StandardCharsets.UTF_8)));}});
            web.loadUrl(origin);
        });
        check(done.await(15,TimeUnit.SECONDS),"PWA native bridge reply timed out");return response[0];
    }
    private void drainMain() throws Exception {
        CountDownLatch drained = new CountDownLatch(1); new Handler(Looper.getMainLooper()).post(drained::countDown);
        if (!drained.await(3, TimeUnit.SECONDS)) throw new AssertionError("UI cleanup timeout");
    }
    private static void check(boolean valid, String message) { if (!valid) throw new AssertionError(message); }
    private static final class TrackingContext extends ContextWrapper {
        volatile int binds, unbinds;
        TrackingContext(Context context) { super(context); }
        @Override public Context getApplicationContext() { return this; }
        @Override public boolean bindService(Intent intent, ServiceConnection connection, int flags) { binds++; return super.bindService(intent, connection, flags); }
        @Override public void unbindService(ServiceConnection connection) { unbinds++; super.unbindService(connection); }
    }
}
