package iq.dananir.pos;

import org.junit.Test;
import static org.junit.Assert.*;
import static iq.dananir.pos.NativeBackDispatch.Action.*;

public class NativeBackDispatchTest {
    @Test public void homeExitIsEnabledOnlyWhenNativeConfirmationIsAvailable() {
        assertTrue(NativeBackDispatch.SCRIPT.contains("exitConfirmationVersion:1"));
    }

    @Test public void rapidBackWaitsForOneWebDecisionAndDoesNotDoubleNavigate() {
        NativeBackDispatch back = new NativeBackDispatch();
        long request = back.begin(); assertTrue(request > 0);
        assertEquals(0, back.begin());
        assertEquals(HANDLED, back.complete(request, "\"handled\"", false));
        assertEquals(IGNORE, back.complete(request, "\"exit\"", false));
        long next = back.begin(); assertTrue(next > request);
        assertEquals(EXIT, back.complete(next, "\"exit\"", false));
    }

    @Test public void newPageOrBackgroundInvalidatesEvenAnExitReply() {
        NativeBackDispatch back = new NativeBackDispatch();
        long previous = back.begin(); back.invalidate();
        long next = back.begin();
        assertEquals(IGNORE, back.complete(previous, "\"exit\"", false));
        assertEquals(0, back.begin());
        assertEquals(HANDLED, back.complete(next, "\"handled\"", false));
    }

    @Test public void printStartingDuringJavascriptPreventsBothExitAndLegacyHistory() {
        NativeBackDispatch back = new NativeBackDispatch();
        assertEquals(BLOCKED, back.complete(back.begin(), "\"exit\"", true));
        assertEquals(BLOCKED, back.complete(back.begin(), "\"unhandled\"", true));
        assertEquals(HANDLED, back.complete(back.begin(), "\"handled\"", true));
        assertEquals(EXIT, back.complete(back.begin(), "\"exit\"", false));
    }

    @Test public void absentOldWebHandlerFallsBackWithoutInventingExitOrHandled() {
        NativeBackDispatch back = new NativeBackDispatch();
        for (String response : new String[]{"\"unhandled\"", "null", null, "false", "\"unexpected\""}) {
            assertEquals(FALLBACK, back.complete(back.begin(), response, false));
        }
    }
}
