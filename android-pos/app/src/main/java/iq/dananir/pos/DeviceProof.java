package iq.dananir.pos;

import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;
import java.security.*;
import java.security.spec.ECGenParameterSpec;
import java.nio.charset.StandardCharsets;

/** Private key stays in AndroidKeyStore. Possession proof is not hardware attestation. */
final class DeviceProof {
    private static final String ALIAS="dananeer.device.proof.v1";
    private static synchronized KeyStore store() throws Exception {
        KeyStore store=KeyStore.getInstance("AndroidKeyStore");store.load(null);
        if(!store.containsAlias(ALIAS)) {
            KeyPairGenerator generator=KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC,"AndroidKeyStore");
            generator.initialize(new KeyGenParameterSpec.Builder(ALIAS,KeyProperties.PURPOSE_SIGN|KeyProperties.PURPOSE_VERIFY)
                .setAlgorithmParameterSpec(new ECGenParameterSpec("secp256r1")).setDigests(KeyProperties.DIGEST_SHA256)
                .setUserAuthenticationRequired(false).build());
            generator.generateKeyPair();
        }
        return store;
    }
    static String publicKey() throws Exception {
        return Base64.encodeToString(store().getCertificate(ALIAS).getPublicKey().getEncoded(),Base64.NO_WRAP);
    }
    static String sign(String challenge) throws Exception {
        if(challenge==null||challenge.isEmpty()||challenge.length()>2048)throw new IllegalArgumentException("تحدي اعتماد الجهاز غير صالح.");
        Signature signature=Signature.getInstance("SHA256withECDSA");
        signature.initSign((PrivateKey)store().getKey(ALIAS,null));
        signature.update(challenge.getBytes(StandardCharsets.UTF_8));
        return Base64.encodeToString(signature.sign(),Base64.NO_WRAP);
    }
}
