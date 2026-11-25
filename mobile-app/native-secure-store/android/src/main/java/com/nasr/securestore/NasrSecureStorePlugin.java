package com.nasr.securestore;

import android.content.Context;
import android.content.SharedPreferences;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.util.Base64;
import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.nio.charset.StandardCharsets;
import java.security.KeyStore;
import java.security.MessageDigest;
import java.util.Arrays;
import androidx.biometric.BiometricManager;
import androidx.biometric.BiometricPrompt;
import androidx.core.content.ContextCompat;
import androidx.fragment.app.FragmentActivity;
import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

@CapacitorPlugin(name="NasrSecureStore")
public class NasrSecureStorePlugin extends Plugin {
 private static final String ALIAS="nasr.mobile.session.v1";
 private boolean biometricBusy=false;
 private BiometricPrompt biometricPrompt;
 private SharedPreferences prefs(){return getContext().getSharedPreferences("nasr-session-vault",Context.MODE_PRIVATE);}
 private SharedPreferences biometricPrefs(){return getContext().getSharedPreferences("nasr-biometric-vault",Context.MODE_PRIVATE);}
 private SecretKey key() throws Exception {
  KeyStore store=KeyStore.getInstance("AndroidKeyStore");store.load(null);
  if(!store.containsAlias(ALIAS)){KeyGenerator generator=KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES,"AndroidKeyStore");generator.init(new KeyGenParameterSpec.Builder(ALIAS,KeyProperties.PURPOSE_ENCRYPT|KeyProperties.PURPOSE_DECRYPT).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).setKeySize(256).build());generator.generateKey();}
  return (SecretKey)store.getKey(ALIAS,null);
 }
 @PluginMethod public synchronized void set(PluginCall call){
  try{String name=call.getString("key"),value=call.getString("value");if(name==null||value==null){call.reject("Missing key/value");return;}
   Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.ENCRYPT_MODE,key());byte[] iv=cipher.getIV(),encrypted=cipher.doFinal(value.getBytes(StandardCharsets.UTF_8)),all=new byte[iv.length+encrypted.length];System.arraycopy(iv,0,all,0,iv.length);System.arraycopy(encrypted,0,all,iv.length,encrypted.length);
   if(!prefs().edit().putString(name,Base64.encodeToString(all,Base64.NO_WRAP)).commit()){call.reject("Vault write failed");return;}call.resolve();
  }catch(Exception e){call.reject("Session vault is unavailable");}
 }
 @PluginMethod public synchronized void get(PluginCall call){
  try{String name=call.getString("key");if(name==null){call.reject("Missing key");return;}String stored=prefs().getString(name,null);JSObject result=new JSObject();
   if(stored!=null){byte[] all=Base64.decode(stored,Base64.NO_WRAP);Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");cipher.init(Cipher.DECRYPT_MODE,key(),new GCMParameterSpec(128,Arrays.copyOfRange(all,0,12)));result.put("value",new String(cipher.doFinal(Arrays.copyOfRange(all,12,all.length)),StandardCharsets.UTF_8));}call.resolve(result);
  }catch(Exception e){call.reject("Session vault cannot be read");}
 }
 @PluginMethod public synchronized void remove(PluginCall call){String name=call.getString("key");if(name==null){call.reject("Missing key");return;}if(prefs().edit().remove(name).commit())call.resolve();else call.reject("Vault remove failed");}
 private boolean biometricAvailable(){return BiometricManager.from(getContext()).canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_STRONG)==BiometricManager.BIOMETRIC_SUCCESS;}
 private String biometricAlias(String name) throws Exception {
  return "nasr.mobile.biometric.v1."+Base64.encodeToString(MessageDigest.getInstance("SHA-256").digest(name.getBytes(StandardCharsets.UTF_8)),Base64.NO_WRAP|Base64.URL_SAFE);
 }
 private SecretKey biometricKey(String name,boolean create) throws Exception {
  String alias=biometricAlias(name);KeyStore store=KeyStore.getInstance("AndroidKeyStore");store.load(null);
  if(!store.containsAlias(alias)){
   if(!create)throw new IllegalStateException("Biometric enrollment has changed");
   KeyGenerator generator=KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES,"AndroidKeyStore");
   generator.init(new KeyGenParameterSpec.Builder(alias,KeyProperties.PURPOSE_ENCRYPT|KeyProperties.PURPOSE_DECRYPT)
    .setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
    .setKeySize(256).setUserAuthenticationRequired(true).setUserAuthenticationValidityDurationSeconds(-1)
    .setInvalidatedByBiometricEnrollment(true).build());generator.generateKey();
  }
  return (SecretKey)store.getKey(alias,null);
 }
 private void removeBiometric(String name) throws Exception {
  if(!biometricPrefs().edit().remove(name).commit())throw new IllegalStateException("Cannot remove biometric login");
  KeyStore store=KeyStore.getInstance("AndroidKeyStore");store.load(null);String alias=biometricAlias(name);if(store.containsAlias(alias))store.deleteEntry(alias);
 }
 @PluginMethod public void biometricStatus(PluginCall call){
  String name=call.getString("key");if(name==null){call.reject("Missing key");return;}
  JSObject result=new JSObject();result.put("available",biometricAvailable());result.put("saved",biometricPrefs().contains(name));result.put("type","biometric");call.resolve(result);
 }
 @PluginMethod public void biometricRemove(PluginCall call){
  String name=call.getString("key");if(name==null){call.reject("Missing key");return;}
  getActivity().runOnUiThread(()->{if(biometricBusy){call.reject("Biometric prompt is already open","BIOMETRIC_BUSY");return;}try{removeBiometric(name);call.resolve();}catch(Exception e){call.reject("Cannot remove biometric login");}});
 }
 @PluginMethod public void biometricSet(PluginCall call){biometricAuthenticate(call,true);}
 @PluginMethod public void biometricGet(PluginCall call){biometricAuthenticate(call,false);}
 @Override protected void handleOnDestroy(){if(biometricPrompt!=null)biometricPrompt.cancelAuthentication();super.handleOnDestroy();}
 private void biometricAuthenticate(PluginCall call,boolean save){
  String name=call.getString("key"),value=call.getString("value");
  if(name==null||name.length()>2048||(save&&(value==null||value.length()>16384))){call.reject("Invalid biometric login");return;}
  getActivity().runOnUiThread(()->{
   if(biometricBusy){call.reject("Biometric prompt is already open","BIOMETRIC_BUSY");return;}
   if(!biometricAvailable()){call.reject("اثر انگشت یا تشخیص چهرهٔ امن در گوشی فعال نیست.","BIOMETRIC_UNAVAILABLE");return;}
   byte[] encrypted;
   try{
    Cipher cipher=Cipher.getInstance("AES/GCM/NoPadding");
    if(save){cipher.init(Cipher.ENCRYPT_MODE,biometricKey(name,true));encrypted=null;}
    else{
     String stored=biometricPrefs().getString(name,null);if(stored==null){call.reject("ابتدا با رمز عبور، ورود سریع را فعال کنید.","BIOMETRIC_MISSING");return;}
     byte[] all=Base64.decode(stored,Base64.NO_WRAP);if(all.length<29)throw new IllegalStateException("Invalid encrypted login");
     encrypted=Arrays.copyOfRange(all,12,all.length);cipher.init(Cipher.DECRYPT_MODE,biometricKey(name,false),new GCMParameterSpec(128,Arrays.copyOfRange(all,0,12)));
    }
    biometricBusy=true;
    biometricPrompt=new BiometricPrompt((FragmentActivity)getActivity(),ContextCompat.getMainExecutor(getContext()),new BiometricPrompt.AuthenticationCallback(){
     @Override public void onAuthenticationError(int code,CharSequence text){
      biometricBusy=false;biometricPrompt=null;
      boolean cancelled=code==BiometricPrompt.ERROR_CANCELED||code==BiometricPrompt.ERROR_USER_CANCELED||code==BiometricPrompt.ERROR_NEGATIVE_BUTTON;
      call.reject(cancelled?"ورود سریع لغو شد.":"احراز هویت انجام نشد؛ با رمز عبور وارد شوید.",cancelled?"BIOMETRIC_CANCELLED":"BIOMETRIC_FAILED");
     }
     @Override public void onAuthenticationSucceeded(BiometricPrompt.AuthenticationResult result){
      biometricBusy=false;biometricPrompt=null;
      try{
       Cipher authenticated=result.getCryptoObject()==null?null:result.getCryptoObject().getCipher();if(authenticated==null)throw new IllegalStateException("Missing authorized cipher");
       if(save){
        byte[] iv=authenticated.getIV(),data=authenticated.doFinal(value.getBytes(StandardCharsets.UTF_8)),all=new byte[iv.length+data.length];
        System.arraycopy(iv,0,all,0,iv.length);System.arraycopy(data,0,all,iv.length,data.length);
        if(!biometricPrefs().edit().putString(name,Base64.encodeToString(all,Base64.NO_WRAP)).commit())throw new IllegalStateException("Cannot save biometric login");call.resolve();
       }else{JSObject output=new JSObject();output.put("value",new String(authenticated.doFinal(encrypted),StandardCharsets.UTF_8));call.resolve(output);}
      }catch(Exception e){call.reject("ورود سریع قابل خواندن نیست؛ با رمز عبور وارد شوید.","BIOMETRIC_FAILED");}
     }
    });
    BiometricPrompt.PromptInfo info=new BiometricPrompt.PromptInfo.Builder().setTitle("آموزشگاه نصر")
     .setSubtitle(save?"فعال‌سازی ورود سریع":"ورود با اثر انگشت یا چهره")
     .setAllowedAuthenticators(BiometricManager.Authenticators.BIOMETRIC_STRONG).setNegativeButtonText("استفاده از رمز عبور").build();
    biometricPrompt.authenticate(info,new BiometricPrompt.CryptoObject(cipher));
   }catch(Exception e){
    biometricBusy=false;biometricPrompt=null;
    try{removeBiometric(name);}catch(Exception ignored){}
    call.reject("تنظیمات اثر انگشت یا چهره تغییر کرده است؛ با رمز عبور وارد شوید و ورود سریع را دوباره فعال کنید.","BIOMETRIC_INVALIDATED");
   }
  });
 }
}
