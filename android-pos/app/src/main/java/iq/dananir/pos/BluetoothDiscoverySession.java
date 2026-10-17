package iq.dananir.pos;

/** Main-thread lifecycle fence; no Android dependencies or device identifiers. */
final class BluetoothDiscoverySession {
    private long generation;
    private String operationId;
    private boolean active;

    long begin(String id) {
        if (id == null || !id.matches("[A-Za-z0-9-]{16,80}")) throw new IllegalArgumentException("مرجع البحث غير صالح.");
        if (active) throw new IllegalStateException("أوقف البحث أو الإقران الحالي أولًا.");
        operationId = id; active = true; return ++generation;
    }
    boolean current(long ticket) { return active && ticket == generation; }
    boolean owns(String id) { return active && operationId.equals(id); }
    boolean active() { return active; }
    boolean complete(long ticket) { if (!current(ticket)) return false; active = false; return true; }
}
