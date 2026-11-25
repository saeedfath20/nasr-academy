export interface NasrSecureStorePlugin {
 get(options:{key:string}):Promise<{value?:string}>;
 set(options:{key:string,value:string}):Promise<void>;
 remove(options:{key:string}):Promise<void>;
 biometricStatus(options:{key:string}):Promise<{available:boolean;saved:boolean;type:'face'|'fingerprint'|'biometric'}>;
 biometricSet(options:{key:string,value:string}):Promise<void>;
 biometricGet(options:{key:string}):Promise<{value:string}>;
 biometricRemove(options:{key:string}):Promise<void>;
}
export declare const NasrSecureStore:NasrSecureStorePlugin;
