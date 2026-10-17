package iq.dananir.pos;

import org.junit.Test;
import static org.junit.Assert.*;

public class PrinterDiagnosticsTest {
    @Test public void diagnosticNeverIncludesReceiptOrCredentialsFromExceptionMessage() {
        RuntimeException failure = new RuntimeException("PIN=123456789 phone=07701234567 token=secret");
        String summary = PrinterDiagnostics.failureSummary("print/rovoo", failure);
        assertTrue(summary.contains("java.lang.RuntimeException"));
        assertTrue(summary.contains("diagnosticNeverIncludesReceiptOrCredentialsFromExceptionMessage"));
        assertFalse(summary.contains("123456789")); assertFalse(summary.contains("07701234567")); assertFalse(summary.contains("secret"));
    }
}
