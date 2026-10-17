package iq.dananir.pos;

import org.junit.Test;
import static org.junit.Assert.*;

public class SystemBarPaletteTest {
    @Test public void oldPayloadKeepsOneColorAndThemeFallback() {
        SystemBarPalette old = SystemBarPalette.resolve(false, "#6A7132", null, null, null);
        assertEquals(0xff6a7132, old.statusBarColor);
        assertEquals(old.statusBarColor, old.navigationBarColor);
        assertEquals(old.statusBarColor, old.backgroundColor);
        assertEquals(SystemBarPalette.LEGACY_DARK, SystemBarPalette.resolve(true, "", null, null, null).statusBarColor);
        assertEquals(SystemBarPalette.LEGACY_LIGHT, SystemBarPalette.resolve(false, null, null, null, null).backgroundColor);
    }

    @Test public void statusAndNavigationIconsFollowTheirOwnColorRatherThanTheme() {
        SystemBarPalette darkTheme = SystemBarPalette.resolve(true, "#556622", "#F5F7F6", "#142329", "#0D171B");
        assertTrue(darkTheme.statusDarkIcons()); assertFalse(darkTheme.navigationDarkIcons());
        assertEquals(0xff0d171b, darkTheme.backgroundColor);
        SystemBarPalette lightTheme = SystemBarPalette.resolve(false, "#556622", "#0E2D31", "#FFFFFF", "#F5F7F6");
        assertFalse(lightTheme.statusDarkIcons()); assertTrue(lightTheme.navigationDarkIcons());
        assertEquals(0xfff5f7f6, lightTheme.backgroundColor);
    }

    @Test public void malformedIndividualColorsUseOnlyValidatedLegacyFallback() {
        for (String bad : new String[]{"#FFF", "#00112233", "red", " #112233", "#112233\n", "#GG2211", "", null}) {
            SystemBarPalette palette = SystemBarPalette.resolve(false, "#234567", bad, "#FFFFFF", bad);
            assertEquals(0xff234567, palette.statusBarColor);
            assertEquals(0xffffffff, palette.navigationBarColor);
            assertEquals(0xff234567, palette.backgroundColor);
        }
        assertEquals(SystemBarPalette.LEGACY_DARK, SystemBarPalette.resolve(true, "#INVALID", null, null, null).statusBarColor);
    }

    @Test public void luminanceUsesLinearChannelsAndOldNavigationIconsStayReadable() {
        assertFalse(SystemBarPalette.darkIcons(0xff747474));
        assertTrue(SystemBarPalette.darkIcons(0xff767676));
        assertTrue(SystemBarPalette.darkIcons(0xff00ff00));
        assertFalse(SystemBarPalette.darkIcons(0xff0000ff));
        SystemBarPalette light = SystemBarPalette.resolve(false, null, "#FFFFFF", "#FFFFFF", "#F5F7F6");
        assertSame(light, light.forSdk(26)); assertSame(light, light.forSdk(35));
        SystemBarPalette old = light.forSdk(25);
        assertFalse(old.navigationDarkIcons());
        assertEquals(light.statusBarColor, old.statusBarColor);
        assertEquals(light.backgroundColor, old.backgroundColor);
    }
}
