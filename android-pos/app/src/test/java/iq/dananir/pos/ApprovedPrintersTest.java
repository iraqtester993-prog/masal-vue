package iq.dananir.pos;
import org.junit.Test;
import static org.junit.Assert.*;
public class ApprovedPrintersTest {
    @Test public void knownPosModelsResolveWithoutPerDeviceRegistration() {
        assertEquals("sunmi",ApprovedPrinters.internal("SUNMI","V2"));
        assertEquals("rovoo",ApprovedPrinters.internal("MTHD","MTHD-M1"));
        assertEquals("rovoo",ApprovedPrinters.internal("ROVOO","Android POS"));
        assertEquals("zcs",ApprovedPrinters.internal("Android POS","Z91"));
        assertNull(ApprovedPrinters.internal("Samsung","SM-A15"));
        assertNull(ApprovedPrinters.internal("Generic","POS"));
    }
    @Test public void GenericBluetoothNamesNeverImplyAnApprovedPrinterType() {
        assertEquals("GOOJPRT",ApprovedPrinters.bluetooth("GOOJPRT-123"));
        assertEquals("GOOJPRT",ApprovedPrinters.bluetooth("JingPu Printer"));
        assertEquals("Feasycom",ApprovedPrinters.bluetooth("Feasycom"));
        assertEquals("Z51",ApprovedPrinters.bluetooth("Z51 Printer"));
        assertNull(ApprovedPrinters.bluetooth("Printer001"));
        assertFalse(ApprovedPrinters.approved("unknown"));
    }
    @Test public void registryAliasesMatchOnlyDistinctConfiguredNames() {
        assertTrue(ApprovedPrinters.matches("JingPu Printer-103", "GOOJPRT|JingPu"));
        assertTrue(ApprovedPrinters.matches("MTHD M1", "ROVOO|MTHD-M1"));
        assertFalse(ApprovedPrinters.matches("Printer001", "GOOJPRT|JingPu"));
        assertFalse(ApprovedPrinters.matches("Feasycom", "||"));
        assertFalse(ApprovedPrinters.matches("Printer001", "pr"));
        assertFalse(ApprovedPrinters.matches(null, null));
    }
    @Test public void mtpTwoAliasesKeepTheExistingGoojprtProfile() {
        for (String name : new String[]{"MTP-2", "MTP-II", "MTP-Ⅱ", "ＭＴＰ－Ⅱ", "Printer MTP-II", "MTP-2-BT"}) {
            assertEquals(name, "GOOJPRT", ApprovedPrinters.bluetooth(name));
            assertTrue(name, ApprovedPrinters.matches(name, "GOOJPRT|JingPu|MTP-2|MTP-II"));
        }
        assertTrue(ApprovedPrinters.matches("MTP-2", "MTP-Ⅱ"));
        assertTrue(ApprovedPrinters.matches("MTP-Ⅱ", "MTP-2"));
    }
    @Test public void nearbyMtpModelsAreNotAcceptedByTheNewAliases() {
        for (String name : new String[]{"MTP-20", "MTP-200", "MTP-3", "MTP-III", "MTP-IIA", "XMTP-2", "MTP-2A"}) {
            assertNull(name, ApprovedPrinters.bluetooth(name));
            assertFalse(name, ApprovedPrinters.matches(name, "MTP-2|MTP-II|MTP-Ⅱ"));
        }
    }
    @Test public void yhd8006NamesResolveToAnIndependentCanonicalProfile() {
        for (String name : new String[]{"YHD-8006", "YHD8006", "YHD8006-BT", "Printer YHD-8006", "ＹＨＤ－８００６"}) {
            assertEquals(name, "YHD-8006", ApprovedPrinters.bluetooth(name));
            assertTrue(name, ApprovedPrinters.matches(name, "YHD-8006|YHD8006"));
        }
        assertTrue(ApprovedPrinters.approved("YHD-8006"));
        assertFalse(ApprovedPrinters.approved("YHD8006"));
    }
    @Test public void yhdAliasesDoNotApproveAdjacentModelsOrGenericNames() {
        for (String name : new String[]{"YHD80060", "YHD-80060", "YHD-8006A", "XYHD8006", "YHD-5801", "Printer001"}) {
            assertNull(name, ApprovedPrinters.bluetooth(name));
            assertFalse(name, ApprovedPrinters.matches(name, "YHD-8006|YHD8006"));
        }
    }
    @Test public void explicitRegistryAliasesAndLegacyBrandSuffixesRemainSupported() {
        assertTrue(ApprovedPrinters.matches("YHD-5801", "YHD-5801"));
        assertTrue(ApprovedPrinters.matches("FSC-BT_123", "Feasycom|FSC"));
        assertEquals("GOOJPRT", ApprovedPrinters.bluetooth("GOOJPRT1234"));
        assertEquals("GOOJPRT", ApprovedPrinters.bluetooth("JingPu001"));
        assertFalse(ApprovedPrinters.matches("Printer001", "GOOJPRT|JingPu|MTP-2|MTP-II|YHD-8006|YHD8006|Feasycom|FSC|Z51"));
    }
    @Test public void onlyCanonicalYhd8006UsesThe80mm576DotProfile() {
        assertArrayEquals(new String[]{"GOOJPRT", "Feasycom", "Z51", "YHD-8006"}, ApprovedPrinters.BLUETOOTH_TYPES);
        assertEquals(576, ApprovedPrinters.receiptWidthPixels("YHD-8006"));
        assertEquals(80, ApprovedPrinters.paperWidthMm("YHD-8006"));
        for (String profile : new String[]{"GOOJPRT", "Feasycom", "Z51", "sunmi", "rovoo", "zcs", "YHD-5801", "YHD8006", "unknown", ""}) {
            assertEquals(profile, 384, ApprovedPrinters.receiptWidthPixels(profile));
            assertEquals(profile, 58, ApprovedPrinters.paperWidthMm(profile));
        }
        assertEquals(384, ApprovedPrinters.receiptWidthPixels(null));
        assertEquals(58, ApprovedPrinters.paperWidthMm(null));
    }
}
