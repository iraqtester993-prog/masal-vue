package iq.dananir.pos;
import org.junit.Test;
import static org.junit.Assert.*;
public class ReceiptBoundsTest {
    @Test public void narrowImageCannotExpandIntoHugeBitmap() {
        int[] size=ReceiptBounds.image(1,1200,384,1,0);
        assertArrayEquals(new int[]{1,1200},size);
        assertTrue((long)384*(size[1]+12)*4 < 2000000);
    }
    @Test public void normalLogoKeepsExpectedDimensions() {
        assertArrayEquals(new int[]{180,90},ReceiptBounds.image(200,100,384,.5,0));
    }
    @Test public void rejectsBeforeAllocationWhenRemainingBudgetIsExhausted() {
        try {ReceiptBounds.image(1,1200,384,1,11000);fail();}catch(IllegalArgumentException expected){}
        try {ReceiptBounds.rows(100,11901);fail();}catch(IllegalArgumentException expected){}
        try {ReceiptBounds.image(1,-1,384,1,0);fail();}catch(IllegalArgumentException expected){}
    }
    @Test public void rowBudgetBoundaryAndOverflowAreSafe() {
        assertEquals(100,ReceiptBounds.rows(100,11900));
        try {ReceiptBounds.rows(Integer.MAX_VALUE,1);fail();}catch(IllegalArgumentException expected){}
        try {ReceiptBounds.image(Integer.MAX_VALUE,1200,384,1,0);fail();}catch(IllegalArgumentException expected){}
    }
}
