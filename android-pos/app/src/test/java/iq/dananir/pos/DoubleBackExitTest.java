package iq.dananir.pos;

import org.junit.Test;
import static org.junit.Assert.*;

public class DoubleBackExitTest {
    @Test public void oneBackOnlyArmsAndASecondWithinTwoSecondsExits() {
        DoubleBackExit gate = new DoubleBackExit();
        assertFalse(gate.press(0, "home:1"));
        assertTrue(gate.press(1999, "home:1"));
        assertFalse(gate.press(2000, "home:1"));
    }

    @Test public void expirationAndClockRollbackRequireAnotherPair() {
        DoubleBackExit gate = new DoubleBackExit();
        assertFalse(gate.press(100, "home:1"));
        assertFalse(gate.press(2100, "home:1"));
        assertFalse(gate.press(2099, "home:1"));
        assertTrue(gate.press(2200, "home:1"));
    }

    @Test public void navigationOrBackgroundOrPrintResetRequiresTwoFreshApprovals() {
        DoubleBackExit gate = new DoubleBackExit();
        assertFalse(gate.press(100, "home:1"));
        assertFalse(gate.press(200, "home:3"));
        gate.reset();
        assertFalse(gate.press(300, "home:3"));
        assertTrue(gate.press(400, "home:3"));
    }
}
