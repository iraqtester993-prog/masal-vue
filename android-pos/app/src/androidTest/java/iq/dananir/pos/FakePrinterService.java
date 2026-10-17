package iq.dananir.pos;

import android.app.Service;
import android.content.Intent;
import android.os.*;

/** Exists only in the separate verification APK, never the distributed application. */
public final class FakePrinterService extends Service {
    public IBinder onBind(Intent intent) {
        if (intent.getBooleanExtra("nullBinding", false)) return null;
        Binder binder = new Binder();
        binder.attachInterface(null, "dananeer.test.Printer");
        return binder;
    }
}
