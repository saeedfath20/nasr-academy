import Foundation
import Capacitor
import Security
import LocalAuthentication

@objc(NasrSecureStorePlugin)
public class NasrSecureStorePlugin: CAPPlugin, CAPBridgedPlugin {
 public let identifier = "NasrSecureStorePlugin"
 public let jsName = "NasrSecureStore"
 public let pluginMethods: [CAPPluginMethod] = [CAPPluginMethod(name: "get", returnType: CAPPluginReturnPromise), CAPPluginMethod(name: "set", returnType: CAPPluginReturnPromise), CAPPluginMethod(name: "remove", returnType: CAPPluginReturnPromise), CAPPluginMethod(name: "biometricStatus", returnType: CAPPluginReturnPromise), CAPPluginMethod(name: "biometricSet", returnType: CAPPluginReturnPromise), CAPPluginMethod(name: "biometricGet", returnType: CAPPluginReturnPromise), CAPPluginMethod(name: "biometricRemove", returnType: CAPPluginReturnPromise)]
 private var biometricBusy = false
 private func query(_ key: String) -> [String: Any] { [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: (Bundle.main.bundleIdentifier ?? "com.nasr.academy") + ".session", kSecAttrAccount as String: key] }
 @objc func set(_ call: CAPPluginCall) {
  guard let key=call.getString("key"),let value=call.getString("value"),let data=value.data(using:.utf8) else { call.reject("Missing key/value");return }
  let q=query(key),update=[kSecValueData as String:data]
  var status=SecItemUpdate(q as CFDictionary,update as CFDictionary)
  if status==errSecItemNotFound {var add=q;add[kSecValueData as String]=data;add[kSecAttrAccessible as String]=kSecAttrAccessibleWhenUnlockedThisDeviceOnly;status=SecItemAdd(add as CFDictionary,nil)}
  if status==errSecSuccess {call.resolve()} else {call.reject("Session vault cannot be written")}
 }
 @objc func get(_ call: CAPPluginCall) {
  guard let key=call.getString("key") else {call.reject("Missing key");return};var q=query(key);q[kSecReturnData as String]=true;q[kSecMatchLimit as String]=kSecMatchLimitOne;var result:CFTypeRef?
  let status=SecItemCopyMatching(q as CFDictionary,&result)
  if status==errSecItemNotFound {call.resolve();return}
  if status==errSecSuccess,let data=result as? Data,let value=String(data:data,encoding:.utf8) {call.resolve(["value":value])} else {call.reject("Session vault cannot be read")}
 }
 @objc func remove(_ call:CAPPluginCall) {guard let key=call.getString("key") else {call.reject("Missing key");return};let status=SecItemDelete(query(key) as CFDictionary);if status==errSecSuccess||status==errSecItemNotFound {call.resolve()} else {call.reject("Session vault cannot be removed")}}
 private func biometricQuery(_ key:String) -> [String:Any] {
  [kSecClass as String:kSecClassGenericPassword,kSecAttrService as String:(Bundle.main.bundleIdentifier ?? "com.nasr.academy") + ".biometric",kSecAttrAccount as String:key]
 }
 private func savedFlag(_ key:String) -> String { "nasr-biometric-login:" + key }
 private func deleteBiometric(_ key:String) -> OSStatus {
  let result=SecItemDelete(biometricQuery(key) as CFDictionary)
  if result==errSecSuccess || result==errSecItemNotFound {UserDefaults.standard.removeObject(forKey:savedFlag(key))}
  return result
 }
 @objc func biometricStatus(_ call:CAPPluginCall) {
  guard let key=call.getString("key") else {call.reject("Missing key");return}
  let context=LAContext();var error:NSError?
  let available=context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics,error:&error)
  call.resolve(["available":available,"saved":UserDefaults.standard.bool(forKey:savedFlag(key)),"type":context.biometryType == .faceID ? "face" : "fingerprint"])
 }
 @objc func biometricRemove(_ call:CAPPluginCall) {
  guard let key=call.getString("key") else {call.reject("Missing key");return}
  DispatchQueue.main.async {
   guard !self.biometricBusy else {call.reject("Biometric prompt is already open","BIOMETRIC_BUSY");return}
   let status=self.deleteBiometric(key)
   if status==errSecSuccess || status==errSecItemNotFound {call.resolve()} else {call.reject("Cannot remove biometric login")}
  }
 }
 @objc func biometricSet(_ call:CAPPluginCall) {
  guard let key=call.getString("key"),let value=call.getString("value"),key.count<=2048,value.count<=16384,let data=value.data(using:.utf8) else {call.reject("Invalid biometric login");return}
  DispatchQueue.main.async {
   guard !self.biometricBusy else {call.reject("Biometric prompt is already open","BIOMETRIC_BUSY");return}
   let context=LAContext();context.localizedFallbackTitle="";context.localizedCancelTitle="استفاده از رمز عبور"
   var error:NSError?
   guard context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics,error:&error) else {call.reject("اثر انگشت یا تشخیص چهره در گوشی فعال نیست.","BIOMETRIC_UNAVAILABLE");return}
   self.biometricBusy=true
   context.evaluatePolicy(.deviceOwnerAuthenticationWithBiometrics,localizedReason:"فعال‌سازی ورود سریع به آموزشگاه نصر") { success,error in
    guard success else {DispatchQueue.main.async {self.biometricBusy=false};call.reject("فعال‌سازی ورود سریع لغو شد.","BIOMETRIC_CANCELLED");return}
    var accessError:Unmanaged<CFError>?
    guard let access=SecAccessControlCreateWithFlags(nil,kSecAttrAccessibleWhenPasscodeSetThisDeviceOnly,.biometryCurrentSet,&accessError) else {DispatchQueue.main.async {self.biometricBusy=false};call.reject("Cannot protect biometric login");return}
    let removed=self.deleteBiometric(key)
    guard removed==errSecSuccess || removed==errSecItemNotFound else {DispatchQueue.main.async {self.biometricBusy=false};call.reject("Cannot replace biometric login");return}
    var q=self.biometricQuery(key);q[kSecValueData as String]=data;q[kSecAttrAccessControl as String]=access
    let status=SecItemAdd(q as CFDictionary,nil)
    if status==errSecSuccess {UserDefaults.standard.set(true,forKey:self.savedFlag(key))}
    DispatchQueue.main.async {self.biometricBusy=false}
    if status==errSecSuccess {call.resolve()} else {call.reject("Cannot save biometric login")}
   }
  }
 }
 @objc func biometricGet(_ call:CAPPluginCall) {
  guard let key=call.getString("key"),key.count<=2048 else {call.reject("Missing key");return}
  DispatchQueue.main.async {
   guard !self.biometricBusy else {call.reject("Biometric prompt is already open","BIOMETRIC_BUSY");return}
   let context=LAContext();context.localizedFallbackTitle="";context.localizedCancelTitle="استفاده از رمز عبور"
   var error:NSError?
   guard context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics,error:&error) else {call.reject("احراز هویت در گوشی فعال نیست؛ با رمز عبور وارد شوید.","BIOMETRIC_UNAVAILABLE");return}
   self.biometricBusy=true
   DispatchQueue.global(qos:.userInitiated).async {
    var q=self.biometricQuery(key);q[kSecReturnData as String]=true;q[kSecMatchLimit as String]=kSecMatchLimitOne
    q[kSecUseAuthenticationContext as String]=context;q[kSecUseOperationPrompt as String]="ورود به آموزشگاه نصر"
    var result:CFTypeRef?;let status=SecItemCopyMatching(q as CFDictionary,&result)
    DispatchQueue.main.async {self.biometricBusy=false}
    if status==errSecSuccess,let data=result as? Data,let value=String(data:data,encoding:.utf8) {call.resolve(["value":value])}
    else if status==errSecUserCanceled {call.reject("ورود سریع لغو شد.","BIOMETRIC_CANCELLED")}
    else if status==errSecItemNotFound {UserDefaults.standard.removeObject(forKey:self.savedFlag(key));call.reject("ابتدا با رمز عبور وارد شوید و ورود سریع را دوباره فعال کنید.","BIOMETRIC_INVALIDATED")}
    else {call.reject("احراز هویت انجام نشد؛ با رمز عبور وارد شوید.","BIOMETRIC_FAILED")}
   }
  }
 }
}
