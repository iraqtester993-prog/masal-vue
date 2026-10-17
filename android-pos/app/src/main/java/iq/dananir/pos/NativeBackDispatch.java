package iq.dananir.pos;

/** One in-flight web decision. A stale reply must never navigate a replacement page. */
final class NativeBackDispatch {
    enum Action { IGNORE, HANDLED, EXIT, FALLBACK, BLOCKED }

    static final String SCRIPT = "(function(){try{var e=new CustomEvent('dananeer:native-back',"
        + "{cancelable:true,detail:{result:'unhandled',exitConfirmationVersion:1}});window.dispatchEvent(e);"
        + "if(!e.defaultPrevented)return 'unhandled';"
        + "return e.detail&&e.detail.result==='exit'?'exit':'handled';"
        + "}catch(error){return 'unhandled';}})()";

    private long generation;
    private boolean pending;

    long begin() { if (pending) return 0; pending = true; return ++generation; }
    void invalidate() { pending = false; generation++; }

    Action complete(long ticket, String serialized, boolean printBusy) {
        if (!pending || ticket != generation) return Action.IGNORE;
        pending = false;
        // evaluateJavascript serializes the fixed string result as JSON, rather than passing raw text.
        if ("\"handled\"".equals(serialized)) return Action.HANDLED;
        if (printBusy) return Action.BLOCKED;
        return "\"exit\"".equals(serialized) ? Action.EXIT : Action.FALLBACK;
    }
}
