package iq.dananir.pos;
import android.content.Context;
import android.content.ContentValues;
import android.database.Cursor;
import android.database.sqlite.*;

/** No PIN, phone, receipt content or account credentials are persisted here. */
final class PrintLedger extends SQLiteOpenHelper {
    PrintLedger(Context context) { super(context, "print-ledger.db", null, 1); }
    @Override public void onCreate(SQLiteDatabase db) { db.execSQL("CREATE TABLE jobs (hash TEXT PRIMARY KEY, state TEXT NOT NULL, updated_at INTEGER NOT NULL)"); }
    @Override public void onUpgrade(SQLiteDatabase db, int oldVersion, int newVersion) { throw new IllegalStateException("Unsupported ledger upgrade"); }
    boolean contains(String hash) {
        try (Cursor cursor = getReadableDatabase().rawQuery("SELECT 1 FROM jobs WHERE hash=? LIMIT 1", new String[]{hash})) { return cursor.moveToFirst(); }
    }
    void submit(String hash) {
        ContentValues value = new ContentValues(); value.put("hash", hash); value.put("state", "submitted"); value.put("updated_at", System.currentTimeMillis());
        getWritableDatabase().insertOrThrow("jobs", null, value);
    }
    void accepted(String hash) {
        ContentValues value = new ContentValues(); value.put("state", "accepted"); value.put("updated_at", System.currentTimeMillis());
        getWritableDatabase().update("jobs", value, "hash=?", new String[]{hash});
    }
}
