package iq.dananir.pos;

import org.junit.Test;
import static org.junit.Assert.*;

public class BluetoothDiscoverySessionTest {
    @Test public void cancellationAndTimeoutCompleteOnlyOnceAndOldCallbacksCannotCompleteANewScan() {
        BluetoothDiscoverySession state = new BluetoothDiscoverySession();
        long old = state.begin("scan-operation-0001");
        assertTrue(state.owns("scan-operation-0001")); assertFalse(state.owns("other-operation-0001"));
        assertTrue(state.complete(old)); assertFalse(state.complete(old));
        long next = state.begin("scan-operation-0002");
        assertFalse(state.current(old)); assertFalse(state.complete(old)); assertTrue(state.current(next));
        assertTrue(state.complete(next)); assertFalse(state.active());
    }
    @Test public void concurrentOperationsAreRejectedAndIdsAreBounded() {
        BluetoothDiscoverySession state = new BluetoothDiscoverySession();
        for (String id : new String[]{"", "short", "operation-with-spaces 123", "x".repeat(81)}) {
            try { state.begin(id); fail("Invalid operation ID accepted"); } catch (IllegalArgumentException expected) { }
        }
        state.begin("pair-operation-0001");
        try { state.begin("scan-operation-0002"); fail("Concurrent operation accepted"); } catch (IllegalStateException expected) { }
    }
}
